<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\JourneyEnrollment;
use App\Models\User;

class JourneyEnrollmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->roleIn(app(TenantContext::class)->id()) !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return $this->viewAny($user)
            && JourneyEnrollment::query()->visibleTo($user)->whereKey($journeyEnrollment->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return $this->view($user, $journeyEnrollment)
            && in_array($journeyEnrollment->status, ['active', 'paused'], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return false;
    }

    public function pause(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return $this->view($user, $journeyEnrollment) && $journeyEnrollment->status === 'active';
    }

    public function resume(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return $this->view($user, $journeyEnrollment) && $journeyEnrollment->status === 'paused';
    }

    public function cancel(User $user, JourneyEnrollment $journeyEnrollment): bool
    {
        return $this->update($user, $journeyEnrollment);
    }
}
