<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JourneyEnrollment;
use App\Models\Task;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $baseCompanies = Company::query()->visibleTo($user);
        $timezone = app(TenantContext::class)->organization()->timezone;
        $localTodayStart = now($timezone)->startOfDay();
        $todayStart = $localTodayStart->utc();
        $tomorrowStart = $localTodayStart->addDay()->utc();
        $openTasks = Task::query()->visibleTo($user)->whereIn('status', ['pending', 'in_progress', 'postponed']);
        $activeJourneys = JourneyEnrollment::query()->whereIn('status', ['active', 'paused']);

        if ($user->roleIn(app(TenantContext::class)->id()) === 'salesperson') {
            $activeJourneys->where('assigned_to', $user->id)
                ->whereHas('company', fn ($companies) => $companies->where('owner_user_id', $user->id));
        }

        return Inertia::render('dashboard', [
            'metrics' => [
                'companies' => (clone $baseCompanies)->count(),
                'prospects' => (clone $baseCompanies)->where('relationship_status', 'prospect')->count(),
                'customers' => (clone $baseCompanies)->where('relationship_status', 'customer')->count(),
                'contacts' => Contact::query()
                    ->when($user->roleIn(app(TenantContext::class)->id()) === 'salesperson', fn ($query) => $query->whereHas(
                        'companies', fn ($companies) => $companies->where('companies.owner_user_id', $user->id),
                    ))
                    ->count(),
                'tasks_due_today' => (clone $openTasks)->where('due_at', '>=', $todayStart)->where('due_at', '<', $tomorrowStart)->count(),
                'overdue_tasks' => (clone $openTasks)->where('due_at', '<', $todayStart)->count(),
                'active_journeys' => $activeJourneys->count(),
            ],
            'recentActivities' => Activity::query()
                ->with(['company:id,name', 'contact:id,name', 'user:id,name'])
                ->when($user->roleIn(app(TenantContext::class)->id()) === 'salesperson', fn ($query) => $query->whereHas(
                    'company', fn ($companies) => $companies->where('owner_user_id', $user->id),
                ))
                ->latest('occurred_at')
                ->limit(6)
                ->get(),
            'attentionCompanies' => (clone $baseCompanies)
                ->withMax('activities', 'occurred_at')
                ->orderByRaw('activities_max_occurred_at IS NULL DESC')
                ->orderBy('activities_max_occurred_at')
                ->limit(5)
                ->get(['id', 'name', 'relationship_status', 'owner_user_id']),
        ]);
    }
}
