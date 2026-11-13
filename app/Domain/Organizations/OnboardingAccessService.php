<?php

namespace App\Domain\Organizations;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class OnboardingAccessService
{
    public function assertOwner(Organization $organization, User $actor): void
    {
        $this->assertActiveTenant($organization);

        $isActiveOwner = DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->exists();

        if (! $isActiveOwner) {
            throw new AuthorizationException;
        }
    }

    public function lockForOwner(Organization $organization, User $actor, string $expectedStep): Organization
    {
        $this->assertActiveTenant($organization);

        $lockedOrganization = Organization::query()
            ->lockForUpdate()
            ->findOrFail($organization->id);
        $membership = DB::table('organization_user')
            ->where('organization_id', $lockedOrganization->id)
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->first();

        if ($membership === null
            || ! (bool) $membership->is_active
            || $membership->role !== 'owner') {
            throw new AuthorizationException;
        }

        abort_unless($lockedOrganization->onboarding_step === $expectedStep, 403);

        return $lockedOrganization;
    }

    private function assertActiveTenant(Organization $organization): void
    {
        abort_unless(app(TenantContext::class)->id() === $organization->id, 403);
    }
}
