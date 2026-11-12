<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Enums\OrganizationRole;
use App\Models\Company;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $salesperson = $this->user()?->roleIn($organizationId) === OrganizationRole::Salesperson->value;

        return [
            'name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'tax_id' => ['nullable', 'string', 'max:32', Rule::unique('companies', 'tax_id')->where('organization_id', $organizationId)],
            'segment' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:2000'],
            'relationship_status' => ['required', Rule::in(['prospect', 'customer', 'inactive', 'partner'])],
            'lead_source' => ['nullable', 'string', 'max:120'],
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true),
                ...($salesperson ? [Rule::in([$this->user()->id])] : []),
            ],
            'team_id' => [
                'nullable', 'integer',
                Rule::exists('teams', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active'),
            ],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $organizationId = app(TenantContext::class)->id();
        if ($this->user()?->roleIn($organizationId) !== OrganizationRole::Salesperson->value || $this->filled('team_id')) {
            return;
        }

        $teams = DB::table('team_memberships')
            ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
            ->where('team_memberships.organization_id', $organizationId)
            ->where('team_memberships.user_id', $this->user()->id)
            ->where('team_memberships.role', 'salesperson')
            ->where('team_memberships.is_active', true)
            ->where('teams.status', 'active')
            ->pluck('teams.id');

        if ($teams->count() === 1) {
            $this->merge(['team_id' => $teams->first()]);
        }
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            $organizationId = app(TenantContext::class)->id();
            $role = $user->roleIn($organizationId);
            $teamId = $this->input('team_id');
            $ownerId = $role === OrganizationRole::Salesperson->value
                ? $user->id
                : $this->input('owner_user_id');

            if ($role === 'manager') {
                $managesTeam = $teamId !== null && DB::table('team_memberships')
                    ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
                    ->where('team_memberships.organization_id', $organizationId)
                    ->where('team_memberships.team_id', (int) $teamId)
                    ->where('team_memberships.user_id', $user->id)
                    ->where('team_memberships.role', 'manager')
                    ->where('team_memberships.is_active', true)
                    ->where('teams.status', 'active')
                    ->exists();

                if (! $managesTeam) {
                    $validator->errors()->add('team_id', 'Selecione uma equipe ativa que você administra.');
                }
            }

            if ($role === OrganizationRole::Salesperson->value && $teamId !== null) {
                $isTeamMember = DB::table('team_memberships')
                    ->where('organization_id', $organizationId)
                    ->where('team_id', (int) $teamId)
                    ->where('user_id', $user->id)
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->exists();

                if (! $isTeamMember) {
                    $validator->errors()->add('team_id', 'Selecione uma equipe da qual você faça parte.');
                }
            }

            if ($teamId !== null && $ownerId !== null) {
                $ownerBelongsToTeam = DB::table('team_memberships')
                    ->where('organization_id', $organizationId)
                    ->where('team_id', (int) $teamId)
                    ->where('user_id', (int) $ownerId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->exists();

                if (! $ownerBelongsToTeam) {
                    $validator->errors()->add('owner_user_id', 'O vendedor precisa estar ativo na equipe selecionada.');
                }
            }
        }];
    }
}
