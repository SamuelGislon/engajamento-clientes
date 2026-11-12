<?php

namespace App\Http\Controllers;

use App\Domain\Teams\CommercialReportService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Activity;
use App\Models\CommercialAlertOccurrence;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerScore;
use App\Models\JourneyEnrollment;
use App\Models\Sale;
use App\Models\Task;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $organization = app(TenantContext::class)->organization();
        $role = $user->roleIn(app(TenantContext::class)->id());
        $baseCompanies = Company::query()->visibleTo($user);
        $timezone = app(TenantContext::class)->organization()->timezone;
        $localTodayStart = now($timezone)->startOfDay();
        $todayStart = $localTodayStart->utc();
        $tomorrowStart = $localTodayStart->addDay()->utc();
        $periodStart = now($timezone)->startOfMonth()->utc();
        $periodEnd = now($timezone)->startOfMonth()->addMonth()->utc();
        $openTasks = Task::query()->visibleTo($user)->whereIn('status', ['pending', 'in_progress', 'postponed']);
        $activeJourneys = JourneyEnrollment::query()->visibleTo($user)->whereIn('status', ['active', 'paused']);

        if ($user->roleIn(app(TenantContext::class)->id()) === 'salesperson') {
            $activeJourneys->where('assigned_to', $user->id)
                ->whereHas('company', fn ($companies) => $companies->where('owner_user_id', $user->id));
        }

        $visibleSales = Sale::query()
            ->visibleTo($user)
            ->where('status', 'confirmed');
        $periodSales = (clone $visibleSales)
            ->where('sold_at', '>=', $periodStart)
            ->where('sold_at', '<', $periodEnd);
        $periodSalesTotal = (string) (clone $periodSales)->sum('amount');
        $periodSalesCount = (clone $periodSales)->count();
        $tierCounts = CustomerScore::query()
            ->whereNotNull('tier')
            ->whereHas('company', fn ($companies) => $companies->visibleTo($user))
            ->selectRaw('tier, COUNT(*) as aggregate')
            ->groupBy('tier')
            ->pluck('aggregate', 'tier');
        $commercialAttention = CommercialAlertOccurrence::query()
            ->with([
                'company' => fn ($companies) => $companies
                    ->withMax(['sales as last_purchase_at' => fn ($sales) => $sales->where('status', 'confirmed')], 'sold_at')
                    ->withMax('activities', 'occurred_at'),
                'company.owner:id,name',
                'company.customerScore',
                'task',
                'recommendedContact:id,name,job_title',
            ])
            ->where('status', 'active')
            ->whereIn('type', ['commercial_inactivity', 'relationship_inactivity'])
            ->whereHas('company', fn ($companies) => $companies->visibleTo($user))
            ->orderByRaw("CASE WHEN type = 'commercial_inactivity' THEN 0 ELSE 1 END")
            ->orderBy('first_detected_at')
            ->limit(8)
            ->get()
            ->map(function (CommercialAlertOccurrence $occurrence) use ($user): array {
                $company = $occurrence->company;

                return [
                    'id' => $occurrence->id,
                    'type' => $occurrence->type,
                    'reason' => $occurrence->reason,
                    'first_detected_at' => $occurrence->first_detected_at,
                    'company' => ['id' => $company->id, 'name' => $company->name],
                    'owner' => $company->owner === null
                        ? null
                        : ['id' => $company->owner->id, 'name' => $company->owner->name],
                    'tier' => $company->customerScore?->tier,
                    'last_purchase_at' => $company->last_purchase_at,
                    'last_contact_at' => $company->activities_max_occurred_at,
                    'suggested_action' => $occurrence->task !== null
                        ? $occurrence->task->title
                        : ($occurrence->type === 'commercial_inactivity'
                            ? 'Revisar novas oportunidades e retomar o contato.'
                            : 'Registrar uma interação comercial com a empresa.'),
                    'recommended_contact' => $occurrence->recommendedContact === null
                        ? null
                        : ['id' => $occurrence->recommendedContact->id, 'name' => $occurrence->recommendedContact->name],
                    'task' => $occurrence->task === null
                        ? null
                        : [
                            'id' => $occurrence->task->id,
                            'title' => $occurrence->task->title,
                            'type' => $occurrence->task->type,
                            'can_view' => $user->can('view', $occurrence->task),
                        ],
                ];
            });

        $activeOccurrenceCount = fn (string $type): int => CommercialAlertOccurrence::query()
            ->where('type', $type)
            ->where('status', 'active')
            ->whereHas('company', fn ($companies) => $companies->visibleTo($user))
            ->distinct('company_id')
            ->count('company_id');

        $managerReport = $role === 'manager' && $organization !== null
            ? app(CommercialReportService::class)->generate($user, $organization, [], 5, false)
            : null;

        return Inertia::render('dashboard', [
            'metrics' => [
                'companies' => (clone $baseCompanies)->count(),
                'prospects' => (clone $baseCompanies)->where('relationship_status', 'prospect')->count(),
                'customers' => (clone $baseCompanies)->where('relationship_status', 'customer')->count(),
                'contacts' => Contact::query()->visibleTo($user)->count(),
                'tasks_due_today' => (clone $openTasks)->where('due_at', '>=', $todayStart)->where('due_at', '<', $tomorrowStart)->count(),
                'overdue_tasks' => (clone $openTasks)->where('due_at', '<', $todayStart)->count(),
                'active_journeys' => $activeJourneys->count(),
            ],
            'commercialMetrics' => [
                'customers_with_purchases' => (clone $baseCompanies)->whereHas('sales', fn ($sales) => $sales->where('status', 'confirmed'))->count(),
                'prospects_without_purchases' => (clone $baseCompanies)
                    ->where('relationship_status', 'prospect')
                    ->whereDoesntHave('sales', fn ($sales) => $sales->where('status', 'confirmed'))
                    ->count(),
                'commercially_inactive' => $activeOccurrenceCount('commercial_inactivity'),
                'without_recent_contact' => $activeOccurrenceCount('relationship_inactivity'),
                'reactivated_this_period' => CommercialAlertOccurrence::query()
                    ->where('type', 'commercial_inactivity')
                    ->where('status', 'resolved')
                    ->where('resolution_reason', 'Nova compra confirmada.')
                    ->where('resolved_at', '>=', $periodStart)
                    ->where('resolved_at', '<', $periodEnd)
                    ->whereHas('company', fn ($companies) => $companies->visibleTo($user))
                    ->distinct('company_id')
                    ->count('company_id'),
                'confirmed_sales_period' => $periodSalesCount,
                'revenue_period' => bcadd($periodSalesTotal, '0', 2),
                'average_ticket_period' => $periodSalesCount > 0 ? bcdiv($periodSalesTotal, (string) $periodSalesCount, 2) : null,
                'tier_distribution' => [
                    'A' => (int) ($tierCounts['A'] ?? 0),
                    'B' => (int) ($tierCounts['B'] ?? 0),
                    'C' => (int) ($tierCounts['C'] ?? 0),
                    'D' => (int) ($tierCounts['D'] ?? 0),
                ],
                'pending_reactivation_tasks' => (clone $openTasks)->where('type', 'reactivation')->count(),
            ],
            'commercialAttention' => $commercialAttention,
            'commercialTimezone' => $timezone,
            'managerReport' => $managerReport === null ? null : [
                'summary' => $managerReport['summary'],
                'sellerPerformance' => $managerReport['sellerPerformance'],
            ],
            'recentActivities' => Activity::query()
                ->visibleTo($user)
                ->with(['company:id,name', 'contact:id,name', 'user:id,name'])
                ->latest('occurred_at')
                ->limit(6)
                ->get(),
            'attentionCompanies' => (clone $baseCompanies)
                ->withMax('activities', 'occurred_at')
                ->orderByRaw('"activities_max_occurred_at" ASC NULLS FIRST')
                ->limit(5)
                ->get(['id', 'name', 'relationship_status', 'owner_user_id']),
        ]);
    }
}
