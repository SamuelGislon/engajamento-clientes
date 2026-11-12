<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Enums\OrganizationRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->roleIn(app(TenantContext::class)->id()), [
            OrganizationRole::Owner->value,
            OrganizationRole::Admin->value,
            OrganizationRole::Manager->value,
        ], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        $organizationId = app(TenantContext::class)->id();
        $role = $user->roleIn($organizationId);

        if ($role === null || $team->organization_id !== $organizationId) {
            return false;
        }

        return match ($role) {
            OrganizationRole::Owner->value, OrganizationRole::Admin->value => true,
            OrganizationRole::Manager->value => $team->status === 'active' && $team->isManagedBy($user),
            default => false,
        };
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->roleIn(app(TenantContext::class)->id()), [OrganizationRole::Owner->value, OrganizationRole::Admin->value], true);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $this->create($user) && $this->view($user, $team);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $this->create($user) && $this->view($user, $team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return $this->create($user) && $this->view($user, $team);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Team $team): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Team $team): bool
    {
        return false;
    }
}
