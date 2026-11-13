<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\DataImport;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDataImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', DataImport::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'record_type' => ['required', 'string', Rule::in(['companies', 'contacts'])],
            'journey_mode' => ['required', 'string', Rule::in(['none'])],
            'journey_template_id' => ['prohibited'],
            'team_id' => [
                'nullable',
                'integer',
                Rule::exists('teams', 'id')
                    ->where('organization_id', app(TenantContext::class)->id())
                    ->where('status', 'active'),
            ],
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx'],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            $organizationId = app(TenantContext::class)->id();
            $role = $user?->roleIn($organizationId);
            $teamId = $this->input('team_id');

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

            if ($role === 'salesperson' && $teamId !== null) {
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
        }];
    }
}
