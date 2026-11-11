<?php

namespace App\Console\Commands;

use App\Domain\Tasks\TaskAssignmentNotifier;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendTaskDeadlineNotifications extends Command
{
    protected $signature = 'engagement:notify-task-deadlines';

    protected $description = 'Create once-only notifications for upcoming and overdue tasks.';

    public function handle(TenantContext $tenant, TaskAssignmentNotifier $notifications): int
    {
        $hasFailures = false;

        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
            try {
                $tenant->set($organization);
                $this->notifyUpcomingTasks($organization, $tenant, $notifications);
                $this->notifyOverdueTasks($organization, $tenant, $notifications);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Falha ao processar notificações da organização {$organization->id}.");
                $hasFailures = true;
            }
        }

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    private function notifyUpcomingTasks(Organization $organization, TenantContext $tenant, TaskAssignmentNotifier $notifications): void
    {
        $now = CarbonImmutable::now('UTC');
        $taskIds = Task::query()
            ->whereIn('status', ['pending', 'in_progress', 'postponed'])
            ->whereNull('due_soon_notified_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->addHours(24))
            ->orderBy('id')
            ->pluck('id');

        foreach ($taskIds as $taskId) {
            DB::transaction(function () use ($taskId, $organization, $tenant, $notifications): void {
                $tenant->set($organization);
                $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

                if ($task === null || $task->due_soon_notified_at !== null
                    || ! in_array($task->status, ['pending', 'in_progress', 'postponed'], true)
                    || $task->due_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                    || $task->due_at->greaterThan(CarbonImmutable::now('UTC')->addHours(24))) {
                    return;
                }

                $task->update(['due_soon_notified_at' => CarbonImmutable::now('UTC')]);
                $notifications->notifyTaskEvent($task, 'task_due_soon', 'Atividade próxima do vencimento', 'Uma atividade vence nas próximas 24 horas.');
            });
        }
    }

    private function notifyOverdueTasks(Organization $organization, TenantContext $tenant, TaskAssignmentNotifier $notifications): void
    {
        $now = CarbonImmutable::now('UTC');
        $taskIds = Task::query()
            ->whereIn('status', ['pending', 'in_progress', 'postponed'])
            ->whereNull('overdue_notified_at')
            ->where('due_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        foreach ($taskIds as $taskId) {
            DB::transaction(function () use ($taskId, $organization, $tenant, $notifications): void {
                $tenant->set($organization);
                $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

                if ($task === null || $task->overdue_notified_at !== null
                    || ! in_array($task->status, ['pending', 'in_progress', 'postponed'], true)
                    || $task->due_at->isFuture()) {
                    return;
                }

                $task->update(['overdue_notified_at' => CarbonImmutable::now('UTC')]);
                $notifications->notifyTaskEvent($task, 'task_overdue', 'Atividade em atraso', 'Uma atividade está vencida e precisa de atenção.');
            });
        }
    }
}
