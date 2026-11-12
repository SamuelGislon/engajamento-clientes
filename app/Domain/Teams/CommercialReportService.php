<?php

namespace App\Domain\Teams;

use App\Models\CommercialAlertOccurrence;
use App\Models\Company;
use App\Models\JourneyEnrollment;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CommercialReportService
{
    /**
     * @param  array{from?: string, to?: string, team_id?: int|string|null, seller_id?: int|string|null, status?: string|null, type?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function generate(User $user, Organization $organization, array $filters, int $sellerPageSize = 30, bool $includeDetailData = true): array
    {
        $organizationId = $organization->id;
        $timezone = $organization->timezone;
        $nowLocal = CarbonImmutable::now($timezone);
        $fromLocal = CarbonImmutable::parse($filters['from'] ?? $nowLocal->startOfMonth()->toDateString(), $timezone)->startOfDay();
        $toLocal = CarbonImmutable::parse($filters['to'] ?? $nowLocal->toDateString(), $timezone)->startOfDay()->addDay();
        $fromUtc = $fromLocal->utc();
        $toUtc = $toLocal->utc();
        $nowUtc = $nowLocal->utc();
        $teamId = isset($filters['team_id']) ? (int) $filters['team_id'] : null;
        $sellerId = isset($filters['seller_id']) ? (int) $filters['seller_id'] : null;
        $taskStatus = $filters['status'] ?? null;
        $taskType = $filters['type'] ?? null;
        $role = $user->roleIn($organizationId);

        $companyQuery = Company::query()->visibleTo($user);
        $this->filterCompanies($companyQuery, $teamId, $sellerId);
        $taskQuery = Task::query()->visibleTo($user);
        $this->filterTasks($taskQuery, $organizationId, $teamId, $sellerId);
        $saleQuery = Sale::query()->visibleTo($user)
            ->where('status', 'confirmed')
            ->where('sold_at', '>=', $fromUtc)
            ->where('sold_at', '<', $toUtc);
        $this->filterSales($saleQuery, $teamId, $sellerId);
        $enrollmentQuery = JourneyEnrollment::query()->visibleTo($user)->whereIn('status', ['active', 'paused']);
        $this->filterEnrollments($enrollmentQuery, $teamId, $sellerId);

        $periodTasks = (clone $taskQuery)
            ->where('due_at', '>=', $fromUtc)
            ->where('due_at', '<', $toUtc)
            ->when($taskStatus !== null, fn ($tasks) => $tasks->where('status', $taskStatus))
            ->when($taskType !== null, fn ($tasks) => $tasks->where('type', $taskType));
        $eligibleTasks = (clone $periodTasks)->where('due_at', '<=', $nowUtc)->where('status', '!=', 'cancelled');
        $completedOnTime = (clone $eligibleTasks)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereColumn('completed_at', '<=', 'due_at')
            ->count();
        $completedLate = (clone $eligibleTasks)
            ->where('status', 'completed')
            ->where(fn (Builder $query) => $query->whereColumn('completed_at', '>', 'due_at')->orWhereNull('completed_at'))
            ->count();
        $eligibleCount = $eligibleTasks->count();
        $salesCount = (clone $saleQuery)->count();
        $revenue = (string) (clone $saleQuery)->sum('amount');

        $summary = [
            'companies' => (clone $companyQuery)->count(),
            'prospects' => (clone $companyQuery)->where('relationship_status', 'prospect')->count(),
            'customers' => (clone $companyQuery)->where('relationship_status', 'customer')->count(),
            'unassigned_companies' => (clone $companyQuery)->whereNull('owner_user_id')->count(),
            'commercially_inactive' => (clone $companyQuery)->whereHas('commercialAlertOccurrences', fn ($alerts) => $alerts
                ->where('type', 'commercial_inactivity')->where('status', 'active'))->count(),
            'without_recent_contact' => (clone $companyQuery)->whereHas('commercialAlertOccurrences', fn ($alerts) => $alerts
                ->where('type', 'relationship_inactivity')->where('status', 'active'))->count(),
            'reactivated' => CommercialAlertOccurrence::query()
                ->where('type', 'commercial_inactivity')
                ->where('status', 'resolved')
                ->where('resolution_reason', 'Nova compra confirmada.')
                ->where('resolved_at', '>=', $fromUtc)
                ->where('resolved_at', '<', $toUtc)
                ->whereHas('company', function ($companies) use ($user, $teamId, $sellerId): void {
                    $companies->visibleTo($user)
                        ->when($teamId !== null, fn ($query) => $query->where('team_id', $teamId))
                        ->when($sellerId !== null, fn ($query) => $query->where('owner_user_id', $sellerId));
                })
                ->distinct('company_id')
                ->count('company_id'),
            'tier_distribution' => [
                'A' => (clone $companyQuery)->whereHas('customerScore', fn ($scores) => $scores->where('tier', 'A'))->count(),
                'B' => (clone $companyQuery)->whereHas('customerScore', fn ($scores) => $scores->where('tier', 'B'))->count(),
                'C' => (clone $companyQuery)->whereHas('customerScore', fn ($scores) => $scores->where('tier', 'C'))->count(),
                'D' => (clone $companyQuery)->whereHas('customerScore', fn ($scores) => $scores->where('tier', 'D'))->count(),
            ],
            'active_journeys' => (clone $enrollmentQuery)->count(),
            'tasks' => [
                'eligible' => $eligibleCount,
                'completed_on_time' => $completedOnTime,
                'completed_late' => $completedLate,
                'pending' => (clone $periodTasks)->whereIn('status', ['pending', 'in_progress', 'postponed'])->where('due_at', '>=', $nowUtc)->count(),
                'overdue' => (clone $periodTasks)->whereIn('status', ['pending', 'in_progress', 'postponed'])->where('due_at', '<', $nowUtc)->count(),
                'cancelled' => (clone $periodTasks)->where('status', 'cancelled')->count(),
                'completion_rate' => $eligibleCount > 0 ? round(($completedOnTime + $completedLate) / $eligibleCount * 100, 1) : 0,
            ],
            'sales' => [
                'count' => $salesCount,
                'revenue' => bcadd($revenue, '0', 2),
                'average_ticket' => $salesCount > 0 ? bcdiv($revenue, (string) $salesCount, 2) : null,
                'buyers' => (clone $saleQuery)->distinct('company_id')->count('company_id'),
            ],
        ];

        $visibleTeams = $organization->teams()->where('status', 'active');
        if ($role === 'manager') {
            $visibleTeams->whereIn('teams.id', app(CommercialAccessScope::class)->managedTeamIds($user, $organizationId));
        } elseif ($role === 'salesperson') {
            $visibleTeams->whereHas('users', fn ($users) => $users
                ->where('users.id', $user->id)
                ->where('team_memberships.role', 'salesperson')
                ->where('team_memberships.is_active', true));
        }
        $teamOptions = $visibleTeams->orderBy('name')->get(['teams.id', 'teams.name']);

        $sellerQuery = $organization->users()
            ->where('organization_user.role', 'salesperson')
            ->where('organization_user.is_active', true);
        if ($role === 'manager') {
            $sellerQuery->whereIn('users.id', app(CommercialAccessScope::class)->managedSellerIds($user, $organizationId));
        } elseif ($role === 'salesperson') {
            $sellerQuery->where('users.id', $user->id);
        }
        if ($teamId !== null) {
            $sellerQuery->whereIn('users.id', DB::table('team_memberships')
                ->select('user_id')
                ->where('organization_id', $organizationId)
                ->where('team_id', $teamId)
                ->where('role', 'salesperson')
                ->where('is_active', true));
        }
        if ($sellerId !== null) {
            $sellerQuery->where('users.id', $sellerId);
        }
        $sellerOptions = (clone $sellerQuery)->reorder()->limit(200)->get(['users.id', 'users.name']);
        $sellerPage = (clone $sellerQuery)->orderBy('name')->paginate($sellerPageSize)->withQueryString();
        $sellerIds = $sellerPage->getCollection()->modelKeys();

        $companiesBySeller = (clone $companyQuery)
            ->whereIn('owner_user_id', $sellerIds)
            ->selectRaw('owner_user_id, COUNT(*) as companies_count')
            ->selectRaw("SUM(CASE WHEN relationship_status = 'prospect' THEN 1 ELSE 0 END) as prospects_count")
            ->groupBy('owner_user_id')
            ->toBase()
            ->get()
            ->keyBy('owner_user_id');

        $sellerTaskMetrics = (clone $periodTasks)
            ->whereIn('assigned_to', $sellerIds)
            ->select('assigned_to')
            ->selectRaw("SUM(CASE WHEN status <> 'cancelled' AND due_at <= ? THEN 1 ELSE 0 END) as eligible", [$nowUtc])
            ->selectRaw("SUM(CASE WHEN status = 'completed' AND completed_at IS NOT NULL AND completed_at <= due_at THEN 1 ELSE 0 END) as completed_on_time")
            ->selectRaw("SUM(CASE WHEN status = 'completed' AND (completed_at > due_at OR completed_at IS NULL) THEN 1 ELSE 0 END) as completed_late")
            ->selectRaw("SUM(CASE WHEN status IN ('pending', 'in_progress', 'postponed') AND due_at >= ? THEN 1 ELSE 0 END) as pending", [$nowUtc])
            ->selectRaw("SUM(CASE WHEN status IN ('pending', 'in_progress', 'postponed') AND due_at < ? THEN 1 ELSE 0 END) as overdue", [$nowUtc])
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('assigned_to')
            ->toBase()
            ->get()
            ->keyBy('assigned_to');

        $salesBySeller = (clone $saleQuery)
            ->whereIn('seller_id', $sellerIds)
            ->selectRaw('seller_id, COUNT(*) as sales_count, SUM(amount) as revenue')
            ->groupBy('seller_id')
            ->toBase()
            ->get()
            ->keyBy('seller_id');

        $journeysBySeller = (clone $enrollmentQuery)
            ->whereIn('assigned_to', $sellerIds)
            ->selectRaw('assigned_to, COUNT(*) as journeys_count')
            ->groupBy('assigned_to')
            ->toBase()
            ->get()
            ->keyBy('assigned_to');

        $inactivityBySeller = DB::table('commercial_alert_occurrences as alerts')
            ->join('companies', function ($join) use ($organizationId): void {
                $join->on('companies.id', '=', 'alerts.company_id')
                    ->where('companies.organization_id', '=', $organizationId)
                    ->where('alerts.organization_id', '=', $organizationId);
            })
            ->whereIn('companies.owner_user_id', $sellerIds)
            ->where('alerts.status', 'active')
            ->whereIn('alerts.type', ['commercial_inactivity', 'relationship_inactivity'])
            ->when($teamId !== null, fn ($query) => $query->where('companies.team_id', $teamId))
            ->when($sellerId !== null, fn ($query) => $query->where('companies.owner_user_id', $sellerId))
            ->select('companies.owner_user_id')
            ->selectRaw("COUNT(DISTINCT CASE WHEN alerts.type = 'commercial_inactivity' THEN companies.id END) as inactive_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN alerts.type = 'relationship_inactivity' THEN companies.id END) as no_contact_count")
            ->groupBy('companies.owner_user_id')
            ->get()
            ->keyBy('owner_user_id');

        $reportingTeamExpression = 'COALESCE(sales.team_id_at_sale, report_companies.team_id)';
        $salesByTeamRows = (clone $saleQuery)
            ->leftJoin('companies as report_companies', function ($join) use ($organizationId): void {
                $join->on('report_companies.id', '=', 'sales.company_id')
                    ->on('report_companies.organization_id', '=', 'sales.organization_id')
                    ->where('report_companies.organization_id', '=', $organizationId);
            })
            ->selectRaw($reportingTeamExpression.' as reporting_team_id')
            ->selectRaw('COUNT(DISTINCT sales.id) as sales_count')
            ->selectRaw('SUM(sales.amount) as revenue')
            ->selectRaw('COUNT(DISTINCT sales.seller_id) as sellers_count')
            ->groupByRaw($reportingTeamExpression)
            ->orderByRaw($reportingTeamExpression.' IS NULL')
            ->orderByRaw($reportingTeamExpression)
            ->toBase()
            ->get();
        $teamNames = $organization->teams()
            ->whereIn('id', $salesByTeamRows->pluck('reporting_team_id')->filter()->unique())
            ->pluck('name', 'id');
        $salesByTeam = $salesByTeamRows->map(fn (object $row): array => [
            'team_id' => $row->reporting_team_id === null ? null : (int) $row->reporting_team_id,
            'team_name' => $row->reporting_team_id === null ? 'Sem equipe atribuída' : ($teamNames[$row->reporting_team_id] ?? 'Equipe'),
            'sellers_count' => (int) $row->sellers_count,
            'sales_count' => (int) $row->sales_count,
            'revenue' => $this->moneyString($row->revenue),
        ])->values();

        $sellerPerformance = $sellerPage->through(function (User $seller) use (
            $companiesBySeller,
            $sellerTaskMetrics,
            $salesBySeller,
            $journeysBySeller,
            $inactivityBySeller,
        ): array {
            $companyMetrics = $companiesBySeller->get($seller->id);
            $taskMetrics = $sellerTaskMetrics->get($seller->id);
            $salesMetrics = $salesBySeller->get($seller->id);
            $journeyMetrics = $journeysBySeller->get($seller->id);
            $inactiveMetrics = $inactivityBySeller->get($seller->id);
            $eligible = (int) ($this->metricValue($taskMetrics, 'eligible') ?? 0);
            $completed = (int) ($this->metricValue($taskMetrics, 'completed_on_time') ?? 0)
                + (int) ($this->metricValue($taskMetrics, 'completed_late') ?? 0);

            return [
                'id' => $seller->id,
                'name' => $seller->name,
                'portfolio' => (int) ($this->metricValue($companyMetrics, 'companies_count') ?? 0),
                'prospects' => (int) ($this->metricValue($companyMetrics, 'prospects_count') ?? 0),
                'commercially_inactive' => (int) ($this->metricValue($inactiveMetrics, 'inactive_count') ?? 0),
                'without_recent_contact' => (int) ($this->metricValue($inactiveMetrics, 'no_contact_count') ?? 0),
                'active_journeys' => (int) ($this->metricValue($journeyMetrics, 'journeys_count') ?? 0),
                'tasks' => [
                    'eligible' => $eligible,
                    'completed_on_time' => (int) ($this->metricValue($taskMetrics, 'completed_on_time') ?? 0),
                    'completed_late' => (int) ($this->metricValue($taskMetrics, 'completed_late') ?? 0),
                    'pending' => (int) ($this->metricValue($taskMetrics, 'pending') ?? 0),
                    'overdue' => (int) ($this->metricValue($taskMetrics, 'overdue') ?? 0),
                    'cancelled' => (int) ($this->metricValue($taskMetrics, 'cancelled') ?? 0),
                    'completion_rate' => $eligible > 0 ? round($completed / $eligible * 100, 1) : 0,
                ],
                'sales_count' => (int) ($this->metricValue($salesMetrics, 'sales_count') ?? 0),
                'revenue' => $this->moneyString($this->metricValue($salesMetrics, 'revenue')),
            ];
        });

        $overdueTasks = (clone $periodTasks)
            ->whereIn('status', ['pending', 'in_progress', 'postponed'])
            ->where('due_at', '<', $nowUtc)
            ->with(['company:id,name', 'assignee:id,name'])
            ->orderBy('due_at')
            ->limit(10)
            ->get(['id', 'company_id', 'assigned_to', 'title', 'due_at']);
        $unassignedCompanies = (clone $companyQuery)
            ->whereNull('owner_user_id')
            ->with('team:id,name')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'team_id']);
        $relationshipCompanies = $includeDetailData
            ? (clone $companyQuery)
                ->with(['owner:id,name', 'team:id,name', 'customerScore:id,company_id,tier'])
                ->withMax('activities', 'occurred_at')
                ->withCount(['journeyEnrollments as active_journeys_count' => fn ($enrollments) => $enrollments->whereIn('status', ['active', 'paused'])])
                ->orderByRaw('"activities_max_occurred_at" ASC NULLS FIRST')
                ->orderBy('companies.id')
                ->paginate($sellerPageSize, ['companies.id', 'companies.name', 'companies.team_id', 'companies.owner_user_id', 'companies.relationship_status'])
                ->withQueryString()
            : null;

        $salesByMonth = [];
        $monthCursor = $fromLocal->startOfMonth();
        $lastMonth = $toLocal->subDay()->startOfMonth();
        while ($includeDetailData && $monthCursor <= $lastMonth) {
            $monthStartUtc = $monthCursor->startOfMonth()->utc();
            if ($monthStartUtc < $fromUtc) {
                $monthStartUtc = $fromUtc;
            }
            $monthEndUtc = $monthCursor->startOfMonth()->addMonth()->utc();
            if ($monthEndUtc > $toUtc) {
                $monthEndUtc = $toUtc;
            }
            $monthlySales = (clone $saleQuery)
                ->where('sold_at', '>=', $monthStartUtc)
                ->where('sold_at', '<', $monthEndUtc);
            $salesByMonth[] = [
                'month' => $monthCursor->format('Y-m'),
                'count' => (clone $monthlySales)->count(),
                'revenue' => bcadd((string) (clone $monthlySales)->sum('amount'), '0', 2),
            ];
            $monthCursor = $monthCursor->addMonth();
        }

        return [
            'summary' => $summary,
            'sellerPerformance' => $sellerPerformance,
            'salesByTeam' => $salesByTeam,
            'teams' => $teamOptions,
            'sellers' => $sellerOptions,
            'relationshipCompanies' => $relationshipCompanies,
            'salesByMonth' => $salesByMonth,
            'filters' => [
                'from' => $fromLocal->toDateString(),
                'to' => $toLocal->subDay()->toDateString(),
                'team_id' => $teamId,
                'seller_id' => $sellerId,
                'status' => $taskStatus,
                'type' => $taskType,
            ],
            'attention' => [
                'overdue_tasks' => $overdueTasks,
                'unassigned_companies' => $unassignedCompanies,
            ],
            'timezone' => $timezone,
        ];
    }

    /** @param Builder<Company> $query */
    private function filterCompanies(Builder $query, ?int $teamId, ?int $sellerId): void
    {
        $query->when($teamId !== null, fn ($companies) => $companies->where('team_id', $teamId))
            ->when($sellerId !== null, fn ($companies) => $companies->where('owner_user_id', $sellerId));
    }

    /** @param Builder<Task> $query */
    private function filterTasks(Builder $query, int $organizationId, ?int $teamId, ?int $sellerId): void
    {
        $query->when($teamId !== null, function ($tasks) use ($organizationId, $teamId): void {
            $teamSellers = DB::table('team_memberships')
                ->select('user_id')
                ->where('organization_id', $organizationId)
                ->where('team_id', $teamId)
                ->where('role', 'salesperson')
                ->where('is_active', true);
            $tasks->where(fn ($nested) => $nested
                ->whereHas('company', fn ($companies) => $companies->where('team_id', $teamId))
                ->orWhere(fn ($standalone) => $standalone->whereNull('company_id')->whereIn('assigned_to', $teamSellers)));
        })->when($sellerId !== null, fn ($tasks) => $tasks->where('assigned_to', $sellerId));
    }

    /** @param Builder<Sale> $query */
    private function filterSales(Builder $query, ?int $teamId, ?int $sellerId): void
    {
        if ($teamId !== null) {
            $query->where(function (Builder $sales) use ($teamId): void {
                $sales->where('team_id_at_sale', $teamId)
                    ->orWhere(function (Builder $legacySales) use ($teamId): void {
                        $legacySales->whereNull('team_id_at_sale')
                            ->whereHas('company', fn (Builder $companies) => $companies->where('team_id', $teamId));
                    });
            });
        }

        if ($sellerId !== null) {
            $query->where('seller_id', $sellerId);
        }
    }

    /** @param Builder<JourneyEnrollment> $query */
    private function filterEnrollments(Builder $query, ?int $teamId, ?int $sellerId): void
    {
        $query->when($teamId !== null, fn ($enrollments) => $enrollments->whereHas('company', fn ($companies) => $companies->where('team_id', $teamId)))
            ->when($sellerId !== null, fn ($enrollments) => $enrollments->where('assigned_to', $sellerId));
    }

    private function moneyString(mixed $amount): string
    {
        if (! is_numeric($amount)) {
            return '0.00';
        }

        return bcadd((string) $amount, '0', 2);
    }

    private function metricValue(mixed $row, string $key): mixed
    {
        return is_object($row) ? data_get($row, $key) : null;
    }
}
