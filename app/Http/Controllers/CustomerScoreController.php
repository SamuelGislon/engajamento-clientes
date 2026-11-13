<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\UpdateCustomerScoreRequest;
use App\Models\Company;
use App\Models\CustomerScore;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerScoreController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Company::class);
        $organizationId = app(TenantContext::class)->id();
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $commercialSettings = app(CommercialSettingsService::class)->forOrganization($organization);
        $tiersByKey = collect($commercialSettings->tiers)->keyBy('key');
        $filters = $request->validate([
            'tier' => ['nullable', Rule::in(['A', 'B', 'C', 'D', 'incomplete'])],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('organization_user', 'user_id')
                ->where('organization_id', $organizationId)->where('role', 'salesperson')->where('is_active', true)],
            'segment' => ['nullable', 'string', 'max:100'],
            'relationship_status' => ['nullable', Rule::in(['prospect', 'customer', 'inactive', 'partner'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $user = $request->user();
        $timezone = app(TenantContext::class)->organization()->timezone;
        $twelveMonthsAgo = CarbonImmutable::now('UTC')->subYear();
        $salesInLastYear = fn ($sales) => $sales->where('status', 'confirmed')->where('sold_at', '>=', $twelveMonthsAgo);
        $scoreOrder = CustomerScore::query()
            ->select('total_score')
            ->whereColumn('company_id', 'companies.id')
            ->limit(1);
        $companies = Company::query()
            ->visibleTo($user)
            ->with(['owner:id,name', 'customerScore'])
            ->withSum(['sales as volume_12_months' => $salesInLastYear], 'amount')
            ->withCount(['sales as purchase_count_12_months' => $salesInLastYear])
            ->withMax(['sales as last_purchase_at' => fn ($sales) => $sales->where('status', 'confirmed')], 'sold_at')
            ->withMax('activities', 'occurred_at')
            ->when(($filters['tier'] ?? null) === 'incomplete', fn ($query) => $query->whereDoesntHave('customerScore', fn ($scores) => $scores->where('is_complete', true)))
            ->when(isset($filters['tier']) && $filters['tier'] !== 'incomplete', fn ($query) => $query->whereHas('customerScore', fn ($scores) => $scores->where('tier', $filters['tier'])))
            ->when($filters['owner_user_id'] ?? null, fn ($query, int $ownerId) => $query->where('owner_user_id', $ownerId))
            ->when($filters['segment'] ?? null, fn ($query, string $segment) => $query->where('segment', $segment))
            ->when($filters['relationship_status'] ?? null, fn ($query, string $status) => $query->where('relationship_status', $status))
            ->when($filters['from'] ?? null, fn ($query, string $date) => $query->whereHas('sales', fn ($sales) => $sales
                ->where('status', 'confirmed')
                ->where('sold_at', '>=', CarbonImmutable::parse($date, $timezone)->startOfDay()->utc())))
            ->when($filters['to'] ?? null, fn ($query, string $date) => $query->whereHas('sales', fn ($sales) => $sales
                ->where('status', 'confirmed')
                ->where('sold_at', '<', CarbonImmutable::parse($date, $timezone)->addDay()->startOfDay()->utc())))
            ->orderByDesc($scoreOrder)
            ->orderBy('companies.name')
            ->paginate(15)
            ->withQueryString();
        $companies->getCollection()->each(function (Company $company) use ($tiersByKey): void {
            $company->setAttribute('volume_12_months', bcadd($this->numericString($company->volume_12_months ?? '0'), '0', 2));

            if ($company->customerScore !== null) {
                $tier = $tiersByKey->get($company->customerScore->tier);
                $company->customerScore->setAttribute('tier_name', $tier['name'] ?? null);
                $company->customerScore->setAttribute('tier_color', $tier['color'] ?? null);
            }
        });

        return Inertia::render('commercial/classification', [
            'companies' => $companies,
            'filters' => $filters,
            'owners' => app(TenantContext::class)->organization()?->users()
                ->where('organization_user.role', 'salesperson')
                ->where('organization_user.is_active', true)
                ->when($user->roleIn($organizationId) === 'salesperson', fn ($users) => $users->where('users.id', $user->id))
                ->when($user->roleIn($organizationId) === 'manager', fn ($users) => $users->whereIn(
                    'users.id',
                    app(CommercialAccessScope::class)->managedSellerIds($user, $organizationId),
                ))
                ->orderBy('name')
                ->get(['users.id', 'users.name']) ?? collect(),
            'segments' => Company::query()->visibleTo($user)->whereNotNull('segment')->distinct()->orderBy('segment')->pluck('segment'),
            'tiers' => $commercialSettings->tiers,
            'scoringWeights' => $commercialSettings->scoring_weights,
        ]);
    }

    public function update(UpdateCustomerScoreRequest $request, Company $company, CustomerScoreService $scores): RedirectResponse
    {
        $scores->updateManualCriteria($company, $request->validated(), 'Critérios manuais atualizados');

        return redirect()->route('companies.show', $company)->with('success', 'Classificação comercial recalculada.');
    }

    /** @return numeric-string */
    private function numericString(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new \UnexpectedValueException('O agregado de compras retornou um valor inválido.');
        }

        return (string) $value;
    }
}
