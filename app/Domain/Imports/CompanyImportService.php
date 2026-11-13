<?php

namespace App\Domain\Imports;

use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Journeys\JourneyService;
use App\Models\Company;
use App\Models\DataImport;
use App\Models\JourneyTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanyImportService
{
    /**
     * @param  list<list<string>>  $rows
     * @param  list<int>  $malformedRows
     * @return array{
     *     totalRows: int,
     *     validRows: list<array{line: int, attributes: array<string, mixed>, resolution?: array{action: string, target_id?: int}}>,
     *     invalidRows: int,
     *     duplicateRows: int,
     *     errors: list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, city: string|null, state: string|null, tax_id_masked: string|null}>, can_create?: bool}>
     * }
     */
    public function analyze(DataImport $batch, User $actor, array $rows, int $totalRows, bool $lockDestination = false, array $malformedRows = []): array
    {
        $this->assertDestinationStillAuthorized($batch, $actor, $lockDestination);
        $mapping = is_array($batch->mapping) ? $batch->mapping : [];
        $fields = app(CompanyImportFields::class)->options();
        $candidates = [];
        $invalidRows = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            if (in_array($line, $malformedRows, true)) {
                $invalidRows++;
                $this->appendError($errors, $line, 'invalid', 'A linha contém mais colunas do que o cabeçalho.');

                continue;
            }

            $attributes = $this->mappedAttributes($mapping, $fields, $row);

            $attributes['name'] = trim((string) ($attributes['name'] ?? ''));
            $attributes['relationship_status'] = ($attributes['relationship_status'] ?? null) ?: 'prospect';
            $state = $attributes['state'] ?? null;
            $attributes['state'] = is_string($state) ? mb_strtoupper($state) : null;

            $taxId = $attributes['tax_id'] ?? null;
            if (is_string($taxId)) {
                $attributes['tax_id'] = preg_replace('/\D+/', '', $taxId) ?? '';
            }

            $validator = Validator::make($attributes, [
                'name' => ['required', 'string', 'max:180'],
                'trade_name' => ['nullable', 'string', 'max:180'],
                'legal_name' => ['nullable', 'string', 'max:180'],
                'tax_id' => ['nullable', 'regex:/^\d{14}$/'],
                'segment' => ['nullable', 'string', 'max:100'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:255'],
                'website' => ['nullable', 'url', 'max:255'],
                'city' => ['nullable', 'string', 'max:120'],
                'state' => ['nullable', 'size:2'],
                'address' => ['nullable', 'string', 'max:2000'],
                'relationship_status' => ['required', 'in:prospect,customer,inactive,partner'],
                'lead_source' => ['nullable', 'string', 'max:120'],
                'notes' => ['nullable', 'string', 'max:10000'],
            ], [
                'name.required' => 'Nome da empresa é obrigatório.',
                'tax_id.regex' => 'CNPJ deve conter 14 dígitos válidos.',
                'email.email' => 'E-mail inválido.',
                'website.url' => 'O site deve incluir um endereço válido, como https://exemplo.com.',
                'state.size' => 'UF deve conter duas letras.',
            ]);

            $messages = $validator->errors()->all();

            $taxId = $attributes['tax_id'] ?? null;
            if (is_string($taxId) && ! $this->isValidCnpj($taxId)) {
                $messages[] = 'CNPJ inválido.';
            }

            if ($messages !== []) {
                $invalidRows++;
                $this->appendError($errors, $line, 'invalid', implode(' ', array_unique($messages)));

                continue;
            }

            $attributes = array_filter($attributes, fn (mixed $value): bool => $value !== null);
            $candidates[] = [
                'line' => $line,
                'attributes' => $attributes,
                'keys' => $this->identityKeys($attributes),
            ];
        }

        $existingKeys = $this->existingIdentityKeys($batch, $actor, $candidates);
        $seenKeys = [];
        $seenTaxIds = [];
        $validRows = [];
        $duplicateRows = 0;

        foreach ($candidates as $candidate) {
            $isDuplicate = false;
            $isOutsidePortfolio = false;
            $taxIdSeenInFile = false;
            $matchingCompanies = [];

            foreach ($candidate['keys'] as $key) {
                if (isset($existingKeys['hidden'][$key])) {
                    $isOutsidePortfolio = true;
                } elseif (isset($existingKeys['visible'][$key])) {
                    $isDuplicate = true;
                    foreach ($existingKeys['candidatesByKey'][$key] ?? [] as $company) {
                        $matchingCompanies[$company['id']] = $company;
                    }
                } elseif (isset($seenKeys[$key])) {
                    $isDuplicate = true;
                    $taxIdSeenInFile = $taxIdSeenInFile || str_starts_with($key, 'cnpj:');
                }
            }

            foreach ($candidate['keys'] as $key) {
                $seenKeys[$key] = true;
            }

            $taxId = $candidate['attributes']['tax_id'] ?? null;
            if (is_string($taxId) && $taxId !== '') {
                $taxIdSeenInFile = $taxIdSeenInFile || isset($seenTaxIds[$taxId]);
                $seenTaxIds[$taxId] = true;
            }

            if ($isOutsidePortfolio) {
                $invalidRows++;
                $this->appendError($errors, $candidate['line'], 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');

                continue;
            }

            if ($isDuplicate) {
                $resolution = $this->requestedResolution($batch, $candidate['line']);
                $targetId = $this->resolvedTargetId($resolution, $matchingCompanies);
                $canCreate = ! $taxIdSeenInFile && ! $this->hasTaxIdMatch($candidate['attributes'], $matchingCompanies);

                if (($resolution['action'] ?? null) === 'ignore') {
                    $duplicateRows++;

                    continue;
                }

                if ($resolution === null
                    || ! in_array($resolution['action'], ['create', 'update', 'resolve', 'ignore'], true)
                    || ($resolution['action'] === 'create' && ! $canCreate)
                    || (in_array($resolution['action'], ['update', 'resolve'], true) && $targetId === null)) {
                    $duplicateRows++;
                    $this->appendError(
                        $errors,
                        $candidate['line'],
                        'duplicate',
                        'Possível duplicidade identificada. Escolha criar, atualizar um registro visível, resolver manualmente ou ignorar a linha.',
                        [
                            'candidates' => array_map(
                                fn (array $company): array => [
                                    'id' => $company['id'],
                                    'name' => $company['name'],
                                    'city' => $company['city'],
                                    'state' => $company['state'],
                                    'tax_id_masked' => $this->maskTaxId($company['tax_id']),
                                ],
                                array_values($matchingCompanies),
                            ),
                            'can_create' => $canCreate,
                        ],
                    );

                    continue;
                }

                if ($resolution['action'] === 'create') {
                    $candidate['resolution'] = ['action' => 'create'];
                } elseif ($targetId !== null) {
                    $candidate['resolution'] = [
                        'action' => $resolution['action'],
                        'target_id' => $targetId,
                    ];
                } else {
                    $duplicateRows++;
                    $this->appendError($errors, $candidate['line'], 'duplicate', 'A resolução salva não corresponde mais a um registro acessível. Revise a linha antes de continuar.');

                    continue;
                }
            }

            $attributes = $candidate['attributes'];
            if (! isset($candidate['resolution']) || $candidate['resolution']['action'] !== 'update') {
                $attributes['team_id'] = $batch->team_id;

                if ($actor->roleIn($batch->organization_id) === 'salesperson') {
                    $attributes['owner_user_id'] = $actor->id;
                }
            }

            $validRow = [
                'line' => $candidate['line'],
                'attributes' => $attributes,
            ];

            if (isset($candidate['resolution'])) {
                $validRow['resolution'] = $candidate['resolution'];
            }

            $validRows[] = $validRow;
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
     * @param  list<array{line: int, attributes: array<string, mixed>, resolution?: array{action: string, target_id?: int}}>  $validRows
     * @return array{createdRows: int, updatedRows: int, duplicateRows: int, invalidRows: int, errors: list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, city: string|null, state: string|null, tax_id_masked: string|null}>, can_create?: bool}>}
     */
    public function import(
        DataImport $batch,
        User $actor,
        array $validRows,
        CustomerScoreService $scores,
        ?JourneyTemplate $journeyTemplate,
        JourneyService $journeys,
    ): array {
        $this->assertDestinationStillAuthorized($batch, $actor, true);
        $candidates = [];

        foreach ($validRows as $row) {
            $candidates[] = [
                'line' => $row['line'],
                'attributes' => $row['attributes'],
                'resolution' => $row['resolution'] ?? null,
                'keys' => $this->identityKeys($row['attributes']),
            ];
        }

        $existingKeys = $this->existingIdentityKeys($batch, $actor, $candidates);
        $createdRows = 0;
        $updatedRows = 0;
        $duplicateRows = 0;
        $invalidRows = 0;
        $errors = [];

        foreach ($candidates as $candidate) {
            $outsidePortfolio = false;
            $duplicate = false;
            $matchingCompanyIds = [];

            foreach ($candidate['keys'] as $key) {
                if (isset($existingKeys['hidden'][$key])) {
                    $outsidePortfolio = true;
                } elseif (isset($existingKeys['visible'][$key])) {
                    $duplicate = true;
                    foreach ($existingKeys['candidatesByKey'][$key] ?? [] as $company) {
                        $matchingCompanyIds[] = $company['id'];
                    }
                }
            }

            $matchingCompanyIds = array_values(array_unique($matchingCompanyIds));

            if ($outsidePortfolio) {
                $invalidRows++;
                $this->appendError($errors, $candidate['line'], 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');

                continue;
            }

            if (($candidate['resolution']['action'] ?? null) === 'ignore') {
                $duplicateRows++;

                continue;
            }

            if (isset($candidate['resolution']) && in_array($candidate['resolution']['action'], ['update', 'resolve'], true)) {
                $targetId = $candidate['resolution']['target_id'] ?? null;
                $hasSingleCandidate = count($matchingCompanyIds) === 1;
                $hasManualCandidate = $candidate['resolution']['action'] === 'resolve';
                $target = is_int($targetId)
                    && in_array($targetId, $matchingCompanyIds, true)
                    && ($hasManualCandidate || $hasSingleCandidate)
                    ? Company::query()
                        ->where('organization_id', $batch->organization_id)
                        ->visibleTo($actor)
                        ->whereKey($targetId)
                        ->lockForUpdate()
                        ->first()
                    : null;

                if ($target === null) {
                    $invalidRows++;
                    $this->appendError($errors, $candidate['line'], 'invalid', 'O registro selecionado para atualização deixou de estar disponível no seu escopo.');

                    continue;
                }

                $this->assertManagerStillManagesCompanyTeam($batch, $actor, $target);

                $attributes = $candidate['attributes'];
                unset($attributes['owner_user_id'], $attributes['team_id'], $attributes['tax_id']);
                $target->fill(array_filter($attributes, fn (mixed $value): bool => $value !== null));
                $target->save();
                $scores->recalculateForCompany($target, 'Atualização por importação de planilha');
                $updatedRows++;

                continue;
            }

            if ($duplicate && (! isset($candidate['resolution']) || $candidate['resolution']['action'] !== 'create')) {
                $duplicateRows++;
                $this->appendError($errors, $candidate['line'], 'duplicate', 'A empresa passou a existir durante a importação; a linha foi ignorada com segurança.');

                continue;
            }

            $attributes = $candidate['attributes'];
            $taxId = $attributes['tax_id'] ?? null;
            unset($attributes['tax_id']);

            if ($taxId !== null) {
                $company = Company::query()->firstOrCreate(
                    ['tax_id' => $taxId],
                    ['tax_id' => $taxId, ...$attributes],
                );

                if (! $company->wasRecentlyCreated) {
                    $canViewExisting = Company::query()
                        ->where('organization_id', $batch->organization_id)
                        ->visibleTo($actor)
                        ->whereKey($company->id)
                        ->exists();

                    if ($canViewExisting) {
                        $duplicateRows++;
                        $this->appendError($errors, $candidate['line'], 'duplicate', 'A empresa passou a existir durante a importação; a linha foi ignorada com segurança.');
                    } else {
                        $invalidRows++;
                        $this->appendError($errors, $candidate['line'], 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');
                    }

                    continue;
                }
            } else {
                $company = Company::query()->create($attributes);
            }

            $scores->recalculateForCompany($company, 'Importação de planilha');

            if ($journeyTemplate !== null && $company->relationship_status === 'prospect') {
                $journeys->enrollManually($company, $journeyTemplate, $actor);
            }

            $createdRows++;
        }

        return [
            'createdRows' => $createdRows,
            'updatedRows' => $updatedRows,
            'duplicateRows' => $duplicateRows,
            'invalidRows' => $invalidRows,
            'errors' => $errors,
        ];
    }

    private function assertDestinationStillAuthorized(DataImport $batch, User $actor, bool $lockDestination): void
    {
        $role = $actor->roleIn($batch->organization_id);
        $teamId = $batch->team_id;

        if (! in_array($role, ['owner', 'admin', 'manager', 'salesperson'], true)) {
            throw new AuthorizationException;
        }

        if ($teamId !== null) {
            $team = Team::query()
                ->where('organization_id', $batch->organization_id)
                ->whereKey($teamId)
                ->when($lockDestination, fn ($query) => $query->lockForUpdate())
                ->first();

            if ($team === null || $team->status !== 'active') {
                throw ValidationException::withMessages(['team_id' => 'A equipe de destino não está mais ativa nesta organização.']);
            }
        }

        if ($role === 'manager' && ($teamId === null || ! $this->hasActiveTeamMembership($batch, $actor, 'manager', $lockDestination))) {
            throw ValidationException::withMessages(['team_id' => 'Você não administra mais a equipe de destino selecionada.']);
        }

        if ($role === 'salesperson' && $teamId !== null && ! $this->hasActiveTeamMembership($batch, $actor, 'salesperson', $lockDestination)) {
            throw ValidationException::withMessages(['team_id' => 'Você não faz mais parte da equipe de destino selecionada.']);
        }
    }

    private function hasActiveTeamMembership(DataImport $batch, User $actor, string $role, bool $lockDestination): bool
    {
        $membership = DB::table('team_memberships')
            ->where('organization_id', $batch->organization_id)
            ->where('team_id', $batch->team_id)
            ->where('user_id', $actor->id)
            ->where('role', $role)
            ->where('is_active', true)
            ->when($lockDestination, fn ($query) => $query->lockForUpdate())
            ->first();

        return $membership !== null;
    }

    /**
     * @param  array<string, array{label: string, required: bool}>  $fields
     * @param  array<string, int>  $mapping
     * @param  list<string>  $row
     * @return array<string, string|null>
     */
    private function mappedAttributes(array $mapping, array $fields, array $row): array
    {
        $attributes = [];

        foreach ($mapping as $field => $columnIndex) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            $value = trim($row[$columnIndex] ?? '');
            $attributes[$field] = $value === '' ? null : $value;
        }

        return $attributes;
    }

    /**
     * @param  list<array{line: int, attributes: array<string, mixed>, keys: list<string>}>  $candidates
     * @return array{visible: array<string, true>, hidden: array<string, true>, candidatesByKey: array<string, list<array{id: int, name: string, city: string|null, state: string|null, tax_id: string|null}>>}
     */
    private function existingIdentityKeys(DataImport $batch, User $actor, array $candidates): array
    {
        $taxIds = [];
        $names = [];
        $lowerNames = [];

        foreach ($candidates as $candidate) {
            $attributes = $candidate['attributes'];

            if (isset($attributes['tax_id'])) {
                $taxIds[] = $attributes['tax_id'];
                $taxIds[] = $this->formatCnpj($attributes['tax_id']);
            }

            $names[] = $attributes['name'];
            $lowerNames[] = mb_strtolower($attributes['name']);
        }

        $companies = Company::query()
            ->where('organization_id', $batch->organization_id)
            ->where(function ($query) use ($taxIds, $names, $lowerNames): void {
                if ($taxIds !== []) {
                    $query->whereIn('tax_id', array_unique($taxIds));
                }

                if ($names !== []) {
                    $method = $taxIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('name', array_unique($names))
                        ->orWhereIn(DB::raw('LOWER(name)'), array_unique($lowerNames));
                }
            })
            ->get(['id', 'tax_id', 'name', 'city', 'state']);

        $keys = ['visible' => [], 'hidden' => [], 'candidatesByKey' => []];

        $visibleCompanyIds = Company::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->whereKey($companies->modelKeys())
            ->pluck('id')
            ->all();
        $visibleCompanyIds = array_fill_keys($visibleCompanyIds, true);

        foreach ($companies as $company) {
            $visibility = isset($visibleCompanyIds[$company->id]) ? 'visible' : 'hidden';

            foreach ($this->identityKeys([
                'tax_id' => $company->tax_id ? preg_replace('/\D+/', '', $company->tax_id) : null,
                'name' => $company->name,
                'city' => $company->city,
                'state' => $company->state,
            ]) as $key) {
                $keys[$visibility][$key] = true;

                if ($visibility === 'visible') {
                    $keys['candidatesByKey'][$key][] = [
                        'id' => $company->id,
                        'name' => $company->name,
                        'city' => $company->city,
                        'state' => $company->state,
                        'tax_id' => $company->tax_id,
                    ];
                }
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function identityKeys(array $attributes): array
    {
        $keys = [];
        $taxId = (string) ($attributes['tax_id'] ?? '');

        if ($taxId !== '') {
            $keys[] = 'cnpj:'.$taxId;
        }

        $city = $this->normalize((string) ($attributes['city'] ?? ''));
        $state = mb_strtoupper(trim((string) ($attributes['state'] ?? '')));

        if ($city !== '' && $state !== '') {
            $keys[] = 'location:'.$this->normalize((string) ($attributes['name'] ?? '')).'|'.$city.'|'.$state;
        }

        return $keys;
    }

    private function normalize(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $lower) ?? $lower;
    }

    private function isValidCnpj(string $digits): bool
    {
        if (preg_match('/^\d{14}$/', $digits) !== 1 || preg_match('/^(\d)\1{13}$/', $digits) === 1) {
            return false;
        }

        $firstWeights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $secondWeights = [6, ...$firstWeights];
        $base = substr($digits, 0, 12);
        $first = $this->cnpjDigit($base, $firstWeights);
        $second = $this->cnpjDigit($base.$first, $secondWeights);

        return substr($digits, 12, 2) === $first.$second;
    }

    /** @param list<int> $weights */
    private function cnpjDigit(string $digits, array $weights): string
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        $remainder = $sum % 11;

        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{id: int, name: string, city: string|null, state: string|null, tax_id: string|null}>  $matches
     */
    private function hasTaxIdMatch(array $attributes, array $matches): bool
    {
        $taxId = $attributes['tax_id'] ?? null;

        if (! is_string($taxId) || $taxId === '') {
            return false;
        }

        foreach ($matches as $match) {
            if (is_string($match['tax_id']) && preg_replace('/\D+/', '', $match['tax_id']) === $taxId) {
                return true;
            }
        }

        return false;
    }

    private function assertManagerStillManagesCompanyTeam(DataImport $batch, User $actor, Company $company): void
    {
        if ($actor->roleIn($batch->organization_id) !== 'manager') {
            return;
        }

        $teamId = $company->team_id;

        if ($teamId === null) {
            throw ValidationException::withMessages(['team_id' => 'Seu acesso a uma equipe da empresa foi alterado. Revise o lote antes de tentar novamente.']);
        }

        $team = Team::query()
            ->where('organization_id', $batch->organization_id)
            ->whereKey($teamId)
            ->lockForUpdate()
            ->first();
        $membership = DB::table('team_memberships')
            ->where('organization_id', $batch->organization_id)
            ->where('team_id', $teamId)
            ->where('user_id', $actor->id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($team === null || $team->status !== 'active' || $membership === null) {
            throw ValidationException::withMessages(['team_id' => 'Seu acesso a uma equipe da empresa foi alterado. Revise o lote antes de tentar novamente.']);
        }
    }

    private function maskTaxId(?string $taxId): ?string
    {
        $digits = preg_replace('/\D+/', '', $taxId ?? '') ?? '';

        if (strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 2).'.***.***/****-'.substr($digits, -2);
    }

    /** @return array{action: string, target_id?: int}|null */
    private function requestedResolution(DataImport $batch, int $line): ?array
    {
        $resolutions = $batch->duplicate_resolutions ?? [];

        return $resolutions[$line] ?? null;
    }

    /**
     * @param  array{action: string, target_id?: int}|null  $resolution
     * @param  array<int, array{id: int, name: string, city: string|null, state: string|null, tax_id: string|null}>  $matches
     */
    private function resolvedTargetId(?array $resolution, array $matches): ?int
    {
        if (! in_array($resolution['action'] ?? null, ['update', 'resolve'], true)) {
            return null;
        }

        if ($resolution['action'] === 'update') {
            return count($matches) === 1 ? (int) array_key_first($matches) : null;
        }

        $targetId = $resolution['target_id'] ?? null;

        return is_int($targetId) && isset($matches[$targetId]) ? $targetId : null;
    }

    private function formatCnpj(string $digits): string
    {
        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) ?? $digits;
    }

    /**
     * @param  list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, city: string|null, state: string|null, tax_id_masked: string|null}>, can_create?: bool}>  $errors
     * @param  array{candidates?: list<array{id: int, name: string, city: string|null, state: string|null, tax_id_masked: string|null}>, can_create?: bool}  $context
     */
    private function appendError(array &$errors, int $line, string $type, string $message, array $context = []): void
    {
        if (count($errors) < 100) {
            $errors[] = ['line' => $line, 'type' => $type, 'message' => $message, ...$context];
        }
    }
}
