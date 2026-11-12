<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $invitationToken = request()->session()->get('pending_invitation_token');
        $invitation = is_string($invitationToken)
            ? OrganizationInvitation::findByRawToken($invitationToken)
            : null;
        $invitationMatches = $invitation !== null
            && $invitation->displayStatus() === 'pending'
            && $invitation->email === $input['email'];

        Validator::make($input, [
            ...$this->profileRules(),
            'organization_name' => $invitationMatches
                ? ['nullable', 'string', 'max:120']
                : ['required', 'string', 'max:120'],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input, $invitationMatches, $invitationToken): User {
            if ($invitationMatches) {
                $lockedInvitation = OrganizationInvitation::query()
                    ->withoutGlobalScope(OrganizationScope::class)
                    ->where('token_hash', hash('sha256', (string) $invitationToken))
                    ->lockForUpdate()
                    ->first();

                if ($lockedInvitation === null
                    || $lockedInvitation->displayStatus() !== 'pending'
                    || $lockedInvitation->email !== $input['email']) {
                    throw ValidationException::withMessages([
                        'email' => 'O convite não está mais disponível. Abra o link atualizado ou solicite outro convite.',
                    ]);
                }
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($invitationMatches) {
                return $user;
            }

            $organization = Organization::create([
                'name' => $input['organization_name'],
                'timezone' => config('app.timezone', 'America/Sao_Paulo'),
            ]);

            $organization->users()->attach($user, ['role' => 'owner']);
            app(TenantContext::class)->set($organization);
            app(JourneyService::class)->ensureDefaultTemplate($organization);
            request()->session()->put('active_organization_id', $organization->id);

            return $user;
        });
    }
}
