<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\JourneyTemplate;
use App\Models\User;

class JourneyTemplatePolicy
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
    public function view(User $user, JourneyTemplate $journeyTemplate): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->roleIn(app(TenantContext::class)->id()), ['owner', 'admin', 'manager'], true);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, JourneyTemplate $journeyTemplate): bool
    {
        return $this->manageSettings($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, JourneyTemplate $journeyTemplate): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, JourneyTemplate $journeyTemplate): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, JourneyTemplate $journeyTemplate): bool
    {
        return false;
    }

    public function manageSettings(User $user): bool
    {
        return in_array($user->roleIn(app(TenantContext::class)->id()), ['owner', 'admin', 'manager'], true);
    }
}
