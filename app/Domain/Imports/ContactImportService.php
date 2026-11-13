<?php

namespace App\Domain\Imports;

use App\Domain\Tenancy\TenantContext;
use App\Enums\ContactRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DataImport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type ContactImportError array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, email_masked: string|null, phone_masked: string|null}>, can_create?: bool, lines?: list<int>}
 */
class ContactImportService
{
    /**
     * @param  list<list<string>>  $rows
     * @param  list<int>  $malformedRows
     * @return array{
     *     totalRows: int,
     *     validRows: list<array{line: int, identity: string, attributes: array<string, mixed>, company_id: int, roles: list<string>, is_primary: bool, relationship_notes: string|null, resolution?: array{action: string, target_id?: int}}>,
     *     invalidRows: int,
     *     duplicateRows: int,
     *     errors: list<ContactImportError>
     * }
     */
    public function analyze(DataImport $batch, User $actor, array $rows, int $totalRows, bool $lockDestination = false, array $malformedRows = []): array
    {
        $this->assertDestinationStillAuthorized($batch, $actor, $lockDestination);
        $fields = app(ContactImportFields::class)->options();
        $mapping = is_array($batch->mapping) ? $batch->mapping : [];
        $invalidRows = 0;
        $errors = [];
        $candidates = [];
        $preparedRows = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            if (in_array($line, $malformedRows, true)) {
                $invalidRows++;
                $this->appendError($errors, $line, 'invalid', 'A linha contém mais colunas do que o cabeçalho.');

                continue;
            }

            $attributes = $this->mappedAttributes($mapping, $fields, $row);
            $attributes['name'] = trim((string) ($attributes['name'] ?? ''));
            $attributes['email'] = isset($attributes['email']) ? mb_strtolower(trim((string) $attributes['email'])) : null;
            $attributes['company_tax_id'] = isset($attributes['company_tax_id'])
                ? (preg_replace('/\D+/', '', (string) $attributes['company_tax_id']) ?? '')
                : null;
            $preparedRows[] = ['line' => $line, 'attributes' => $attributes];
        }

        $companyCatalog = $this->companyCatalog($batch, $actor, $preparedRows, $lockDestination);

        foreach ($preparedRows as $preparedRow) {
            $line = $preparedRow['line'];
            $attributes = $preparedRow['attributes'];
            $rolesResult = $this->parseRoles((string) ($attributes['company_roles'] ?? ''));
            $primaryResult = $this->parseBoolean($attributes['is_primary'] ?? null);
            $company = $this->resolveCompany($companyCatalog, $attributes);

            $messages = Validator::make($attributes, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'whatsapp' => ['nullable', 'string', 'max:40'],
                'job_title' => ['nullable', 'string', 'max:120'],
                'professional_interests' => ['nullable', 'string', 'max:5000'],
                'notes' => ['nullable', 'string', 'max:10000'],
                'company_tax_id' => ['nullable', 'regex:/^\d{14}$/'],
                'company_name' => ['nullable', 'string', 'max:180'],
                'company_roles' => ['nullable', 'string', 'max:500'],
                'is_primary' => ['nullable', 'string', 'max:10'],
                'relationship_notes' => ['nullable', 'string', 'max:10000'],
            ])->errors()->all();

            if (! $primaryResult['valid']) {
                $messages[] = 'Contato principal deve ser sim ou não.';
            }

            if ($rolesResult === null) {
                $messages[] = 'Papéis na empresa inválidos. Use os códigos separados por ponto e vírgula.';
            }

            if ($company === null) {
                $messages[] = 'A empresa informada não foi encontrada ou não está acessível nesta organização.';
            }

            if ($messages !== []) {
                $invalidRows++;
                $this->appendError($errors, $line, 'invalid', implode(' ', array_unique($messages)));

                continue;
            }

            $contactAttributes = array_filter([
                'name' => $attributes['name'],
                'email' => $attributes['email'] ?: null,
                'phone' => $attributes['phone'] ?? null,
                'whatsapp' => $attributes['whatsapp'] ?? null,
                'job_title' => $attributes['job_title'] ?? null,
                'professional_interests' => $attributes['professional_interests'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => 'active',
            ], static fn (mixed $value): bool => $value !== null);

            $candidates[] = [
                'line' => $line,
                'identity' => 'line:'.$line,
                'attributes' => $contactAttributes,
                'company_id' => $company->id,
                'roles' => $rolesResult ?? ['other'],
                'is_primary' => $primaryResult['value'] ?? false,
                'relationship_notes' => $attributes['relationship_notes'] ?? null,
            ];
        }

        $candidates = $this->assignIdentities($candidates);
        $existingIdentities = $this->existingIdentities($batch, $actor, $candidates);
        $grouped = [];

        foreach ($candidates as $candidate) {
            $grouped[$candidate['identity']][] = $candidate;
        }

        $validRows = [];
        $duplicateRows = 0;

        foreach ($grouped as $identity => $personRows) {
            $matchingContacts = $existingIdentities['visible'][$identity] ?? [];
            $resolution = $this->requestedResolution($batch, $personRows[0]['line']);
            $resolutionAction = $resolution['action'] ?? null;

            if ($resolutionAction === 'ignore') {
                $duplicateRows += count($personRows);

                continue;
            }

            if ($matchingContacts !== [] && ! in_array($resolutionAction, ['create', 'update', 'resolve'], true)) {
                $duplicateRows += count($personRows);
                $this->appendError($errors, $personRows[0]['line'], 'duplicate', 'O contato já existe nesta organização. Escolha criar, atualizar, resolver manualmente ou ignorar as linhas.', [
                    'candidates' => array_map(fn (array $contact): array => [
                        'id' => $contact['id'],
                        'name' => $contact['name'],
                        'email_masked' => $this->maskEmail($contact['email']),
                        'phone_masked' => $this->maskPhone($contact['phone']),
                    ], $matchingContacts),
                    'can_create' => true,
                    'lines' => array_column($personRows, 'line'),
                ]);

                continue;
            }

            if ($matchingContacts === [] && in_array($resolutionAction, ['update', 'resolve'], true)) {
                $invalidRows += count($personRows);
                $this->appendError($errors, $personRows[0]['line'], 'invalid', 'O contato escolhido deixou de corresponder aos dados ou não está mais acessível. Revise a linha antes de continuar.', [
                    'lines' => array_column($personRows, 'line'),
                ]);

                continue;
            }

            $targetId = null;
            $requestedTargetId = $resolution['target_id'] ?? null;
            if ($resolutionAction === 'update'
                && count($matchingContacts) === 1
                && $requestedTargetId === $matchingContacts[0]['id']) {
                $targetId = $matchingContacts[0]['id'];
            } elseif ($resolutionAction === 'resolve') {
                if (is_int($requestedTargetId) && in_array($requestedTargetId, array_column($matchingContacts, 'id'), true)) {
                    $targetId = $requestedTargetId;
                }
            }

            if ($matchingContacts !== []
                && in_array($resolutionAction, ['update', 'resolve'], true)
                && $targetId === null) {
                $duplicateRows += count($personRows);
                $this->appendError($errors, $personRows[0]['line'], 'duplicate', 'A resolução precisa apontar para um único contato correspondente e visível.', [
                    'candidates' => array_map(fn (array $contact): array => [
                        'id' => $contact['id'],
                        'name' => $contact['name'],
                        'email_masked' => $this->maskEmail($contact['email']),
                        'phone_masked' => $this->maskPhone($contact['phone']),
                    ], $matchingContacts),
                    'can_create' => true,
                    'lines' => array_column($personRows, 'line'),
                ]);

                continue;
            }

            $mergedAttributes = $this->mergePersonAttributes($personRows);

            if ($mergedAttributes === null) {
                $invalidRows += count($personRows);

                foreach ($personRows as $candidate) {
                    $this->appendError($errors, $candidate['line'], 'ambiguous', 'As linhas da mesma pessoa contêm dados divergentes; revise-as antes de importar.');
                }

                continue;
            }

            foreach ($personRows as &$personRow) {
                $personRow['attributes'] = $mergedAttributes;
                if ($resolutionAction === 'create') {
                    $personRow['resolution'] = ['action' => 'create'];
                } elseif ($targetId !== null) {
                    $personRow['resolution'] = [
                        'action' => $resolutionAction,
                        'target_id' => $targetId,
                    ];
                }
            }
            unset($personRow);

            $seenCompanies = [];
            foreach ($personRows as $candidate) {
                $companyId = $candidate['company_id'];
                $relationshipSignature = json_encode([
                    $candidate['roles'],
                    $candidate['is_primary'],
                    $candidate['relationship_notes'],
                ], JSON_THROW_ON_ERROR);

                if (isset($seenCompanies[$companyId])) {
                    if ($seenCompanies[$companyId] !== $relationshipSignature) {
                        $invalidRows++;
                        $this->appendError($errors, $candidate['line'], 'ambiguous', 'A pessoa aparece mais de uma vez para a mesma empresa com dados de vínculo diferentes.');
                    } else {
                        $duplicateRows++;
                    }

                    continue;
                }

                $seenCompanies[$companyId] = $relationshipSignature;
                $validRows[] = $candidate;
            }
        }

        $primaryCompanyLines = [];
        foreach ($validRows as $candidate) {
            if ($candidate['is_primary']) {
                $primaryCompanyLines[$candidate['company_id']][] = $candidate['line'];
            }
        }

        $conflictingPrimaryLines = array_merge(...array_values(array_filter(
            $primaryCompanyLines,
            static fn (array $lines): bool => count($lines) > 1,
        ))) ?: [];

        if ($conflictingPrimaryLines !== []) {
            $validRows = array_values(array_filter($validRows, static function (array $candidate) use ($conflictingPrimaryLines): bool {
                return ! in_array($candidate['line'], $conflictingPrimaryLines, true);
            }));
            $invalidRows += count($conflictingPrimaryLines);

            foreach ($conflictingPrimaryLines as $line) {
                $this->appendError($errors, $line, 'ambiguous', 'Mais de um contato foi marcado como principal para a mesma empresa.');
            }
        }

        return [
            'totalRows' => $totalRows,
            'validRows' => $validRows,
            'invalidRows' => $invalidRows,
            'duplicateRows' => $duplicateRows,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array{line: int, identity: string, attributes: array<string, mixed>, company_id: int, roles: list<string>, is_primary: bool, relationship_notes: string|null, resolution?: array{action: string, target_id?: int}}>  $validRows
     * @return array{createdRows: int, updatedRows: int, duplicateRows: int, invalidRows: int, errors: list<ContactImportError>}
     */
    public function import(DataImport $batch, User $actor, array $validRows): array
    {
        $this->assertDestinationStillAuthorized($batch, $actor, true);
        $companyIds = array_values(array_unique(array_column($validRows, 'company_id')));
        $authorizedCompanies = Company::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->whereIn('id', $companyIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'team_id']);

        if ($authorizedCompanies->count() !== count($companyIds)) {
            throw ValidationException::withMessages(['file' => 'Uma empresa deixou de estar acessível durante a importação. Nenhum registro foi confirmado.']);
        }

        $this->lockManagerCompanyScopes($batch, $actor, array_values($authorizedCompanies->all()), true);
        $groups = [];

        foreach ($validRows as $row) {
            $groups[$row['identity']][] = $row;
        }

        $this->lockContactIdentityKeys($batch, array_values($groups));

        $createdRows = 0;
        $updatedRows = 0;
        $duplicateRows = 0;
        $invalidRows = 0;
        $errors = [];

        foreach ($groups as $identity => $personRows) {
            $attributes = $personRows[0]['attributes'];
            $resolution = $personRows[0]['resolution'] ?? null;
            $matchingContacts = $this->matchingContacts($batch, $actor, $attributes, false);
            $matchingIds = $matchingContacts->modelKeys();
            if ($matchingIds !== []) {
                $this->lockVisibleContactCompanyScopes($batch, $actor, array_values(array_map('intval', $matchingIds)));
                $matchingContacts = $this->matchingContacts($batch, $actor, $attributes, true);
                $matchingIds = $matchingContacts->modelKeys();
            }

            $action = $resolution['action'] ?? null;
            $targetId = $resolution['target_id'] ?? null;
            $contact = null;

            if (in_array($action, ['update', 'resolve'], true)) {
                $hasSingleTarget = $action === 'resolve' || count($matchingIds) === 1;
                if (! is_int($targetId) || ! $hasSingleTarget || ! in_array($targetId, $matchingIds, true)) {
                    $invalidRows += count($personRows);
                    $this->appendError($errors, $personRows[0]['line'], 'invalid', 'O contato escolhido deixou de corresponder aos dados ou não está mais acessível. Revise a linha antes de continuar.');

                    continue;
                }

                $this->lockVisibleContactCompanyScopes($batch, $actor, [$targetId]);
                $contact = Contact::query()
                    ->where('organization_id', $batch->organization_id)
                    ->visibleTo($actor)
                    ->whereKey($targetId)
                    ->lockForUpdate()
                    ->first();

                if ($contact === null) {
                    $invalidRows += count($personRows);
                    $this->appendError($errors, $personRows[0]['line'], 'invalid', 'O contato escolhido deixou de estar acessível. Revise a linha antes de continuar.');

                    continue;
                }

                unset($attributes['status']);
                $contact->fill(array_filter($attributes, static fn (mixed $value): bool => $value !== null));
                $contact->save();
                $updatedRows++;
            } elseif ($action === 'create') {
                $contact = Contact::query()->create($attributes);
                $createdRows++;
            } elseif ($matchingIds !== []) {
                $duplicateRows += count($personRows);
                $this->appendError($errors, $personRows[0]['line'], 'duplicate', 'O contato passou a existir durante a importação; as linhas foram ignoradas com segurança.', [
                    'candidates' => array_values(array_map(fn (Contact $matchingContact): array => [
                        'id' => (int) $matchingContact->id,
                        'name' => $matchingContact->name,
                        'email_masked' => $this->maskEmail($matchingContact->email),
                        'phone_masked' => $this->maskPhone($matchingContact->phone),
                    ], $matchingContacts->all())),
                    'can_create' => true,
                    'lines' => array_column($personRows, 'line'),
                ]);

                continue;
            } else {
                $contact = Contact::query()->create($attributes);
                $createdRows++;
            }

            $attachedCompanyIds = [];

            foreach ($personRows as $row) {
                if (isset($attachedCompanyIds[$row['company_id']])) {
                    continue;
                }

                $company = Company::query()
                    ->where('organization_id', $batch->organization_id)
                    ->visibleTo($actor)
                    ->whereKey($row['company_id'])
                    ->lockForUpdate()
                    ->first();

                if ($company === null) {
                    throw ValidationException::withMessages(['file' => 'Uma empresa deixou de estar acessível durante a importação. Nenhum registro foi confirmado.']);
                }

                $alreadyLinked = DB::table('company_contacts')
                    ->where('organization_id', $batch->organization_id)
                    ->where('company_id', $company->id)
                    ->where('contact_id', $contact->id)
                    ->exists();

                if ($alreadyLinked) {
                    $attachedCompanyIds[$company->id] = true;

                    continue;
                }

                if ($row['is_primary']) {
                    DB::table('company_contacts')
                        ->where('organization_id', $batch->organization_id)
                        ->where('company_id', $company->id)
                        ->update(['is_primary' => false]);
                }

                $company->contacts()->attach($contact->id, [
                    'organization_id' => $batch->organization_id,
                    'roles' => $row['roles'],
                    'is_primary' => $row['is_primary'],
                    'notes' => $row['relationship_notes'],
                ]);
                $attachedCompanyIds[$company->id] = true;
            }
        }

        return ['createdRows' => $createdRows, 'updatedRows' => $updatedRows, 'duplicateRows' => $duplicateRows, 'invalidRows' => $invalidRows, 'errors' => $errors];
    }

    private function assertDestinationStillAuthorized(DataImport $batch, User $actor, bool $lockDestination): void
    {
        if (app(TenantContext::class)->id() !== (int) $batch->organization_id) {
            throw new AuthorizationException;
        }

        $role = $actor->roleIn($batch->organization_id);

        if (! in_array($role, ['owner', 'admin', 'manager', 'salesperson'], true)) {
            throw new AuthorizationException;
        }

        if ($role === 'manager' && $batch->team_id === null) {
            throw ValidationException::withMessages(['team_id' => 'Selecione uma equipe ativa que você administra.']);
        }

        if ($batch->team_id === null) {
            return;
        }

        $team = Team::query()
            ->where('organization_id', $batch->organization_id)
            ->whereKey($batch->team_id)
            ->when($lockDestination, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($team === null || $team->status !== 'active') {
            throw ValidationException::withMessages(['team_id' => 'A equipe de destino não está mais ativa nesta organização.']);
        }

        $requiredRole = match ($role) {
            'manager' => 'manager',
            'salesperson' => 'salesperson',
            default => null,
        };

        if ($requiredRole !== null && ! DB::table('team_memberships')
            ->where('organization_id', $batch->organization_id)
            ->where('team_id', $batch->team_id)
            ->where('user_id', $actor->id)
            ->where('role', $requiredRole)
            ->where('is_active', true)
            ->when($lockDestination, fn ($query) => $query->lockForUpdate())
            ->exists()) {
            throw ValidationException::withMessages(['team_id' => 'Você não tem mais acesso à equipe de destino selecionada.']);
        }
    }

    /**
     * @param  list<array{line: int, attributes: array<string, string|null>}>  $rows
     * @return array{tax: array<string, list<Company>>, names: array<string, list<Company>>}
     */
    private function companyCatalog(DataImport $batch, User $actor, array $rows, bool $lock): array
    {
        $taxVariants = [];
        $nameKeys = [];

        foreach ($rows as $row) {
            $taxId = (string) ($row['attributes']['company_tax_id'] ?? '');
            if ($taxId !== '') {
                $taxVariants[] = $taxId;
                if (preg_match('/^\d{14}$/', $taxId) === 1) {
                    $taxVariants[] = preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $taxId) ?? $taxId;
                }
            }

            $name = trim((string) ($row['attributes']['company_name'] ?? ''));
            if ($name !== '') {
                $nameKeys[] = mb_strtolower($this->normalize($name));
            }
        }

        $companiesById = [];
        foreach (array_chunk(array_values(array_unique($taxVariants)), 200) as $taxChunk) {
            $companies = Company::query()
                ->where('organization_id', $batch->organization_id)
                ->visibleTo($actor)
                ->whereIn('tax_id', $taxChunk)
                ->when($lock, fn ($companies) => $companies->lockForUpdate())
                ->orderBy('id')
                ->get(['id', 'tax_id', 'name', 'trade_name', 'team_id']);

            foreach ($companies as $company) {
                $companiesById[$company->id] = $company;
            }
        }

        foreach (array_chunk(array_values(array_unique($nameKeys)), 200) as $nameChunk) {
            $companies = Company::query()
                ->where('organization_id', $batch->organization_id)
                ->visibleTo($actor)
                ->where(function ($companies) use ($nameChunk): void {
                    $companies->whereIn(DB::raw('LOWER(name)'), $nameChunk)
                        ->orWhereIn(DB::raw('LOWER(trade_name)'), $nameChunk);
                })
                ->when($lock, fn ($companies) => $companies->lockForUpdate())
                ->orderBy('id')
                ->get(['id', 'tax_id', 'name', 'trade_name', 'team_id']);

            foreach ($companies as $company) {
                $companiesById[$company->id] = $company;
            }
        }

        $this->lockManagerCompanyScopes($batch, $actor, array_values($companiesById), $lock);
        $catalog = ['tax' => [], 'names' => []];
        foreach ($companiesById as $company) {
            $digits = $this->digits((string) $company->tax_id);
            if ($digits !== '') {
                $catalog['tax'][$digits][$company->id] = $company;
            }

            foreach ([$company->name, $company->trade_name] as $companyName) {
                if ($companyName === null || trim($companyName) === '') {
                    continue;
                }
                $key = mb_strtolower($this->normalize($companyName));
                $catalog['names'][$key][$company->id] = $company;
            }
        }

        foreach ($catalog['names'] as $key => $companies) {
            $catalog['names'][$key] = array_values($companies);
        }
        foreach ($catalog['tax'] as $key => $companies) {
            $catalog['tax'][$key] = array_values($companies);
        }

        return $catalog;
    }

    /**
     * @param  list<Company>  $companies
     */
    private function lockManagerCompanyScopes(DataImport $batch, User $actor, array $companies, bool $lock): void
    {
        if (! $lock || $actor->roleIn($batch->organization_id) !== 'manager') {
            return;
        }

        $teamIds = array_values(array_unique(array_filter(array_map(
            static fn (Company $company): ?int => $company->team_id === null ? null : (int) $company->team_id,
            $companies,
        ))));
        sort($teamIds);

        if ($teamIds === []) {
            return;
        }

        $teams = Team::query()
            ->where('organization_id', $batch->organization_id)
            ->whereIn('id', $teamIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'status'])
            ->keyBy('id');
        $activeMembershipTeamIds = DB::table('team_memberships')
            ->where('organization_id', $batch->organization_id)
            ->where('user_id', $actor->id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->whereIn('team_id', $teamIds)
            ->orderBy('team_id')
            ->lockForUpdate()
            ->pluck('team_id')
            ->map(static fn (int|string $teamId): int => (int) $teamId)
            ->all();

        foreach ($teamIds as $teamId) {
            if ($teams->get($teamId)?->status !== 'active' || ! in_array($teamId, $activeMembershipTeamIds, true)) {
                throw ValidationException::withMessages(['team_id' => 'Seu acesso a uma equipe das empresas selecionadas foi alterado. Revise o lote antes de tentar novamente.']);
            }
        }
    }

    /**
     * @param  array{tax: array<string, list<Company>>, names: array<string, list<Company>>}  $catalog
     * @param  array<string, mixed>  $attributes
     */
    private function resolveCompany(array $catalog, array $attributes): ?Company
    {
        $taxId = (string) ($attributes['company_tax_id'] ?? '');
        $name = trim((string) ($attributes['company_name'] ?? ''));
        $taxMatches = $taxId !== '' ? ($catalog['tax'][$taxId] ?? []) : [];
        $nameMatches = $name !== '' ? ($catalog['names'][mb_strtolower($this->normalize($name))] ?? []) : [];

        if ($taxId !== '' && count($taxMatches) !== 1) {
            return null;
        }

        if ($name !== '' && count($nameMatches) !== 1) {
            return null;
        }

        $byTaxId = $taxMatches[0] ?? null;
        $byName = $nameMatches[0] ?? null;
        if ($byTaxId !== null && $byName !== null && $byName->id !== $byTaxId->id) {
            return null;
        }

        return $byName ?? $byTaxId;
    }

    /**
     * @param  list<array{identity: string, attributes: array<string, mixed>}>  $candidates
     * @return array{visible: array<string, list<array{id: int, name: string, email: string|null, phone: string|null}>>}
     */
    private function existingIdentities(DataImport $batch, User $actor, array $candidates): array
    {
        $emails = [];
        $names = [];

        foreach ($candidates as $candidate) {
            $attributes = $candidate['attributes'];
            if (! empty($attributes['email'])) {
                $emails[] = mb_strtolower((string) $attributes['email']);
            }
            if (! empty($attributes['name'])) {
                $names[] = mb_strtolower($this->normalize((string) $attributes['name']));
            }
        }

        if ($emails === [] && $names === []) {
            return ['visible' => [], 'hidden' => []];
        }

        $existingContacts = Contact::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->where(function ($contacts) use ($emails, $names): void {
                if ($emails !== []) {
                    $contacts->whereIn(DB::raw('LOWER(email)'), array_unique($emails));
                }
                if ($names !== []) {
                    $method = $emails === [] ? 'whereIn' : 'orWhereIn';
                    $contacts->{$method}(DB::raw('LOWER(name)'), array_unique($names));
                }
            })
            ->get(['id', 'name', 'email', 'phone']);

        $identities = ['visible' => []];

        foreach ($candidates as $candidate) {
            foreach ($existingContacts as $contact) {
                if ($this->contactMatchesIdentity($candidate['attributes'], $contact)) {
                    $identities['visible'][$candidate['identity']][(int) $contact->id] = [
                        'id' => (int) $contact->id,
                        'name' => $contact->name,
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                    ];
                }
            }
        }

        foreach ($identities['visible'] as $identity => $contacts) {
            ksort($contacts);
            $identities['visible'][$identity] = array_values($contacts);
        }

        return $identities;
    }

    /** @param array<string, mixed> $attributes */
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Contact>
     */
    private function matchingContacts(DataImport $batch, User $actor, array $attributes, bool $lock): Collection
    {
        $email = mb_strtolower((string) ($attributes['email'] ?? ''));
        $name = mb_strtolower($this->normalize((string) ($attributes['name'] ?? '')));
        $phone = $this->digits((string) ($attributes['phone'] ?? ''));

        if ($email === '' && ($name === '' || $phone === '')) {
            return Contact::query()->whereRaw('1 = 0')->get();
        }

        return Contact::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->where(function ($contacts) use ($email, $name): void {
                if ($email !== '') {
                    $contacts->whereRaw('LOWER(email) = ?', [$email]);
                }
                if ($name !== '') {
                    $method = $email === '' ? 'whereRaw' : 'orWhereRaw';
                    $contacts->{$method}('LOWER(name) = ?', [$name]);
                }
            })
            ->when($lock, fn ($contacts) => $contacts->orderBy('id')->lockForUpdate())
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'phone'])
            ->filter(fn (Contact $contact): bool => $this->contactMatchesIdentity($attributes, $contact))
            ->values();
    }

    /** @param array<string, mixed> $attributes */
    private function contactMatchesIdentity(array $attributes, Contact $contact): bool
    {
        $email = mb_strtolower((string) ($attributes['email'] ?? ''));
        $emailMatches = $email !== '' && $email === mb_strtolower((string) $contact->email);
        $phone = $this->digits((string) ($attributes['phone'] ?? ''));
        $phoneMatches = $phone !== '' && $phone === $this->digits((string) $contact->phone);
        $nameMatches = mb_strtolower($this->normalize((string) ($attributes['name'] ?? '')))
            === mb_strtolower($this->normalize($contact->name));

        return $emailMatches || ($nameMatches && $phoneMatches);
    }

    /**
     * @param  list<list<array{line: int, attributes: array<string, mixed> }>>  $groups
     */
    private function lockContactIdentityKeys(DataImport $batch, array $groups): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $lockIds = [];

        foreach ($groups as $personRows) {
            foreach ($personRows as $personRow) {
                foreach ($this->identityKeys($personRow['attributes'], $personRow['line']) as $identityKey) {
                    if (str_starts_with($identityKey, 'line:')) {
                        continue;
                    }

                    $lockIds[] = (int) hexdec(substr(hash('sha256', 'contact-import:'.$batch->organization_id.':'.$identityKey), 0, 15));
                }
            }
        }

        $lockIds = array_values(array_unique($lockIds));
        sort($lockIds, SORT_NUMERIC);

        foreach ($lockIds as $lockId) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [$lockId]);
        }
    }

    /**
     * @param  list<int>  $contactIds
     */
    private function lockVisibleContactCompanyScopes(DataImport $batch, User $actor, array $contactIds): void
    {
        if ($contactIds === []) {
            return;
        }

        $companies = Company::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->whereHas('contacts', fn ($contacts) => $contacts->whereIn('contacts.id', $contactIds))
            ->orderBy('companies.id')
            ->lockForUpdate()
            ->get(['companies.id', 'companies.team_id']);

        $this->lockManagerCompanyScopes($batch, $actor, array_values($companies->all()), true);
    }

    /** @return array{action: string, target_id?: int}|null */
    private function requestedResolution(DataImport $batch, int $line): ?array
    {
        $resolutions = $batch->duplicate_resolutions ?? [];
        $resolution = $resolutions[$line] ?? $resolutions[(string) $line] ?? null;

        if ($resolution === null) {
            return null;
        }

        $targetId = $resolution['target_id'] ?? null;

        return [
            'action' => $resolution['action'],
            ...(is_int($targetId) ? ['target_id' => $targetId] : []),
        ];
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '') {
            return null;
        }

        return mb_substr($local, 0, 1).'***'.(mb_strlen($local) > 1 ? mb_substr($local, -1) : '').'@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        $digits = $this->digits((string) $phone);

        return strlen($digits) < 4 ? null : '***'.substr($digits, -4);
    }

    /**
     * @param  array<string, int>  $mapping
     * @param  array<string, array{label: string, required: bool}>  $fields
     * @param  list<string>  $row
     * @return array<string, string|null>
     */
    private function mappedAttributes(array $mapping, array $fields, array $row): array
    {
        $attributes = [];
        foreach ($mapping as $field => $columnIndex) {
            if (array_key_exists($field, $fields)) {
                $value = trim($row[$columnIndex] ?? '');
                $attributes[$field] = $value === '' ? null : $value;
            }
        }

        return $attributes;
    }

    /** @return list<string>|null */
    private function parseRoles(string $value): ?array
    {
        $roles = array_values(array_unique(array_filter(array_map('trim', preg_split('/[;,|]+/', $value) ?: []))));
        if ($roles === []) {
            return ['other'];
        }

        if (count($roles) > 5) {
            return null;
        }

        foreach ($roles as $role) {
            if (ContactRole::tryFrom($role) === null) {
                return null;
            }
        }

        return $roles;
    }

    /** @return array{valid: bool, value: bool|null} */
    private function parseBoolean(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['valid' => true, 'value' => false];
        }

        return match (mb_strtolower(trim($value))) {
            'sim', 's', 'yes', 'y', 'true', '1' => ['valid' => true, 'value' => true],
            'não', 'nao', 'n', 'no', 'false', '0' => ['valid' => true, 'value' => false],
            default => ['valid' => false, 'value' => null],
        };
    }

    /**
     * @param  list<array{line: int, identity: string, attributes: array<string, mixed>, company_id: int, roles: list<string>, is_primary: bool, relationship_notes: string|null}>  $candidates
     * @return list<array{line: int, identity: string, attributes: array<string, mixed>, company_id: int, roles: list<string>, is_primary: bool, relationship_notes: string|null}>
     */
    private function assignIdentities(array $candidates): array
    {
        $parents = array_keys($candidates);
        $keyOwners = [];

        foreach ($candidates as $index => $candidate) {
            foreach ($this->identityKeys($candidate['attributes'], $candidate['line']) as $key) {
                if (isset($keyOwners[$key])) {
                    $this->union($parents, $index, $keyOwners[$key]);
                } else {
                    $keyOwners[$key] = $index;
                }
            }
        }

        $groupLines = [];
        foreach ($candidates as $index => $candidate) {
            $root = $this->findRoot($parents, $index);
            $groupLines[$root] = min($groupLines[$root] ?? $candidate['line'], $candidate['line']);
        }

        foreach ($candidates as $index => &$candidate) {
            $root = $this->findRoot($parents, $index);
            $candidate['identity'] = 'group:'.$groupLines[$root];
        }
        unset($candidate);

        return $candidates;
    }

    /** @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function identityKeys(array $attributes, int $line): array
    {
        $keys = [];
        $email = mb_strtolower(trim((string) ($attributes['email'] ?? '')));
        if ($email !== '') {
            $keys[] = 'email:'.$email;
        }

        $phone = $this->digits((string) ($attributes['phone'] ?? ''));
        if ($phone !== '') {
            $keys[] = 'name-phone:'.mb_strtolower($this->normalize((string) $attributes['name'])).'|'.$phone;
        }

        if ($keys === []) {
            $keys[] = 'line:'.$line;
        }

        return $keys;
    }

    /**
     * @param  list<array{attributes: array<string, mixed>}>  $personRows
     * @return array<string, mixed>|null
     */
    private function mergePersonAttributes(array $personRows): ?array
    {
        $attributes = ['status' => 'active'];
        $canonicalValues = [];

        foreach ($personRows as $row) {
            foreach ($row['attributes'] as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $canonical = match ($field) {
                    'email' => mb_strtolower(trim((string) $value)),
                    'phone', 'whatsapp' => $this->digits((string) $value),
                    default => mb_strtolower($this->normalize((string) $value)),
                };

                if (isset($canonicalValues[$field]) && $canonicalValues[$field] !== $canonical) {
                    return null;
                }

                $canonicalValues[$field] = $canonical;
                $attributes[$field] ??= $field === 'email' ? mb_strtolower(trim((string) $value)) : $value;
            }
        }

        return $attributes;
    }

    /** @param array<int, int> $parents */
    private function findRoot(array &$parents, int $index): int
    {
        if ($parents[$index] !== $index) {
            $parents[$index] = $this->findRoot($parents, $parents[$index]);
        }

        return $parents[$index];
    }

    /** @param array<int, int> $parents */
    private function union(array &$parents, int $left, int $right): void
    {
        $leftRoot = $this->findRoot($parents, $left);
        $rightRoot = $this->findRoot($parents, $right);

        if ($leftRoot !== $rightRoot) {
            $parents[max($leftRoot, $rightRoot)] = min($leftRoot, $rightRoot);
        }
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** @param list<ContactImportError> $errors
     * @param  array{candidates?: list<array{id: int, name: string, email_masked: string|null, phone_masked: string|null}>, can_create?: bool, lines?: list<int>}  $context
     */
    private function appendError(array &$errors, int $line, string $type, string $message, array $context = []): void
    {
        if (count($errors) < DataImport::MAX_VALIDATION_ERRORS) {
            $errors[] = ['line' => $line, 'type' => $type, 'message' => $message, ...$context];
        }
    }
}
