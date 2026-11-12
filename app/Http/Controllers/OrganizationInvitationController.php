<?php

namespace App\Http\Controllers;

use App\Domain\Teams\OrganizationInvitationService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\AcceptOrganizationInvitationRequest;
use App\Http\Requests\StoreOrganizationInvitationRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationInvitationController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        abort_unless(in_array($request->user()->roleIn($organization->id), ['owner', 'admin'], true), 403);

        return Inertia::render('organization/users/index', [
            'currentRole' => $request->user()->roleIn($organization->id),
            'members' => $organization->users()
                ->with(['teams' => fn ($teams) => $teams
                    ->where('teams.status', 'active')
                    ->where('team_memberships.organization_id', $organization->id)
                    ->where('team_memberships.is_active', true)])
                ->orderBy('name')
                ->paginate(20)
                ->through(fn ($member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                    'is_active' => (bool) $member->pivot->is_active,
                    'teams' => $member->teams->map(fn ($team): array => [
                        'id' => $team->id,
                        'name' => $team->name,
                    ]),
                ]),
            'invitations' => OrganizationInvitation::query()
                ->with('team:id,name')
                ->latest()
                ->paginate(20, ['id', 'organization_id', 'email', 'role', 'team_id', 'status', 'expires_at', 'accepted_at', 'revoked_at', 'created_at'])
                ->through(fn (OrganizationInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'team' => $invitation->team?->name,
                    'status' => $invitation->displayStatus(),
                    'expires_at' => $invitation->expires_at,
                    'created_at' => $invitation->created_at,
                ]),
            'teams' => $organization->teams()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreOrganizationInvitationRequest $request, OrganizationInvitationService $invitations): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $validated = $request->validated();

        $invitations->invite(
            $organization,
            $request->user(),
            $validated['email'],
            $validated['role'],
            isset($validated['team_id']) ? (int) $validated['team_id'] : null,
        );

        return redirect()->route('organization.users.index')->with('success', 'Convite enviado.');
    }

    public function resend(OrganizationInvitation $invitation, Request $request, OrganizationInvitationService $invitations): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null || $invitation->organization_id !== $organization->id, 404);
        abort_unless(in_array($request->user()->roleIn($organization->id), ['owner', 'admin'], true), 403);

        $invitations->invite($organization, $request->user(), $invitation->email, $invitation->role, $invitation->team_id);

        return back()->with('success', 'Um novo convite foi enviado. O link anterior foi invalidado.');
    }

    public function revoke(OrganizationInvitation $invitation, Request $request, OrganizationInvitationService $invitations): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null || $invitation->organization_id !== $organization->id, 404);
        abort_unless(in_array($request->user()->roleIn($organization->id), ['owner', 'admin'], true), 403);

        $invitations->revoke($invitation);

        return back()->with('success', 'Convite revogado.');
    }

    public function show(string $token, Request $request): Response
    {
        $invitation = OrganizationInvitation::findByRawToken($token);
        abort_if($invitation === null, 404);

        $organization = Organization::query()->findOrFail($invitation->organization_id);
        app(TenantContext::class)->set($organization);
        if (! $request->user()) {
            $request->session()->put('url.intended', route('invitations.show', $token));
            $request->session()->put('pending_invitation_token', $token);
        }

        return Inertia::render('invitations/show', [
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'team' => $invitation->team_id === null ? null : Team::query()
                    ->where('organization_id', $invitation->organization_id)
                    ->whereKey($invitation->team_id)
                    ->value('name'),
                'organization' => $organization->name,
                'status' => $invitation->displayStatus(),
                'token' => $token,
            ],
            'isAuthenticated' => $request->user() !== null,
            'isVerified' => $request->user()?->hasVerifiedEmail() ?? false,
            'emailMatches' => $request->user() !== null
                && mb_strtolower(trim($request->user()->email)) === $invitation->email,
        ]);
    }

    public function accept(AcceptOrganizationInvitationRequest $request, string $token, OrganizationInvitationService $invitations): RedirectResponse
    {
        $organization = $invitations->accept($token, $request->user());
        $request->session()->put('active_organization_id', $organization->id);
        $request->session()->forget('pending_invitation_token');

        return redirect()->route('dashboard')->with('success', 'Você agora participa de '.$organization->name.'.');
    }
}
