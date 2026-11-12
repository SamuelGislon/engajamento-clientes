<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\IndexSalesRequest;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Models\Company;
use App\Models\JourneyEnrollment;
use App\Models\JourneyTemplate;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(IndexSalesRequest $request): Response
    {
        $filters = $request->validated();
        $user = $request->user();
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $timezone = $organization->timezone;
        $sales = Sale::query()
            ->visibleTo($user)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['company_id'] ?? null, fn ($query, int $companyId) => $query->where('company_id', $companyId))
            ->when($filters['seller_id'] ?? null, fn ($query, int $sellerId) => $query->where('seller_id', $sellerId))
            ->when($filters['from'] ?? null, fn ($query, string $date) => $query->where('sold_at', '>=', CarbonImmutable::parse($date, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, string $date) => $query->where('sold_at', '<', CarbonImmutable::parse($date, $timezone)->addDay()->startOfDay()->utc()))
            ->with(['company:id,name,owner_user_id', 'seller:id,name'])
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();
        $sales->getCollection()->each(function (Sale $sale) use ($user): void {
            $sale->setAttribute('can_manage', $user->can('update', $sale));
        });
        $companies = Company::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get(['id', 'name']);
        $sellers = $organization->users()
            ->where('organization_user.role', 'salesperson')
            ->where('organization_user.is_active', true)
            ->when($user->roleIn($organization->id) === 'salesperson', fn ($query) => $query->where('users.id', $user->id))
            ->when($user->roleIn($organization->id) === 'manager', fn ($query) => $query->whereIn(
                'users.id',
                app(CommercialAccessScope::class)->managedSellerIds($user, $organization->id),
            ))
            ->orderBy('name')
            ->get(['users.id', 'users.name']);

        return Inertia::render('sales/index', [
            'sales' => $sales,
            'salesTimezone' => $timezone,
            'filters' => $filters,
            'companies' => $companies,
            'sellers' => $sellers,
        ]);
    }

    public function store(
        StoreSaleRequest $request,
        Company $company,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
        JourneyService $journeys,
        CommercialSettingsService $settingsService,
    ): RedirectResponse {
        $attributes = $request->validated();
        $attributes['sold_at'] = $this->normalizeSoldAt($attributes['sold_at']);
        $attributes['created_by'] = $request->user()->id;
        $attributes['status'] = 'confirmed';

        DB::transaction(function () use ($attributes, $company, $scores, $inactivity, $journeys, $settingsService, $request): void {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            $attributes['team_id_at_sale'] = $lockedCompany->team_id;
            $isFirstPurchase = $lockedCompany->relationship_status === 'prospect'
                && ! $lockedCompany->sales()->where('status', 'confirmed')->exists();
            $lockedCompany->sales()->create($attributes);
            $inactivity->resolveCommercialForCompany($lockedCompany);

            if ($isFirstPurchase) {
                $lockedCompany->update(['relationship_status' => 'customer']);

                JourneyEnrollment::query()
                    ->where('company_id', $lockedCompany->id)
                    ->whereIn('status', ['active', 'paused'])
                    ->whereHas('template', fn ($templates) => $templates->where('type', 'prospecting'))
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (JourneyEnrollment $enrollment) => $journeys->cancel($enrollment));

                $organization = app(TenantContext::class)->organization();
                abort_if($organization === null, 404);
                $setting = $settingsService->forOrganization($organization);
                $postSaleTemplate = $setting->post_sale_journey_template_id === null
                    ? null
                    : JourneyTemplate::query()
                        ->whereKey($setting->post_sale_journey_template_id)
                        ->where('type', 'post_sale')
                        ->where('trigger_type', 'first_sale')
                        ->where('is_active', true)
                        ->first();

                if ($postSaleTemplate !== null) {
                    $journeys->enroll($lockedCompany, $postSaleTemplate, $request->user());
                }
            }

            $scores->recalculateForCompany($lockedCompany, 'Venda confirmada');
        });

        return redirect()->route('companies.show', $company)->with('success', 'Venda registrada.');
    }

    public function edit(Sale $sale): Response
    {
        Gate::authorize('update', $sale);
        $sale->load(['company:id,name', 'seller:id,name']);
        $timezone = app(TenantContext::class)->organization()->timezone;
        $sale->setAttribute('sold_at_local', $sale->sold_at->setTimezone($timezone)->format('Y-m-d\\TH:i'));

        return Inertia::render('sales/edit', ['sale' => $sale, 'salesTimezone' => $timezone]);
    }

    public function update(
        UpdateSaleRequest $request,
        Sale $sale,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
    ): RedirectResponse {
        $attributes = $request->validated();

        if (isset($attributes['sold_at'])) {
            $attributes['sold_at'] = $this->normalizeSoldAt($attributes['sold_at']);
        }

        DB::transaction(function () use ($attributes, $sale, $scores, $inactivity): void {
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            Gate::authorize('update', $lockedSale);
            $lockedSale->update($attributes);
            $inactivity->refreshCommercialForCompany($lockedSale->company);
            $scores->recalculateForCompany($lockedSale->company, 'Venda atualizada');
        });

        return redirect()->route('companies.show', $sale->company_id)->with('success', 'Venda atualizada.');
    }

    public function cancel(
        Sale $sale,
        Request $request,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
    ): RedirectResponse {
        DB::transaction(function () use ($sale, $request, $scores, $inactivity): void {
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            Gate::authorize('cancel', $lockedSale);
            $lockedSale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
            ]);
            $inactivity->refreshCommercialForCompany($lockedSale->company);
            $scores->recalculateForCompany($lockedSale->company, 'Venda cancelada');
        });

        return redirect()->route('companies.show', $sale->company_id)->with('success', 'Venda cancelada; o histórico foi preservado.');
    }

    private function normalizeSoldAt(mixed $value): CarbonImmutable
    {
        $timezone = app(TenantContext::class)->organization()->timezone;

        return CarbonImmutable::parse((string) $value, $timezone)->utc();
    }
}
