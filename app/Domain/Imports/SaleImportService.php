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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaleImportService
{
    /**
     * @param  list<list<string>>  $rows
     * @param  list<int>  $malformedRows
     * @return array{
     *     totalRows: int,
     *     validRows: list<array{line: int, external_id: string|null, company_id: int, seller_id: int, description: string, amount: string, currency: string, sold_at: string, status: string, notes: string|null}>,
     *     invalidRows: int,
     *     duplicateRows: int,
     *     errors: list<array{line: int, type: string, message: string}>
     * }
     */
    public function analyze(DataImport $batch, User $actor, array $rows, int $totalRows, bool $lockDestination = false, array $malformedRows = []): array
    {
        $this->assertImporter($batch, $actor, $lockDestination);
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

        $companyCatalog = $this->companyCatalog($batch, $actor, $preparedRows, $lockDestination);
        $sellerCatalog = $this->sellerCatalog($batch, $preparedRows);
        $teamMemberships = $this->teamMembershipCatalog($batch, $actor, $companyCatalog, $sellerCatalog, $lockDestination);
        $sellerCatalog = $this->lockSellerMemberships($batch, $sellerCatalog, $lockDestination);
        $externalIds = array_values(array_unique(array_filter(array_map(
            static fn (array $prepared): ?string => $prepared['attributes']['external_id'],
            $preparedRows,
        ), static fn (?string $externalId): bool => $externalId !== null)));
        $existingSales = new EloquentCollection;

        foreach (array_chunk($externalIds, 500) as $externalIdChunk) {
            $existingSales = $existingSales->merge(Sale::query()
                ->where('organization_id', $batch->organization_id)
                ->whereIn('external_id', $externalIdChunk)
                ->when($lockDestination, fn ($sales) => $sales->lockForUpdate())
                ->get(['id', 'external_id']));
        }

        $visibleExistingSaleIds = Sale::query()
            ->where('organization_id', $batch->organization_id)
            ->visibleTo($actor)
            ->whereIn('id', $existingSales->modelKeys())
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $existingExternalIds = [];

        foreach ($existingSales as $existingSale) {
            $existingExternalIds[$existingSale->external_id] = in_array((int) $existingSale->id, $visibleExistingSaleIds, true);
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
            if ($externalId !== null && isset($existingExternalIds[$externalId])) {
                if (! $existingExternalIds[$externalId]) {
                    $invalidRows++;
                    $this->appendError($errors, $line, 'invalid', 'A linha não pode ser importada no escopo de acesso atual.');

                    continue;
                }

                $duplicateRows++;
                $this->appendError($errors, $line, 'duplicate', 'O identificador externo já existe nesta organização; a linha será ignorada.');

                continue;
            }

            if ($externalId !== null && isset($seenExternalIds[$externalId])) {
                $duplicateRows++;
                $this->appendError($errors, $line, 'duplicate', 'Identificador externo repetido no arquivo; a linha será ignorada.');

                continue;
            }

            if ($externalId !== null) {
                $seenExternalIds[$externalId] = true;
            }

            $validRows[] = [
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
     * @param  list<array{line: int, external_id: string|null, company_id: int, seller_id: int, description: string, amount: string, currency: string, sold_at: string, status: string, notes: string|null}>  $validRows
     * @return array{createdRows: int, updatedRows: int, duplicateRows: int, invalidRows: int, errors: list<array{line: int, type: string, message: string}>}
     */
    public function import(
        DataImport $batch,
        User $actor,
        array $validRows,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
        JourneyService $journeys,
    ): array {
        $createdRows = 0;
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

            $isFirstPurchase = $row['status'] === 'confirmed'
                && $company->relationship_status === 'prospect'
                && ! $company->sales()->where('status', 'confirmed')->exists();

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
            $sale = $row['external_id'] === null
                ? Sale::query()->create($saleAttributes)
                : Sale::query()->firstOrCreate(
                    ['organization_id' => $batch->organization_id, 'external_id' => $row['external_id']],
                    $saleAttributes,
                );

            if (! $sale->wasRecentlyCreated) {
                $canSeeExistingSale = Sale::query()
                    ->where('organization_id', $batch->organization_id)
                    ->visibleTo($actor)
                    ->whereKey($sale->id)
                    ->exists();

                if ($canSeeExistingSale) {
                    $duplicateRows++;
                    $this->appendError($errors, $row['line'], 'duplicate', 'O identificador externo foi importado por outro processo; a linha foi ignorada.');
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
            $createdRows++;
            unset($sale);
        }

        return [
            'createdRows' => $createdRows,
            'updatedRows' => 0,
            'duplicateRows' => $duplicateRows,
            'invalidRows' => $invalidRows,
            'errors' => $errors,
        ];
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

    /** @param list<array{line: int, type: string, message: string}> $errors */
    private function appendError(array &$errors, int $line, string $type, string $message): void
    {
        if (count($errors) < 100) {
            $errors[] = ['line' => $line, 'type' => $type, 'message' => $message];
        }
    }
}
