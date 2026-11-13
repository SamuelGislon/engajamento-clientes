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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContactImportService
{
    /**
     * @param  list<list<string>>  $rows
     * @param  list<int>  $malformedRows
     * @return array{
     *     totalRows: int,
     *     validRows: list<array{line: int, identity: string, attributes: array<string, mixed>, company_id: int, roles: list<string>, is_primary: bool, relationship_notes: string|null}>,
     *     invalidRows: int,
     *     duplicateRows: int,
     *     errors: list<array{line: int, type: string, message: string}>
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
            if (isset($existingIdentities['hidden'][$identity])) {
                foreach ($personRows as $candidate) {
                    $invalidRows++;
                    $this->appendError($errors, $candidate['line'], 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');
                }

                continue;
            }

            if (isset($existingIdentities['visible'][$identity])) {
                foreach ($personRows as $candidate) {
                    $duplicateRows++;
                    $this->appendError($errors, $candidate['line'], 'duplicate', 'O contato já existe nesta organização; a linha será ignorada.');
                }

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
                        $this->appendError($errors, $candidate['line'], 'duplicate', 'Vínculo repetido no arquivo; a linha será ignorada.');
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
     * @param  list<array{line: int, identity: string, attributes: array<string, mixed>, company_id: int, roles: list<string>, is_primary: bool, relationship_notes: string|null}>  $validRows
     * @return array{createdRows: int, duplicateRows: int, invalidRows: int, errors: list<array{line: int, type: string, message: string}>}
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

        $createdRows = 0;
        $duplicateRows = 0;
        $invalidRows = 0;
        $errors = [];

        foreach ($groups as $identity => $personRows) {
            $attributes = $personRows[0]['attributes'];
            $existing = $this->findExistingContact($batch, $attributes, true);

            if ($existing !== null) {
                $canViewExisting = Contact::query()
                    ->where('organization_id', $batch->organization_id)
                    ->visibleTo($actor)
                    ->whereKey($existing->id)
                    ->exists();

                foreach ($personRows as $row) {
                    if ($canViewExisting) {
                        $duplicateRows++;
                        $this->appendError($errors, $row['line'], 'duplicate', 'O contato passou a existir durante a importação; as linhas foram ignoradas com segurança.');
                    } else {
                        $invalidRows++;
                        $this->appendError($errors, $row['line'], 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');
                    }
                }

                continue;
            }

            $contact = Contact::query()->create($attributes);
            $createdRows++;
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

        return ['createdRows' => $createdRows, 'duplicateRows' => $duplicateRows, 'invalidRows' => $invalidRows, 'errors' => $errors];
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
     * @return array{visible: array<string, true>, hidden: array<string, true>}
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

        $visibleContactIds = Contact::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->whereIn('contacts.id', $existingContacts->modelKeys())
            ->pluck('contacts.id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $identities = ['visible' => [], 'hidden' => []];

        foreach ($candidates as $candidate) {
            foreach ($existingContacts as $contact) {
                $emailMatches = ! empty($candidate['attributes']['email'])
                    && mb_strtolower((string) $contact->email) === mb_strtolower((string) $candidate['attributes']['email']);
                $phoneMatches = $this->digits((string) $contact->phone) !== ''
                    && $this->digits((string) $contact->phone) === $this->digits((string) ($candidate['attributes']['phone'] ?? ''));
                $nameMatches = mb_strtolower($this->normalize($contact->name)) === mb_strtolower($this->normalize((string) $candidate['attributes']['name']));

                if ($emailMatches || ($nameMatches && $phoneMatches)) {
                    $visibility = in_array((int) $contact->id, $visibleContactIds, true) ? 'visible' : 'hidden';
                    $identities[$visibility][$candidate['identity']] = true;
                    break;
                }
            }
        }

        return $identities;
    }

    /** @param array<string, mixed> $attributes */
    private function findExistingContact(DataImport $batch, array $attributes, bool $lock): ?Contact
    {
        $query = Contact::query()->where('organization_id', $batch->organization_id);
        if (! empty($attributes['email'])) {
            return $query->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $attributes['email'])])
                ->when($lock, fn ($contacts) => $contacts->lockForUpdate())
                ->first();
        }

        $phone = $this->digits((string) ($attributes['phone'] ?? ''));
        if ($phone === '') {
            return null;
        }

        return $query->whereRaw('LOWER(name) = ?', [mb_strtolower($this->normalize((string) $attributes['name']))])
            ->when($lock, fn ($contacts) => $contacts->lockForUpdate())
            ->get()
            ->first(fn (Contact $contact): bool => $this->digits((string) $contact->phone) === $phone);
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

    /** @param list<array{line: int, type: string, message: string}> $errors */
    private function appendError(array &$errors, int $line, string $type, string $message): void
    {
        if (count($errors) < 100) {
            $errors[] = ['line' => $line, 'type' => $type, 'message' => $message];
        }
    }
}
