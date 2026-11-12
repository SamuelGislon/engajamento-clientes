<?php

namespace App\Domain\Teams;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\CompanyAssignmentHistory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyAssignmentService
{
    public function __construct(
        private JourneyService $journeys,
        private CommercialInactivityService $inactivity,
    ) {}

    public function assign(
        Company $company,
        ?int $ownerUserId,
        ?int $teamId,
        User $actor,
        ?string $reason,
    ): Company {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId === null || $company->organization_id !== $organizationId) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($company, $ownerUserId, $teamId, $actor, $reason, $organizationId): Company {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);

            if (! $actor->can('update', $lockedCompany)) {
                throw new AuthorizationException;
            }

            $team = $teamId === null ? null : Team::query()->lockForUpdate()->findOrFail($teamId);

            if ($team !== null && $team->status !== 'active') {
                throw ValidationException::withMessages(['team_id' => 'A equipe selecionada não está ativa.']);
            }

            $role = $actor->roleIn($organizationId);

            if ($role === 'manager') {
                $managesTargetTeam = $team !== null && DB::table('team_memberships')
                    ->where('organization_id', $organizationId)
                    ->where('team_id', $team->id)
                    ->where('user_id', $actor->id)
                    ->where('role', 'manager')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists();

                if (! $managesTargetTeam) {
                    throw new AuthorizationException;
                }
            } elseif ($role === 'salesperson') {
                if ($teamId !== $lockedCompany->team_id || $ownerUserId !== $actor->id) {
                    throw new AuthorizationException;
                }
            } elseif (! in_array($role, ['owner', 'admin'], true)) {
                throw new AuthorizationException;
            }

            $ownerChanged = $lockedCompany->owner_user_id !== $ownerUserId;
            $teamChanged = $lockedCompany->team_id !== $teamId;

            if (! $ownerChanged && ! $teamChanged) {
                return $lockedCompany;
            }

            if ($ownerUserId !== null) {
                $ownerMembership = DB::table('organization_user')
                    ->where('organization_id', $organizationId)
                    ->where('user_id', $ownerUserId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists();

                if (! $ownerMembership) {
                    throw ValidationException::withMessages(['owner_user_id' => 'O responsável precisa ser um vendedor ativo da organização.']);
                }
            }

            if ($team !== null && $ownerUserId !== null) {
                $ownerIsTeamMember = DB::table('team_memberships')
                    ->where('organization_id', $organizationId)
                    ->where('team_id', $team->id)
                    ->where('user_id', $ownerUserId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists();

                if (! $ownerIsTeamMember) {
                    throw ValidationException::withMessages(['owner_user_id' => 'O responsável precisa estar ativo na equipe selecionada.']);
                }
            }

            $previousOwnerId = $lockedCompany->owner_user_id;
            $previousTeamId = $lockedCompany->team_id;
            $lockedCompany->update(['owner_user_id' => $ownerUserId, 'team_id' => $teamId]);

            CompanyAssignmentHistory::query()->create([
                'company_id' => $lockedCompany->id,
                'previous_owner_id' => $previousOwnerId,
                'new_owner_id' => $ownerUserId,
                'previous_team_id' => $previousTeamId,
                'new_team_id' => $teamId,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'changed_at' => now('UTC'),
            ]);

            if ($ownerChanged) {
                $this->journeys->reassignOpenTasks($lockedCompany, $ownerUserId, $actor);
                $this->inactivity->reassignOpenStandaloneTasks($lockedCompany, $ownerUserId, $actor);
            }

            return $lockedCompany->refresh();
        }, 3);
    }

    /**
     * @param  array<int, int>  $companyIds
     */
    public function assignMany(array $companyIds, ?int $ownerUserId, ?int $teamId, User $actor, ?string $reason): void
    {
        $organizationId = app(TenantContext::class)->id();
        if ($organizationId === null) {
            throw new AuthorizationException;
        }

        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        sort($companyIds);

        DB::transaction(function () use ($companyIds, $ownerUserId, $teamId, $actor, $reason): void {
            $companies = Company::query()
                ->whereIn('id', $companyIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($companies->count() !== count($companyIds)) {
                throw ValidationException::withMessages(['company_ids' => 'Uma ou mais empresas estão fora da organização ativa.']);
            }

            foreach ($companies as $company) {
                $this->assign($company, $ownerUserId, $teamId, $actor, $reason);
            }
        }, 3);
    }
}
