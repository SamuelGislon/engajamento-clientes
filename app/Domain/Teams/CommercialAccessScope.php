<?php

namespace App\Domain\Teams;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class CommercialAccessScope
{
    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function applyCompanies(Builder $query, User $user, ?int $organizationId): Builder
    {
        $role = $user->roleIn($organizationId);

        if ($organizationId === null || $role === null) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        if ($role === 'salesperson') {
            return $query->where('companies.owner_user_id', $user->id);
        }

        if ($role !== 'manager') {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('companies.team_id', $this->managedTeamIds($user, $organizationId));
    }

    public function managedTeamIds(User $user, int $organizationId): QueryBuilder
    {
        return DB::table('team_memberships')
            ->select('team_memberships.team_id')
            ->where('team_memberships.organization_id', $organizationId)
            ->where('team_memberships.user_id', $user->id)
            ->where('team_memberships.role', 'manager')
            ->where('team_memberships.is_active', true)
            ->whereExists(fn ($teams) => $teams->selectRaw('1')
                ->from('teams')
                ->whereColumn('teams.id', 'team_memberships.team_id')
                ->whereColumn('teams.organization_id', 'team_memberships.organization_id')
                ->where('teams.status', 'active'));
    }

    public function managedSellerIds(User $user, int $organizationId): QueryBuilder
    {
        return DB::table('team_memberships')
            ->select('team_memberships.user_id')
            ->where('team_memberships.organization_id', $organizationId)
            ->where('team_memberships.role', 'salesperson')
            ->where('team_memberships.is_active', true)
            ->whereIn('team_memberships.team_id', $this->managedTeamIds($user, $organizationId));
    }

    public function canViewCompany(User $user, Company $company, ?int $organizationId): bool
    {
        if ($organizationId === null || $company->organization_id !== $organizationId) {
            return false;
        }

        $role = $user->roleIn($organizationId);

        if (in_array($role, ['owner', 'admin'], true)) {
            return true;
        }

        if ($role === 'salesperson') {
            return $company->owner_user_id === $user->id;
        }

        if ($role !== 'manager' || $company->team_id === null) {
            return false;
        }

        return DB::table('team_memberships')
            ->where('organization_id', $organizationId)
            ->where('team_id', $company->team_id)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->whereExists(fn ($teams) => $teams->selectRaw('1')
                ->from('teams')
                ->whereColumn('teams.id', 'team_memberships.team_id')
                ->where('teams.status', 'active'))
            ->exists();
    }
}
