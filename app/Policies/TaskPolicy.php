<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn(app(TenantContext::class)->id()) !== null;
    }

    public function view(User $user, Task $task): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $user->roleIn(app(TenantContext::class)->id()) !== 'salesperson'
            || ($task->assigned_to === $user->id
                && ($task->company_id === null || $task->company?->owner_user_id === $user->id));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task) && ! in_array($task->status, ['completed', 'cancelled'], true);
    }

    public function complete(User $user, Task $task): bool
    {
        return $this->view($user, $task) && in_array($task->status, ['pending', 'in_progress', 'postponed'], true);
    }

    public function postpone(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function cancel(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
