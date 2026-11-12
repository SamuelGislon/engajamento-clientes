<?php

namespace App\Domain\Teams;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationMemberService
{
    /**
     * @param  array{role: string, is_active: bool, team_ids: array<int, int>}  $attributes
     */
    public function update(Organization $organization, int $memberId, User $actor, array $attributes): void
    {
        app(TenantContext::class)->set($organization);

        DB::transaction(function () use ($organization, $memberId, $actor, $attributes): void {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $actorRole = $actor->roleIn($lockedOrganization->id);

            if (! in_array($actorRole, ['owner', 'admin'], true)) {
                throw new AuthorizationException;
            }

            $membership = DB::table('organization_user')
                ->where('organization_id', $lockedOrganization->id)
                ->where('user_id', $memberId)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                abort(404);
            }

            if ($actorRole === 'admin' && in_array($membership->role, ['owner', 'admin'], true)) {
                throw new AuthorizationException('Somente o proprietário pode administrar vínculos de proprietários e administradores.');
            }

            if ($actorRole === 'admin' && in_array($attributes['role'], ['owner', 'admin'], true)) {
                throw new AuthorizationException('Administradores não podem conceder papéis administrativos.');
            }

            $isActive = (bool) $attributes['is_active'];
            $isLeavingSalesRole = $membership->role === 'salesperson'
                && ($attributes['role'] !== 'salesperson' || ! $isActive);

            if ($isLeavingSalesRole && $lockedOrganization->companies()->where('owner_user_id', $memberId)->exists()) {
                throw ValidationException::withMessages([
                    'role' => 'Transfira a carteira deste vendedor antes de alterar ou desativar sua participação.',
                ]);
            }

            $isRemovingActiveOwner = $membership->role === 'owner'
                && (bool) $membership->is_active
                && ($attributes['role'] !== 'owner' || ! $isActive);

            if ($isRemovingActiveOwner) {
                $activeOwners = DB::table('organization_user')
                    ->where('organization_id', $lockedOrganization->id)
                    ->where('role', 'owner')
                    ->where('is_active', true)
                    ->orderBy('user_id')
                    ->lockForUpdate()
                    ->pluck('user_id');

                if ($activeOwners->count() <= 1) {
                    throw ValidationException::withMessages([
                        'role' => 'A organização precisa manter pelo menos um proprietário ativo.',
                    ]);
                }
            }

            $teamIds = array_values(array_unique(array_map('intval', $attributes['team_ids'])));
            if ($teamIds !== []) {
                $activeTeamCount = DB::table('teams')
                    ->where('organization_id', $lockedOrganization->id)
                    ->where('status', 'active')
                    ->whereIn('id', $teamIds)
                    ->lockForUpdate()
                    ->count();

                if ($activeTeamCount !== count($teamIds)) {
                    throw ValidationException::withMessages(['team_ids' => 'Uma ou mais equipes não estão ativas nesta organização.']);
                }
            }

            DB::table('organization_user')
                ->where('organization_id', $lockedOrganization->id)
                ->where('user_id', $memberId)
                ->update([
                    'role' => $attributes['role'],
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            $teamMemberships = DB::table('team_memberships')
                ->where('organization_id', $lockedOrganization->id)
                ->where('user_id', $memberId)
                ->lockForUpdate()
                ->get(['id', 'team_id', 'role', 'is_active']);

            if (! $isActive || ! in_array($attributes['role'], ['manager', 'salesperson'], true)) {
                DB::table('team_memberships')
                    ->where('organization_id', $lockedOrganization->id)
                    ->where('user_id', $memberId)
                    ->update(['is_active' => false, 'updated_at' => now()]);

                return;
            }

            $removedSellerTeamIds = $membership->role === 'salesperson'
                ? $teamMemberships
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->whereNotIn('team_id', $teamIds === [] ? [-1] : $teamIds)
                    ->pluck('team_id')
                : collect();

            if ($removedSellerTeamIds->isNotEmpty() && $lockedOrganization->companies()
                ->where('owner_user_id', $memberId)
                ->whereIn('team_id', $removedSellerTeamIds)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'team_ids' => 'Transfira as empresas do vendedor antes de removê-lo das respectivas equipes.',
                ]);
            }

            DB::table('team_memberships')
                ->where('organization_id', $lockedOrganization->id)
                ->where('user_id', $memberId)
                ->whereNotIn('team_id', $teamIds === [] ? [-1] : $teamIds)
                ->update(['is_active' => false, 'updated_at' => now()]);

            foreach ($teamIds as $teamId) {
                $existing = $teamMemberships->firstWhere('team_id', $teamId);
                $values = [
                    'role' => $attributes['role'],
                    'is_active' => true,
                    'joined_at' => now(),
                    'updated_at' => now(),
                ];

                if ($existing !== null) {
                    DB::table('team_memberships')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('team_memberships')->insert([
                        ...$values,
                        'organization_id' => $lockedOrganization->id,
                        'team_id' => $teamId,
                        'user_id' => $memberId,
                        'created_at' => now(),
                    ]);
                }
            }
        }, 3);
    }
}
