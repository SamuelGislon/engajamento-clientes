<?php

namespace App\Http\Controllers;

use App\Domain\Journeys\JourneyService;
use App\Domain\Tasks\TaskAssignmentNotifier;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\CompleteTaskRequest;
use App\Http\Requests\PostponeTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', Task::class);
        $companies = Company::query()
            ->visibleTo($request->user())
            ->with(['contacts:id,name'])
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'owner_user_id']);

        return Inertia::render('tasks/form', [
            'task' => null,
            'companies' => $companies,
            'members' => $this->assignableMembers($request->user()),
            'canChangeRelations' => true,
        ]);
    }

    public function edit(Request $request, Task $task): Response
    {
        Gate::authorize('update', $task);
        $companies = Company::query()
            ->visibleTo($request->user())
            ->with(['contacts:id,name'])
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'owner_user_id']);

        return Inertia::render('tasks/form', [
            'task' => $task->load(['company:id,name', 'contact:id,name', 'assignee:id,name']),
            'companies' => $companies,
            'members' => $this->assignableMembers($request->user()),
            'canChangeRelations' => $task->origin === 'manual',
        ]);
    }

    public function show(Task $task): Response
    {
        Gate::authorize('view', $task);
        $task->load(['company:id,name', 'contact:id,name', 'assignee:id,name', 'creator:id,name']);

        return Inertia::render('tasks/show', ['task' => $task]);
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed', 'postponed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('organization_id', app(TenantContext::class)->id())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $user = $request->user();
        $baseQuery = Task::query()->visibleTo($user);
        $timezone = app(TenantContext::class)->organization()->timezone;
        $today = now($timezone)->startOfDay();
        $tomorrowUtc = $today->addDay()->utc();
        $todayUtc = $today->utc();
        $openStatuses = ['pending', 'in_progress', 'postponed'];
        $metrics = [
            'today' => (clone $baseQuery)->whereIn('status', $openStatuses)->where('due_at', '>=', $todayUtc)->where('due_at', '<', $tomorrowUtc)->count(),
            'overdue' => (clone $baseQuery)->whereIn('status', $openStatuses)->where('due_at', '<', $todayUtc)->count(),
            'upcoming' => (clone $baseQuery)->whereIn('status', $openStatuses)->where('due_at', '>=', $tomorrowUtc)->where('due_at', '<', $today->addDays(8)->utc())->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->where('completed_at', '>=', now()->subDays(30))->count(),
        ];

        $tasks = $baseQuery
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->when($filters['company_id'] ?? null, fn ($query, int $companyId) => $query->where('company_id', $companyId))
            ->when($filters['from'] ?? null, fn ($query, string $date) => $query->where('due_at', '>=', CarbonImmutable::parse($date, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, string $date) => $query->where('due_at', '<', CarbonImmutable::parse($date, $timezone)->addDay()->startOfDay()->utc()))
            ->with(['company:id,name', 'contact:id,name', 'assignee:id,name'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
            'filters' => $filters,
            'metrics' => $metrics,
        ]);
    }

    public function store(StoreTaskRequest $request, TaskAssignmentNotifier $notifications): RedirectResponse
    {
        $attributes = $request->validated();
        $attributes['assigned_to'] ??= $request->user()->id;
        $attributes['created_by'] = $request->user()->id;
        $attributes['origin'] = 'manual';

        DB::transaction(function () use ($attributes, $notifications): void {
            $task = Task::query()->create($attributes);
            $notifications->notify($task, 'task_assigned', 'Nova atividade atribuída', 'Uma atividade foi atribuída a você.');
        });

        return redirect()->route('tasks.index')->with('success', 'Atividade criada.');
    }

    public function complete(CompleteTaskRequest $request, Task $task, JourneyService $journeys): RedirectResponse
    {
        Gate::authorize('complete', $task);
        $journeys->completeTask($task, $request->user(), $request->validated('completion_notes'));

        return redirect()->route('tasks.index')->with('success', 'Atividade concluída.');
    }

    public function update(UpdateTaskRequest $request, Task $task, TaskAssignmentNotifier $notifications): RedirectResponse
    {
        DB::transaction(function () use ($request, $task, $notifications): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::authorize('update', $lockedTask);
            $previousAssigneeId = $lockedTask->assigned_to;
            $attributes = $request->validated();

            if (array_key_exists('due_at', $attributes)
                && ! CarbonImmutable::parse($attributes['due_at'])->utc()->equalTo($lockedTask->due_at)) {
                $attributes['due_soon_notified_at'] = null;
                $attributes['overdue_notified_at'] = null;
            }

            if ($lockedTask->origin === 'manual') {
                $attributes['assigned_to'] ??= $lockedTask->assigned_to;
                if (array_key_exists('company_id', $attributes)
                    && $attributes['company_id'] !== $lockedTask->company_id
                    && ! array_key_exists('contact_id', $attributes)) {
                    $attributes['contact_id'] = null;
                }
            } else {
                $attributes = collect($attributes)->only(['title', 'description', 'type', 'priority', 'due_at'])->all();
            }

            $lockedTask->update($attributes);

            if ($lockedTask->assigned_to !== $previousAssigneeId) {
                $notifications->notify($lockedTask->refresh(), 'task_assigned', 'Nova atividade atribuída', 'Uma atividade foi atribuída a você.');
            }
        });

        return redirect()->route('tasks.index')->with('success', 'Atividade atualizada.');
    }

    public function postpone(PostponeTaskRequest $request, Task $task): RedirectResponse
    {
        DB::transaction(function () use ($request, $task): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::authorize('postpone', $lockedTask);
            $lockedTask->update([
                'status' => 'postponed',
                'due_at' => $request->validated('due_at'),
                'due_soon_notified_at' => null,
                'overdue_notified_at' => null,
            ]);
        });

        return redirect()->route('tasks.index')->with('success', 'Atividade adiada.');
    }

    public function cancel(Task $task): RedirectResponse
    {
        Gate::authorize('cancel', $task);
        DB::transaction(function () use ($task): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::authorize('cancel', $lockedTask);
            $lockedTask->update(['status' => 'cancelled']);
        });

        return redirect()->route('tasks.index')->with('success', 'Atividade cancelada.');
    }

    /** @return Collection<int, User> */
    private function assignableMembers(User $user): Collection
    {
        $organization = app(TenantContext::class)->organization();

        if ($organization === null) {
            return collect();
        }

        $members = $organization->users()->orderBy('name');

        if ($user->roleIn($organization->id) === 'salesperson') {
            $members->where('users.id', $user->id);
        }

        return $members->limit(200)->get(['users.id', 'users.name']);
    }
}
