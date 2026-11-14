<?php

namespace App\Domain\Imports;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\DataImport;
use App\Models\JourneyEnrollment;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaleImportService
{
    /**
     * @param  list<list<string>>  $rows
     * @param  list<int>  $malformedRows
     * @return array{
     *     totalRows: int,
     *     validRows: list<array{line: int, external_id: string|null, company_id: int, seller_id: int, description: string, amount: string, currency: string, sold_at: string, status: string, notes: string|null, resolution?: array{action: string, target_id?: int}}>,
     *     invalidRows: int,
     *     duplicateRows: int,
     *     errors: list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, detail?: string}>, can_create?: bool, can_update?: bool, duplicate_source?: 'file'|'database'}>
     * }
     */
    public function analyze(DataImport $batch, User $actor, array $rows, int $totalRows, bool $lockDestination = false, array $malformedRows = []): array
    {
        $this->assertImporter($batch, $actor, false);
        $mapping = is_array($batch->mapping) ? $batch->mapping : [];
        $fields = app(SaleImportFields::class)->options();
        $preparedRows = [];
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
            $externalId = trim((string) ($attributes['external_id'] ?? ''));
            $attributes['external_id'] = $externalId === '' ? null : $externalId;
            $attributes['company_tax_id'] = isset($attributes['company_tax_id'])
                ? (preg_replace('/\D+/', '', $attributes['company_tax_id']) ?? '')
                : null;
            $attributes['seller_email'] = mb_strtolower(trim((string) ($attributes['seller_email'] ?? '')));
            $attributes['status'] = mb_strtolower(trim((string) ($attributes['status'] ?? '')));
            $attributes['currency'] = mb_strtoupper(trim((string) ($attributes['currency'] ?? '')));
            $attributes['amount'] = $this->normalizeAmount((string) ($attributes['amount'] ?? ''));

            $messages = Validator::make($attributes, [
                'external_id' => ['nullable', 'string', 'max:100'],
                'company_tax_id' => ['nullable', 'regex:/^\d{14}$/'],
                'company_name' => ['nullable', 'string', 'max:180'],
                'seller_email' => ['required', 'email', 'max:255'],
                'description' => ['required', 'string', 'max:255'],
                'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99', 'decimal:0,2'],
                'currency' => ['required', 'in:BRL'],
                'sold_at' => ['required', 'string', 'max:80'],
                'status' => ['required', 'in:confirmed,cancelled'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ])->errors()->all();

            if (($attributes['company_tax_id'] ?? null) === null && trim((string) ($attributes['company_name'] ?? '')) === '') {
                $messages[] = 'Informe o CNPJ ou o nome exato da empresa.';
            }

            if ($messages !== []) {
                $invalidRows++;
                $this->appendError($errors, $line, 'invalid', implode(' ', array_unique($messages)));

                continue;
            }

            $soldAt = $this->parseSoldAt((string) $attributes['sold_at']);

            if ($soldAt === null) {
                $invalidRows++;
                $message = $this->isAmbiguousSlashDate((string) $attributes['sold_at'])
                    ? 'A data da venda usa um formato ambíguo. Use AAAA-MM-DD ou ISO 8601.'
                    : 'A data da venda deve usar AAAA-MM-DD, AAAA-MM-DD HH:MM[:SS] ou ISO 8601.';
                $this->appendError($errors, $line, 'invalid', $message);

                continue;
            }

            if ($soldAt->isFuture()) {
                $invalidRows++;
                $this->appendError($errors, $line, 'invalid', 'A data da compra não pode estar no futuro no fuso da organização.');

                continue;
            }

            $attributes['amount'] = $this->canonicalAmount($attributes['amount']);
            $attributes['sold_at'] = $soldAt->toDateTimeString();
            $preparedRows[] = ['line' => $line, 'attributes' => $attributes];
        }

        $companyCandidates = $this->companyCatalog($batch, $actor, $preparedRows, false);
        $externalIds = array_values(array_unique(array_filter(array_map(
            static fn (array $prepared): ?string => $prepared['attributes']['external_id'],
            $preparedRows,
        ), static fn (?string $externalId): bool => $externalId !== null)));
        $existingSales = new EloquentCollection;

        if ($externalIds !== []) {
            $existingSales = Sale::query()
                ->where('organization_id', $batch->organization_id)
                ->whereIn('external_id', $externalIds)
                ->orderBy('id')
                ->when($lockDestination, fn ($sales) => $sales->lockForUpdate())
                ->get(['id', 'external_id', 'company_id']);
        }

        if ($lockDestination) {
            $lockedCompanyIds = $this->lockImportReferences($batch, $actor, array_values($companyCandidates->modelKeys()), $existingSales);
            $this->assertImporter($batch, $actor, true);
            $companyCatalog = $this->companyCatalog($batch, $actor, $preparedRows, false)
                ->whereIn('id', $lockedCompanyIds)
                ->values();
        } else {
            $companyCatalog = $companyCandidates;
        }

        $sellerCatalog = $this->sellerCatalog($batch, $preparedRows);
        $teamMemberships = $this->teamMembershipCatalog($batch, $actor, $companyCatalog, $sellerCatalog, $lockDestination);
        $sellerCatalog = $this->lockSellerMemberships($batch, $sellerCatalog, $lockDestination);

        $visibleExistingSales = Sale::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->whereIn('id', $existingSales->modelKeys())
            ->with('company:id,name')
            ->get(['id', 'company_id', 'seller_id', 'created_by', 'external_id', 'description', 'amount', 'sold_at', 'status'])
            ->keyBy('external_id');
        $existingExternalIds = [];

        foreach ($existingSales as $existingSale) {
            $existingExternalIds[$existingSale->external_id] = $visibleExistingSales->get($existingSale->external_id);
            if ($existingExternalIds[$existingSale->external_id] === null) {
                $existingExternalIds[$existingSale->external_id] = false;
            }
        }

        $seenExternalIds = [];
        $validRows = [];
        $duplicateRows = 0;

        foreach ($preparedRows as $prepared) {
            $line = $prepared['line'];
            $attributes = $prepared['attributes'];
            $company = $this->resolveCompany($companyCatalog, $attributes);
            $sellerMatches = $sellerCatalog[mb_strtolower($attributes['seller_email'])] ?? [];
            $seller = count($sellerMatches) === 1 ? $sellerMatches[0] : null;
            $messages = [];

            if ($company === null) {
                $messages[] = 'A empresa não foi encontrada ou não está acessível nesta organização.';
            }

            if ($seller === null) {
                $messages[] = 'O vendedor informado não é único e ativo nesta organização.';
            } elseif (! $this->sellerIsAuthorized($batch, $actor, $company, $seller, $teamMemberships)) {
                $messages[] = 'O vendedor não está autorizado para esta carteira e empresa.';
            }

            if ($messages !== []) {
                $invalidRows++;
                $this->appendError($errors, $line, 'invalid', implode(' ', $messages));

                continue;
            }

            $externalId = $attributes['external_id'];
            $isRepeatedFileIdentifier = $externalId !== null && isset($seenExternalIds[$externalId]);
            if ($externalId !== null) {
                $seenExternalIds[$externalId] = true;
            }

            if ($externalId !== null && isset($existingExternalIds[$externalId])) {
                $existingSale = $existingExternalIds[$externalId];
                if ($existingSale === false) {
                    if ($isRepeatedFileIdentifier) {
                        $duplicateRows++;
                        $this->appendError(
                            $errors,
                            $line,
                            'duplicate',
                            'Identificador externo repetido no arquivo; somente a primeira linha pode ser importada.',
                            ['duplicate_source' => 'file'],
                        );
                    } else {
                        $invalidRows++;
                        $this->appendError($errors, $line, 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');
                    }

                    continue;
                }

                $resolution = $this->requestedResolution($batch, $line);
                $requestedTargetId = $resolution['target_id'] ?? null;
                $canUpdate = ! $isRepeatedFileIdentifier && Gate::forUser($actor)->allows('update', $existingSale);
                $hasAuthorizedUpdate = $canUpdate
                    && is_int($requestedTargetId)
                    && $requestedTargetId === $existingSale->id
                    && in_array($resolution['action'] ?? null, ['update', 'resolve'], true);

                if (($resolution['action'] ?? null) === 'ignore' || $isRepeatedFileIdentifier) {
                    $duplicateRows++;
                    if (($resolution['action'] ?? null) !== 'ignore') {
                        $this->appendSaleDuplicateError($errors, $line, $existingSale, false, 'Identificador externo repetido no arquivo; somente a primeira linha pode atualizar a venda existente.', 'file');
                    }

                    continue;
                }

                if (! $hasAuthorizedUpdate) {
                    $duplicateRows++;
                    $message = $canUpdate
                        ? 'O identificador externo já existe nesta organização. Escolha atualizar a venda correspondente ou ignorar a linha.'
                        : 'A venda existente pode ser visualizada, mas não pode ser atualizada pelo seu acesso atual. Ignore esta linha.';
                    $this->appendSaleDuplicateError($errors, $line, $existingSale, $canUpdate, $message);

                    continue;
                }

                $resolution = [
                    'action' => 'update',
                    'target_id' => (int) $existingSale->id,
                ];
            } elseif ($isRepeatedFileIdentifier) {
                $duplicateRows++;
                $this->appendError($errors, $line, 'duplicate', 'Identificador externo repetido no arquivo; somente a primeira linha será importada.', [
                    'can_create' => false,
                    'can_update' => false,
                    'duplicate_source' => 'file',
                ]);

                continue;
            } else {
                $resolution = null;
            }

            $validRow = [
                'line' => $line,
                'external_id' => $externalId,
                'company_id' => $company->id,
                'seller_id' => $seller->id,
                'description' => trim($attributes['description']),
                'amount' => $attributes['amount'],
                'currency' => $attributes['currency'],
                'sold_at' => $attributes['sold_at'],
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
            ];

            if ($resolution !== null) {
                $validRow['resolution'] = $resolution;
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
     * @param  list<array{line: int, external_id: string|null, company_id: int, seller_id: int, description: string, amount: string, currency: string, sold_at: string, status: string, notes: string|null, resolution?: array{action: string, target_id?: int}}>  $validRows
     * @return array{createdRows: int, updatedRows: int, duplicateRows: int, invalidRows: int, errors: list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, detail?: string}>, can_create?: bool, can_update?: bool}>}
     */
    public function import(
        DataImport $batch,
        User $actor,
        array $validRows,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
        JourneyService $journeys,
    ): array {
        $destinationCompanyIds = [];
        $targetSaleIds = [];
        $targetExternalIds = [];
        $targetSalePairs = [];

        foreach ($validRows as $row) {
            $destinationCompanyIds[] = $row['company_id'];
            $resolution = $row['resolution'] ?? null;

            if (($resolution['action'] ?? null) !== 'update'
                || ! is_int($resolution['target_id'] ?? null)
                || $row['external_id'] === null) {
                continue;
            }

            $targetSaleIds[] = $resolution['target_id'];
            $targetExternalIds[] = $row['external_id'];
            $targetSalePairs[$resolution['target_id'].':'.$row['external_id']] = true;
        }

        $existingSales = $targetSaleIds === []
            ? new EloquentCollection
            : Sale::query()
                ->where('organization_id', $batch->organization_id)
                ->whereIn('id', array_values(array_unique($targetSaleIds)))
                ->whereIn('external_id', array_values(array_unique($targetExternalIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'company_id', 'external_id'])
                ->filter(static fn (Sale $sale): bool => isset($targetSalePairs[$sale->id.':'.$sale->external_id]))
                ->values();

        $destinationCompanyIds = array_values(array_unique($destinationCompanyIds));
        $this->lockImportReferences($batch, $actor, $destinationCompanyIds, $existingSales);

        $createdRows = 0;
        $updatedRows = 0;
        $duplicateRows = 0;
        $invalidRows = 0;
        $errors = [];

        foreach ($validRows as $row) {
            $company = Company::query()
                ->where('organization_id', $batch->organization_id)
                ->visibleTo($actor)
                ->whereKey($row['company_id'])
                ->lockForUpdate()
                ->first();

            if ($company === null) {
                $invalidRows++;
                $this->appendError($errors, $row['line'], 'invalid', 'O acesso à empresa mudou; a linha não foi importada.');

                continue;
            }

            $seller = $this->authorizedSellerForImport($batch, $actor, $company, $row['seller_id']);

            if ($seller === null) {
                $invalidRows++;
                $this->appendError($errors, $row['line'], 'invalid', 'As permissões do vendedor ou da equipe mudaram; a linha não foi importada.');

                continue;
            }

            $saleAttributes = [
                'organization_id' => $batch->organization_id,
                'company_id' => $company->id,
                'seller_id' => $seller->id,
                'team_id_at_sale' => $company->team_id,
                'created_by' => $actor->id,
                'external_id' => $row['external_id'],
                'description' => $row['description'],
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'sold_at' => $row['sold_at'],
                'status' => $row['status'],
                'notes' => $row['notes'],
                'cancelled_at' => $row['status'] === 'cancelled' ? now() : null,
                'cancelled_by' => $row['status'] === 'cancelled' ? $actor->id : null,
            ];
            $resolution = $row['resolution'] ?? null;
            $existingSale = null;
            $sourceCompany = null;

            if (($resolution['action'] ?? null) === 'update') {
                $targetId = $resolution['target_id'] ?? null;
                if (! is_int($targetId) || $row['external_id'] === null) {
                    $invalidRows++;
                    $this->appendError($errors, $row['line'], 'invalid', 'A decisão de atualização não corresponde a uma venda válida. Revise a linha antes de continuar.');

                    continue;
                }

                $existingSale = Sale::query()
                    ->where('organization_id', $batch->organization_id)
                    ->where('external_id', $row['external_id'])
                    ->visibleTo($actor)
                    ->whereKey($targetId)
                    ->lockForUpdate()
                    ->first();

                if ($existingSale === null) {
                    $invalidRows++;
                    $this->appendError($errors, $row['line'], 'invalid', 'A venda escolhida deixou de corresponder ao identificador externo ou não está mais acessível. Revise a linha antes de continuar.');

                    continue;
                }

                $sourceCompany = Company::query()
                    ->where('organization_id', $batch->organization_id)
                    ->lockForUpdate()
                    ->find($existingSale->company_id);

                if ($sourceCompany === null
                    || ! $this->lockManagerSourceTeamMembership($batch, $actor, $sourceCompany)
                    || ! Gate::forUser($actor)->allows('update', $existingSale)) {
                    $invalidRows++;
                    $this->appendError($errors, $row['line'], 'invalid', 'A venda escolhida deixou de estar autorizada para atualização. Revise a linha antes de continuar.');

                    continue;
                }
            }

            $isFirstPurchase = $row['status'] === 'confirmed'
                && $company->relationship_status === 'prospect'
                && ! $company->sales()
                    ->where('status', 'confirmed')
                    ->when($existingSale !== null, fn ($sales) => $sales->whereKeyNot($existingSale->id))
                    ->exists();

            $sale = $existingSale ?? ($row['external_id'] === null
                ? Sale::query()->create($saleAttributes)
                : Sale::query()->firstOrCreate(
                    ['organization_id' => $batch->organization_id, 'external_id' => $row['external_id']],
                    $saleAttributes,
                ));

            if ($existingSale !== null) {
                $previousCompanyId = (int) $sale->company_id;
                $updateAttributes = $saleAttributes;
                unset($updateAttributes['created_by']);
                $sale->update($updateAttributes);
                $updatedRows++;

                if ($previousCompanyId !== (int) $company->id) {
                    $inactivity->refreshCommercialForCompany($sourceCompany);
                    $scores->recalculateForCompany($sourceCompany, 'Venda histórica importada');
                }
            } elseif (! $sale->wasRecentlyCreated) {
                $canSeeExistingSale = Sale::query()
                    ->where('organization_id', $batch->organization_id)
                    ->visibleTo($actor)
                    ->whereKey($sale->id)
                    ->with('company:id,name')
                    ->first();

                if ($canSeeExistingSale) {
                    $duplicateRows++;
                    $this->appendSaleDuplicateError($errors, $row['line'], $canSeeExistingSale, false, 'O identificador externo foi importado por outro processo; a linha foi ignorada. Valide o lote novamente para escolher uma ação.');
                } else {
                    $invalidRows++;
                    $this->appendError($errors, $row['line'], 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');
                }

                continue;
            }

            if ($row['status'] === 'confirmed') {
                $inactivity->resolveCommercialForCompany($company);
            } else {
                $inactivity->refreshCommercialForCompany($company);
            }

            if ($isFirstPurchase) {
                $company->update(['relationship_status' => 'customer']);
                JourneyEnrollment::query()
                    ->where('company_id', $company->id)
                    ->whereIn('status', ['active', 'paused'])
                    ->whereHas('template', fn ($templates) => $templates->where('type', 'prospecting'))
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (JourneyEnrollment $enrollment) => $journeys->cancel($enrollment));

            }

            $scores->recalculateForCompany($company, $row['status'] === 'confirmed' ? 'Venda histórica importada' : 'Venda cancelada importada');
            if ($existingSale === null) {
                $createdRows++;
            }
            unset($sale);
        }

        return [
            'createdRows' => $createdRows,
            'updatedRows' => $updatedRows,
            'duplicateRows' => $duplicateRows,
            'invalidRows' => $invalidRows,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, detail?: string}>, can_create?: bool, can_update?: bool, duplicate_source?: 'file'|'database'}>  $errors
     * @param  'file'|'database'  $source
     */
    private function appendSaleDuplicateError(array &$errors, int $line, Sale $sale, bool $canUpdate, string $message, string $source = 'database'): void
    {
        $company = $sale->company;
        $soldAt = $sale->sold_at->format('Y-m-d');
        $details = trim($sale->description).' · R$ '.number_format((float) $sale->amount, 2, ',', '.')
            .' · '.$soldAt.' · '.($sale->status === 'confirmed' ? 'confirmada' : 'cancelada');

        $this->appendError($errors, $line, 'duplicate', $message, [
            'candidates' => [[
                'id' => (int) $sale->id,
                'name' => $company instanceof Company ? $company->name : 'Venda existente',
                'detail' => $details,
            ]],
            'can_create' => false,
            'can_update' => $canUpdate,
            'duplicate_source' => $source,
        ]);
    }

    /** @return array{action: string, target_id?: int}|null */
    private function requestedResolution(DataImport $batch, int $line): ?array
    {
        $resolution = ($batch->duplicate_resolutions ?? [])[(string) $line] ?? ($batch->duplicate_resolutions ?? [])[$line] ?? null;

        return is_array($resolution) ? $resolution : null;
    }

    private function authorizedSellerForImport(DataImport $batch, User $actor, Company $company, int $sellerId): ?User
    {
        $team = null;

        if ($company->team_id !== null) {
            $team = Team::query()
                ->where('organization_id', $batch->organization_id)
                ->whereKey($company->team_id)
                ->lockForUpdate()
                ->first();

            if ($team === null || $team->status !== 'active') {
                return null;
            }
        }

        $actorMembership = DB::table('organization_user')
            ->where('organization_id', $batch->organization_id)
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->first(['role', 'is_active']);

        if ($actorMembership === null
            || ! (bool) $actorMembership->is_active
            || ! in_array($actorMembership->role, ['owner', 'admin', 'manager', 'salesperson'], true)) {
            return null;
        }

        $sellerMembership = DB::table('organization_user')
            ->where('organization_id', $batch->organization_id)
            ->where('user_id', $sellerId)
            ->lockForUpdate()
            ->first(['role', 'is_active']);

        if ($sellerMembership === null
            || ! (bool) $sellerMembership->is_active
            || $sellerMembership->role !== 'salesperson') {
            return null;
        }

        $seller = User::query()->find($sellerId);

        if ($seller === null) {
            return null;
        }

        $memberships = [];

        if ($team !== null) {
            $userIds = array_values(array_unique([(int) $actor->id, (int) $seller->id]));
            sort($userIds);
            $teamMemberships = DB::table('team_memberships')
                ->where('organization_id', $batch->organization_id)
                ->where('team_id', $team->id)
                ->whereIn('user_id', $userIds)
                ->where('is_active', true)
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get(['user_id', 'role']);

            foreach ($teamMemberships as $membership) {
                $memberships[$team->id.':'.$membership->user_id.':'.$membership->role] = true;
            }
        }

        return $this->sellerIsAuthorized($batch, $actor, $company, $seller, $memberships) ? $seller : null;
    }

    private function assertImporter(DataImport $batch, User $actor, bool $lockDestination): void
    {
        $role = $actor->roleIn($batch->organization_id);

        if (! in_array($role, ['owner', 'admin', 'manager', 'salesperson'], true)) {
            throw new AuthorizationException;
        }

        if ($batch->team_id !== null) {
            $team = Team::query()
                ->where('organization_id', $batch->organization_id)
                ->whereKey($batch->team_id)
                ->when($lockDestination, fn ($teams) => $teams->lockForUpdate())
                ->first();

            if ($team === null || $team->status !== 'active') {
                throw ValidationException::withMessages(['team_id' => 'A equipe de destino não está mais ativa nesta organização.']);
            }
        }

        if ($role === 'manager' && ($batch->team_id === null || ! $this->hasActiveTeamMembership($batch, $actor, 'manager', $lockDestination))) {
            throw ValidationException::withMessages(['team_id' => 'Você não administra mais a equipe de destino selecionada.']);
        }

        if ($role === 'salesperson' && $batch->team_id !== null && ! $this->hasActiveTeamMembership($batch, $actor, 'salesperson', $lockDestination)) {
            throw ValidationException::withMessages(['team_id' => 'Você não faz mais parte da equipe de destino selecionada.']);
        }
    }

    private function lockManagerSourceTeamMembership(DataImport $batch, User $actor, Company $sourceCompany): bool
    {
        if ($actor->roleIn($batch->organization_id) !== 'manager') {
            return true;
        }

        if ($sourceCompany->team_id === null) {
            return false;
        }

        $team = Team::query()
            ->where('organization_id', $batch->organization_id)
            ->whereKey($sourceCompany->team_id)
            ->lockForUpdate()
            ->first(['id', 'status']);

        if ($team === null || $team->status !== 'active') {
            return false;
        }

        $membership = DB::table('team_memberships')
            ->where('organization_id', $batch->organization_id)
            ->where('team_id', $team->id)
            ->where('user_id', $actor->id)
            ->where('role', 'manager')
            ->lockForUpdate()
            ->first(['is_active']);

        return $membership !== null && (bool) $membership->is_active;
    }

    /**
     * @param  list<int|string>  $destinationCompanyIds
     * @param  EloquentCollection<int, Sale>  $existingSales
     * @return list<int>
     */
    private function lockImportReferences(DataImport $batch, User $actor, array $destinationCompanyIds, EloquentCollection $existingSales): array
    {
        $sourceCompanyIds = $existingSales->pluck('company_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $companyIds = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            [...$destinationCompanyIds, ...$sourceCompanyIds],
        )));
        sort($companyIds, SORT_NUMERIC);

        $companies = $companyIds === []
            ? new EloquentCollection
            : Company::query()
                ->where('organization_id', $batch->organization_id)
                ->whereIn('id', $companyIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        $teamIds = $companies->pluck('team_id')
            ->filter()
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        if ($batch->team_id !== null) {
            $teamIds[] = (int) $batch->team_id;
        }
        $teamIds = array_values(array_unique($teamIds));
        sort($teamIds, SORT_NUMERIC);

        if ($teamIds !== []) {
            Team::query()
                ->where('organization_id', $batch->organization_id)
                ->whereIn('id', $teamIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status']);
        }

        $sourceTeamIds = $companies->whereIn('id', $sourceCompanyIds)
            ->pluck('team_id')
            ->filter()
            ->map(static fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($sourceTeamIds !== [] && $actor->roleIn($batch->organization_id) === 'manager') {
            DB::table('team_memberships')
                ->where('organization_id', $batch->organization_id)
                ->whereIn('team_id', $sourceTeamIds)
                ->where('user_id', $actor->id)
                ->where('role', 'manager')
                ->orderBy('team_id')
                ->lockForUpdate()
                ->get(['team_id', 'is_active']);
        }

        return array_values(array_map(static fn (int|string $id): int => (int) $id, $companies->modelKeys()));
    }

    private function hasActiveTeamMembership(DataImport $batch, User $actor, string $role, bool $lockDestination): bool
    {
        return DB::table('team_memberships')
            ->where('organization_id', $batch->organization_id)
            ->where('team_id', $batch->team_id)
            ->where('user_id', $actor->id)
            ->where('role', $role)
            ->where('is_active', true)
            ->when($lockDestination, fn ($memberships) => $memberships->lockForUpdate())
            ->exists();
    }

    /**
     * @param  list<array{line: int, attributes: array<string, string|null>}>  $rows
     * @return EloquentCollection<int, Company>
     */
    private function companyCatalog(DataImport $batch, User $actor, array $rows, bool $lock): EloquentCollection
    {
        $taxIds = [];
        $names = [];

        foreach ($rows as $prepared) {
            $attributes = $prepared['attributes'];
            if (! empty($attributes['company_tax_id'])) {
                $taxIds[] = $attributes['company_tax_id'];
            }
            foreach (['company_name'] as $field) {
                if (! empty($attributes[$field])) {
                    $names[] = $this->normalize((string) $attributes[$field]);
                }
            }
        }

        $companies = new EloquentCollection;
        foreach (array_chunk(array_values(array_unique($taxIds)), 200) as $taxChunk) {
            $taxAliases = array_values(array_unique([
                ...$taxChunk,
                ...array_map($this->formatCnpj(...), $taxChunk),
            ]));
            $companies = $companies->merge(Company::query()
                ->where('organization_id', $batch->organization_id)
                ->visibleTo($actor)
                ->whereIn('tax_id', $taxAliases)
                ->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->get());
        }
        foreach (array_chunk(array_values(array_unique($names)), 200) as $nameChunk) {
            $companies = $companies->merge(Company::query()
                ->where('organization_id', $batch->organization_id)
                ->visibleTo($actor)
                ->where(fn ($query) => $query
                    ->whereIn(DB::raw('LOWER(name)'), $nameChunk)
                    ->orWhereIn(DB::raw('LOWER(trade_name)'), $nameChunk))
                ->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->get());
        }

        return $companies->unique('id')->values();
    }

    /**
     * @param  list<array{line: int, attributes: array<string, string|null>}>  $rows
     * @return array<string, list<User>>
     */
    private function sellerCatalog(DataImport $batch, array $rows): array
    {
        $emails = [];
        foreach ($rows as $prepared) {
            if (! empty($prepared['attributes']['seller_email'])) {
                $emails[] = mb_strtolower($prepared['attributes']['seller_email']);
            }
        }

        $catalog = [];
        foreach (array_chunk(array_values(array_unique($emails)), 400) as $emailChunk) {
            $sellers = User::query()
                ->select('users.*')
                ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
                ->where('organization_user.organization_id', $batch->organization_id)
                ->where('organization_user.role', 'salesperson')
                ->where('organization_user.is_active', true)
                ->whereIn(DB::raw('LOWER(users.email)'), $emailChunk)
                ->get();

            foreach ($sellers as $seller) {
                $catalog[mb_strtolower($seller->email)][] = $seller;
            }
        }

        return $catalog;
    }

    /**
     * @param  array<string, list<User>>  $catalog
     * @return array<string, list<User>>
     */
    private function lockSellerMemberships(DataImport $batch, array $catalog, bool $lock): array
    {
        $sellerIds = collect($catalog)
            ->flatten(1)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($sellerIds === []) {
            return [];
        }

        $activeSellerIds = [];
        foreach (array_chunk($sellerIds, 400) as $sellerIdChunk) {
            $memberships = DB::table('organization_user')
                ->where('organization_id', $batch->organization_id)
                ->whereIn('user_id', $sellerIdChunk)
                ->orderBy('user_id')
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->get(['user_id', 'role', 'is_active']);
            $activeSellerIds = array_merge($activeSellerIds, $memberships
                ->filter(static fn (object $membership): bool => $membership->role === 'salesperson' && (bool) $membership->is_active)
                ->map(static fn (object $membership): int => (int) $membership->user_id)
                ->all());
        }

        foreach ($catalog as $email => $sellers) {
            $catalog[$email] = array_values(array_filter(
                $sellers,
                static fn (User $seller): bool => in_array((int) $seller->id, $activeSellerIds, true),
            ));
        }

        return $catalog;
    }

    /**
     * @param  EloquentCollection<int, Company>  $companies
     * @param  array<string, list<User>>  $sellers
     * @return array<string, true>
     */
    private function teamMembershipCatalog(DataImport $batch, User $actor, EloquentCollection $companies, array $sellers, bool $lock): array
    {
        $teamIds = $companies->pluck('team_id')->filter()->unique()->map(fn ($id): int => (int) $id)->all();
        if ($teamIds === []) {
            return [];
        }

        Team::query()
            ->where('organization_id', $batch->organization_id)
            ->whereIn('id', $teamIds)
            ->where('status', 'active')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id']);

        $userIds = collect($sellers)->flatten(1)->pluck('id')->map(fn ($id): int => (int) $id)->push((int) $actor->id)->unique()->all();
        $memberships = DB::table('team_memberships')
            ->join('teams', function ($join): void {
                $join->on('teams.id', '=', 'team_memberships.team_id')
                    ->on('teams.organization_id', '=', 'team_memberships.organization_id');
            })
            ->where('team_memberships.organization_id', $batch->organization_id)
            ->whereIn('team_memberships.team_id', $teamIds)
            ->whereIn('team_memberships.user_id', $userIds)
            ->where('team_memberships.is_active', true)
            ->where('teams.status', 'active')
            ->orderBy('team_memberships.team_id')
            ->orderBy('team_memberships.user_id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['team_memberships.team_id', 'team_memberships.user_id', 'team_memberships.role']);

        $membershipSet = [];
        foreach ($memberships as $membership) {
            $membershipSet[$membership->team_id.':'.$membership->user_id.':'.$membership->role] = true;
        }

        return $membershipSet;
    }

    /**
     * @param  EloquentCollection<int, Company>  $catalog
     * @param  array<string, string|null>  $attributes
     */
    private function resolveCompany(EloquentCollection $catalog, array $attributes): ?Company
    {
        $taxId = (string) ($attributes['company_tax_id'] ?? '');
        $name = $this->normalize((string) ($attributes['company_name'] ?? ''));
        $matches = $catalog->filter(function (Company $company) use ($taxId, $name): bool {
            $taxMatches = $taxId !== '' && $this->digits((string) $company->tax_id) === $taxId;
            $nameMatches = $name !== '' && in_array($name, [
                $this->normalize((string) $company->name),
                $this->normalize((string) $company->trade_name),
            ], true);

            return $taxMatches || $nameMatches;
        });

        if ($taxId !== '' && $name !== '') {
            $byTax = $catalog->filter(fn (Company $company): bool => $this->digits((string) $company->tax_id) === $taxId);
            $byName = $catalog->filter(fn (Company $company): bool => in_array($name, [
                $this->normalize((string) $company->name),
                $this->normalize((string) $company->trade_name),
            ], true));

            if ($byTax->count() !== 1 || $byName->count() !== 1 || $byTax->first()?->id !== $byName->first()?->id) {
                return null;
            }
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  array<string, true>  $memberships
     */
    private function sellerIsAuthorized(DataImport $batch, User $actor, ?Company $company, User $seller, array $memberships): bool
    {
        if ($company === null) {
            return false;
        }

        $actorRole = $actor->roleIn($batch->organization_id);
        if ($actorRole === 'salesperson' && $seller->id !== $actor->id) {
            return false;
        }

        if ($actorRole === 'manager' && ($company->team_id === null
            || ! isset($memberships[$company->team_id.':'.$actor->id.':manager']))) {
            return false;
        }

        if ($company->team_id !== null && ! isset($memberships[$company->team_id.':'.$seller->id.':salesperson'])) {
            return false;
        }

        return true;
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

    private function normalizeAmount(string $amount): string
    {
        $normalized = trim(str_replace(["\u{00A0}", ' '], '', $amount));
        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return $normalized;
    }

    private function canonicalAmount(string $amount): string
    {
        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $integer = ltrim($integer, '0');

        return ($integer === '' ? '0' : $integer).'.'.str_pad($fraction, 2, '0');
    }

    private function parseSoldAt(string $value): ?CarbonImmutable
    {
        $timezone = new DateTimeZone(app(TenantContext::class)->organization()->timezone);
        $formats = [
            ['!Y-m-d H:i:s', $timezone],
            ['!Y-m-d H:i', $timezone],
            ['!Y-m-d', $timezone],
            ['!Y-m-d\\TH:i:s', $timezone],
            ['!Y-m-d\\TH:i', $timezone],
            ['!Y-m-d\\TH:i:sP', $timezone],
            ['!Y-m-d\\TH:iP', $timezone],
            ['!Y-m-d\\TH:i:s\\Z', new DateTimeZone('UTC')],
            ['!Y-m-d\\TH:i\\Z', new DateTimeZone('UTC')],
        ];

        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.(\d+)(Z|[+-]\d{2}:\d{2})?$/', $value, $parts) === 1) {
            $fraction = substr(str_pad($parts[2], 6, '0'), 0, 6);
            $offset = $parts[3] ?? '';
            $format = '!Y-m-d\\TH:i:s.u';
            $dateTimezone = $timezone;

            if ($offset !== '') {
                $format .= 'P';
                $value = $parts[1].'.'.$fraction.($offset === 'Z' ? '+00:00' : $offset);
                $dateTimezone = $offset === 'Z' ? new DateTimeZone('UTC') : $timezone;
            } else {
                $value = $parts[1].'.'.$fraction;
            }

            array_unshift($formats, [$format, $dateTimezone]);
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $parts) === 1
            && (int) $parts[1] > 12
            && (int) $parts[2] >= 1
            && (int) $parts[2] <= 12) {
            $formats[] = ['!d/m/Y', $timezone];
        }

        foreach ($formats as [$format, $dateTimezone]) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $dateTimezone);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }

            return CarbonImmutable::instance($date)->utc();
        }

        return null;
    }

    private function isAmbiguousSlashDate(string $value): bool
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/\d{4}$/', $value, $parts) !== 1) {
            return false;
        }

        return (int) $parts[1] >= 1 && (int) $parts[1] <= 12
            && (int) $parts[2] >= 1 && (int) $parts[2] <= 12;
    }

    private function formatCnpj(string $taxId): string
    {
        if (strlen($taxId) !== 14) {
            return $taxId;
        }

        return substr($taxId, 0, 2).'.'.substr($taxId, 2, 3).'.'.substr($taxId, 5, 3).'/'.substr($taxId, 8, 4).'-'.substr($taxId, 12, 2);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    /** @param list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, detail?: string}>, can_create?: bool, can_update?: bool, duplicate_source?: 'file'|'database'}> $errors
     * @param  array{candidates?: list<array{id: int, name: string, detail?: string}>, can_create?: bool, can_update?: bool, duplicate_source?: 'file'|'database'}  $extra
     */
    private function appendError(array &$errors, int $line, string $type, string $message, array $extra = []): void
    {
        if (count($errors) < 100) {
            $errors[] = ['line' => $line, 'type' => $type, 'message' => $message, ...$extra];
        }
    }
}
