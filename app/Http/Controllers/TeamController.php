<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\SyncTeamMembersRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Team::class);
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $user = $request->user();
        $role = $user->roleIn($organization->id);

        $teams = $organization->teams()
            ->withCount(['users as members_count' => fn ($users) => $users->where('team_memberships.is_active', true)])
            ->with(['users' => fn ($users) => $users
                ->where('team_memberships.organization_id', $organization->id)
                ->where('team_memberships.is_active', true)
                ->whereIn('team_memberships.role', ['manager', 'salesperson'])
                ->select(['users.id', 'users.name', 'users.email'])])
            ->when($role === 'manager', fn ($teams) => $teams->where('status', 'active'))
            ->when($role === 'manager', fn ($teams) => $teams->whereHas('users', fn ($users) => $users
                ->where('users.id', $user->id)
                ->where('team_memberships.role', 'manager')
                ->where('team_memberships.is_active', true)))
            ->when($role === 'salesperson', fn ($teams) => $teams->whereHas('users', fn ($users) => $users
                ->where('users.id', $user->id)
                ->where('team_memberships.is_active', true)))
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'status']);

        return Inertia::render('teams/index', [
            'teams' => $teams,
            'canManage' => in_array($role, ['owner', 'admin'], true),
        ]);
    }

    public function store(StoreTeamRequest $request): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);

        $organization->teams()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('teams.index')->with('success', 'Equipe criada.');
    }

    public function show(int $team): Response
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $teamRecord = $organization->teams()
            ->with(['users' => fn ($users) => $users->where('team_memberships.is_active', true)])
            ->withCount('companies')
            ->findOrFail($team);
        Gate::authorize('view', $teamRecord);

        $canManage = Gate::allows('update', $teamRecord);

        return Inertia::render('teams/show', [
            'team' => $teamRecord,
            'canManage' => $canManage,
            'organizationMembers' => $canManage ? $organization->users()
                ->where('organization_user.is_active', true)
                ->whereIn('organization_user.role', ['manager', 'salesperson'])
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email', 'organization_user.role']) : collect(),
        ]);
    }

    public function update(UpdateTeamRequest $request, int $team): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        DB::transaction(function () use ($organization, $team, $request): void {
            $teamRecord = $organization->teams()->lockForUpdate()->findOrFail($team);
            Gate::authorize('update', $teamRecord);
            $attributes = $request->validated();

            if (($attributes['status'] ?? null) === 'inactive'
                && $teamRecord->status === 'active') {
                if ($teamRecord->companies()->exists()) {
                    throw ValidationException::withMessages([
                        'status' => 'Transfira as empresas da equipe antes de desativá-la.',
                    ]);
                }

                if ($teamRecord->openInvitations()->exists()) {
                    throw ValidationException::withMessages([
                        'status' => 'Revogue os convites pendentes ou expirados antes de desativar a equipe.',
                    ]);
                }
            }

            $teamRecord->update($attributes);
        }, 3);

        return redirect()->route('teams.show', $team)->with('success', 'Equipe atualizada.');
    }

    public function syncMembers(SyncTeamMembersRequest $request, int $team): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $userIds = $request->validated('user_ids');

        DB::transaction(function () use ($organization, $team, $userIds): void {
            $teamRecord = $organization->teams()->lockForUpdate()->findOrFail($team);

            if ($teamRecord->status !== 'active') {
                abort(422, 'Não é possível incluir integrantes em uma equipe inativa.');
            }

            $members = DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->whereIn('user_id', $userIds)
                ->whereIn('role', ['manager', 'salesperson'])
                ->lockForUpdate()
                ->get(['user_id', 'role']);

            if ($members->count() !== count($userIds)) {
                abort(422, 'Um ou mais integrantes não pertencem à organização ou não têm um papel de equipe válido.');
            }

            $memberIds = array_values(array_map('intval', $userIds));
            $removedSellers = DB::table('team_memberships')
                ->where('organization_id', $organization->id)
                ->where('team_id', $teamRecord->id)
                ->where('role', 'salesperson')
                ->where('is_active', true)
                ->whereNotIn('user_id', $memberIds === [] ? [-1] : $memberIds)
                ->pluck('user_id');

            if ($removedSellers->isNotEmpty() && $organization->companies()
                ->where('team_id', $teamRecord->id)
                ->whereIn('owner_user_id', $removedSellers)
                ->exists()) {
                throw ValidationException::withMessages([
                    'user_ids' => 'Transfira as empresas dos vendedores antes de removê-los desta equipe.',
                ]);
            }

            DB::table('team_memberships')
                ->where('organization_id', $organization->id)
                ->where('team_id', $teamRecord->id)
                ->whereNotIn('user_id', $memberIds === [] ? [-1] : $memberIds)
                ->update(['is_active' => false, 'updated_at' => now()]);

            $existingMemberships = DB::table('team_memberships')
                ->where('organization_id', $organization->id)
                ->where('team_id', $teamRecord->id)
                ->whereIn('user_id', $memberIds === [] ? [-1] : $memberIds)
                ->get(['id', 'user_id', 'is_active'])
                ->keyBy('user_id');

            foreach ($members as $member) {
                $existing = $existingMemberships->get($member->user_id);
                $values = [
                    'role' => $member->role,
                    'is_active' => true,
                    'updated_at' => now(),
                ];

                if ($existing !== null) {
                    if (! (bool) $existing->is_active) {
                        $values['joined_at'] = now();
                    }
                    DB::table('team_memberships')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('team_memberships')->insert([
                        ...$values,
                        'organization_id' => $organization->id,
                        'team_id' => $teamRecord->id,
                        'user_id' => $member->user_id,
                        'joined_at' => now(),
                        'created_at' => now(),
                    ]);
                }
            }

        });

        return redirect()->route('teams.show', $team)->with('success', 'Integrantes da equipe atualizados.');
    }
}
