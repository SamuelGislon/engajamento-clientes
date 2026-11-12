<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\Sale;
use App\Models\User;

class SalePolicy
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
    public function view(User $user, Sale $sale): bool
    {
        return $this->viewAny($user)
            && ($user->roleIn(app(TenantContext::class)->id()) !== 'salesperson'
                || $sale->company?->owner_user_id === $user->id);
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
    public function update(User $user, Sale $sale): bool
    {
        return $this->canManage($user, $sale);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Sale $sale): bool
    {
        return false;
    }

    public function cancel(User $user, Sale $sale): bool
    {
        return $this->canManage($user, $sale);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Sale $sale): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Sale $sale): bool
    {
        return false;
    }

    private function canManage(User $user, Sale $sale): bool
    {
        if (! $this->view($user, $sale) || $sale->status !== 'confirmed') {
            return false;
        }

        return $user->roleIn(app(TenantContext::class)->id()) !== 'salesperson'
            || $sale->seller_id === $user->id
            || $sale->created_by === $user->id;
    }
}
