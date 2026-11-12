<?php

namespace App\Domain\Teams;

use App\Domain\Tenancy\TenantContext;
use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class OrganizationInvitationService
{
    public function invite(
        Organization $organization,
        User $inviter,
        string $email,
        string $role,
        ?int $teamId,
    ): OrganizationInvitation {
        app(TenantContext::class)->set($organization);
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($organization, $inviter, $email, $role, $teamId): OrganizationInvitation {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $inviterRole = $inviter->roleIn($lockedOrganization->id);

            if (! in_array($inviterRole, [OrganizationRole::Owner->value, OrganizationRole::Admin->value], true)) {
                throw new AuthorizationException;
            }

            if (! in_array($role, [OrganizationRole::Manager->value, OrganizationRole::Salesperson->value], true)) {
                throw ValidationException::withMessages(['role' => 'Convites só podem conceder o papel de gestor ou vendedor.']);
            }

            if ($role === OrganizationRole::Manager->value && $teamId === null) {
                throw ValidationException::withMessages(['team_id' => 'Selecione uma equipe para o gestor convidado.']);
            }

            $team = $teamId === null ? null : Team::query()->lockForUpdate()->findOrFail($teamId);

            if ($team !== null && ($team->organization_id !== $lockedOrganization->id || $team->status !== 'active')) {
                throw ValidationException::withMessages(['team_id' => 'A equipe selecionada não está ativa nesta organização.']);
            }

            $alreadyActiveMember = DB::table('organization_user')
                ->join('users', 'users.id', '=', 'organization_user.user_id')
                ->where('organization_user.organization_id', $lockedOrganization->id)
                ->where('organization_user.is_active', true)
                ->whereRaw('LOWER(users.email) = ?', [$email])
                ->exists();

            if ($alreadyActiveMember) {
                throw ValidationException::withMessages(['email' => 'Esta pessoa já participa da organização.']);
            }

            $token = bin2hex(random_bytes(32));
            $invitation = OrganizationInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            $attributes = [
                'email' => $email,
                'role' => $role,
                'team_id' => $team?->id,
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
                'revoked_at' => null,
                'created_by' => $inviter->id,
            ];

            if ($invitation === null) {
                $invitation = OrganizationInvitation::query()->create($attributes);
            } else {
                $invitation->update($attributes);
            }

            Mail::to($email)->queue(new OrganizationInvitationMail(
                organizationName: $lockedOrganization->name,
                roleLabel: $role === OrganizationRole::Manager->value ? 'Gestor' : 'Vendedor',
                teamName: $team?->name,
                invitationUrl: route('invitations.show', ['token' => $token]),
            ));

            return $invitation;
        }, 3);
    }

    public function revoke(OrganizationInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $lockedInvitation = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! in_array($lockedInvitation->displayStatus(), ['pending', 'expired'], true)) {
                throw ValidationException::withMessages(['invitation' => 'Somente convites pendentes ou expirados podem ser revogados.']);
            }

            $lockedInvitation->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            ]);
        }, 3);
    }

    public function accept(string $token, User $user): Organization
    {
        if (! $user->hasVerifiedEmail()) {
            throw new AuthorizationException('Verifique o e-mail da conta antes de aceitar o convite.');
        }

        $foundInvitation = OrganizationInvitation::findByRawToken($token);

        if ($foundInvitation === null) {
            abort(404);
        }

        $organization = Organization::query()->findOrFail($foundInvitation->organization_id);
        app(TenantContext::class)->set($organization);

        if (mb_strtolower(trim($user->email)) !== $foundInvitation->email) {
            throw new AuthorizationException('Este convite foi enviado para outro endereço de e-mail.');
        }

        return DB::transaction(function () use ($token, $user, $organization): Organization {
            $invitation = OrganizationInvitation::query()
                ->lockForUpdate()
                ->where('token_hash', hash('sha256', $token))
                ->firstOrFail();

            if ($invitation->displayStatus() !== 'pending') {
                throw ValidationException::withMessages(['invitation' => 'O convite expirou, foi revogado ou já foi utilizado.']);
            }

            if (mb_strtolower(trim($user->email)) !== $invitation->email) {
                throw new AuthorizationException('Este convite foi enviado para outro endereço de e-mail.');
            }

            if (! in_array($invitation->role, [OrganizationRole::Manager->value, OrganizationRole::Salesperson->value], true)) {
                throw ValidationException::withMessages(['invitation' => 'O papel deste convite não pode ser aceito.']);
            }

            $membership = DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($membership !== null && (bool) $membership->is_active) {
                throw ValidationException::withMessages(['invitation' => 'Esta conta já participa da organização.']);
            }

            if ($membership === null) {
                $organization->users()->attach($user->id, [
                    'role' => $invitation->role,
                    'is_active' => true,
                ]);
            } else {
                DB::table('organization_user')
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->update([
                        'role' => $invitation->role,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
            }

            if ($invitation->team_id !== null) {
                $team = Team::query()->lockForUpdate()->findOrFail($invitation->team_id);

                if ($team->status !== 'active') {
                    throw ValidationException::withMessages(['invitation' => 'A equipe do convite foi desativada. Peça um novo convite.']);
                }

                $teamMembership = DB::table('team_memberships')
                    ->where('team_id', $team->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($teamMembership === null) {
                    $team->users()->attach($user->id, [
                        'organization_id' => $organization->id,
                        'role' => $invitation->role,
                        'is_active' => true,
                        'joined_at' => now(),
                    ]);
                } else {
                    DB::table('team_memberships')
                        ->where('id', $teamMembership->id)
                        ->update([
                            'role' => $invitation->role,
                            'is_active' => true,
                            'joined_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }

            $invitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            ]);

            return $organization;
        }, 3);
    }
}
