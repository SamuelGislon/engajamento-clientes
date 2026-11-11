<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $event,
        public readonly int $organizationId,
        public readonly string $title,
        public readonly string $message,
        public readonly ?int $taskId = null,
        public readonly ?int $companyId = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'organization_id' => $this->organizationId,
            'title' => $this->title,
            'message' => $this->message,
            'task_id' => $this->taskId,
            'company_id' => $this->companyId,
        ];
    }
}
