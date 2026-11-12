<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignCompanyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $company = Company::query()->find((int) $this->route('company'));

        return $company !== null && ($this->user()?->can('update', $company) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();

        return [
            'owner_user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true),
            ],
            'team_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('teams', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active'),
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $organizationId = app(TenantContext::class)->id();
            $company = Company::query()->find((int) $this->route('company'));

            if ($organizationId === null || $company === null) {
                return;
            }

            $user = $this->user();
            $role = $user->roleIn($organizationId);
            $ownerId = array_key_exists('owner_user_id', $this->all())
                ? $this->input('owner_user_id')
                : $company->owner_user_id;
            $teamId = array_key_exists('team_id', $this->all())
                ? $this->input('team_id')
                : $company->team_id;

            if ($role === 'salesperson') {
                if (array_key_exists('team_id', $this->all())) {
                    $validator->errors()->add('team_id', 'Somente a administração pode alterar a equipe da empresa.');
                }

                if ($ownerId !== null && (int) $ownerId !== $user->id) {
                    $validator->errors()->add('owner_user_id', 'Vendedores não podem transferir empresas para outro responsável.');
                }

                return;
            }

            if ($role === 'manager') {
                $managesTargetTeam = $teamId !== null && DB::table('team_memberships')
                    ->where('organization_id', $organizationId)
                    ->where('team_id', (int) $teamId)
                    ->where('user_id', $user->id)
                    ->where('role', 'manager')
                    ->where('is_active', true)
                    ->exists();

                if (! $managesTargetTeam) {
                    $validator->errors()->add('team_id', 'Gestores só podem redistribuir empresas dentro das equipes que administram.');
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
