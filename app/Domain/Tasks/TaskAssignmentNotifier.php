<?php

namespace App\Domain\Tasks;

use App\Domain\Tenancy\TenantContext;
use App\Mail\TaskAssignedMail;
use App\Models\JourneyEnrollment;
use App\Models\Task;
use App\Notifications\TaskEventNotification;
use Illuminate\Support\Facades\Mail;

class TaskAssignmentNotifier
{
    public function notify(Task $task, string $event, string $title, string $message): void
    {
        $this->notifyTaskEvent($task, $event, $title, $message);
        $task->loadMissing(['assignee', 'company']);

        if ($task->assignee === null) {
            return;
        }

        $timezone = app(TenantContext::class)->organization()->timezone;
        Mail::to($task->assignee)->queue((new TaskAssignedMail($task, $timezone))->afterCommit());
    }

    public function notifyTaskEvent(Task $task, string $event, string $title, string $message): void
    {
        $task->loadMissing('assignee');

        if ($task->assignee === null) {
            return;
        }

        $task->assignee->notify(new TaskEventNotification(
            event: $event,
            organizationId: $task->organization_id,
            title: $title,
            message: $message,
            taskId: $task->id,
            companyId: $task->company_id,
        ));
    }

    public function notifyJourneyCompleted(JourneyEnrollment $enrollment): void
    {
        $enrollment->loadMissing(['assignee', 'company']);

        if ($enrollment->assignee === null) {
            return;
        }

        $enrollment->assignee->notify(new TaskEventNotification(
            event: 'journey_completed',
            organizationId: $enrollment->organization_id,
            title: 'Jornada concluída',
            message: 'A jornada de '.$enrollment->company->name.' foi concluída.',
            companyId: $enrollment->company_id,
        ));
    }
}
