<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BatchAssignCompaniesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->roleIn(app(TenantContext::class)->id()), ['owner', 'admin'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();

        return [
            'company_ids' => ['required', 'array', 'min:1', 'max:100'],
            'company_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('companies', 'id')->where('organization_id', $organizationId),
            ],
            'owner_user_id' => [
                'nullable', 'integer',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true),
            ],
            'team_id' => [
                'nullable', 'integer',
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
            $teamId = $this->input('team_id');
            $ownerId = $this->input('owner_user_id');

            if ($teamId === null || $ownerId === null) {
                return;
            }

            $belongsToTeam = DB::table('team_memberships')
                ->where('organization_id', app(TenantContext::class)->id())
                ->where('team_id', (int) $teamId)
                ->where('user_id', (int) $ownerId)
                ->where('role', 'salesperson')
                ->where('is_active', true)
                ->exists();

            if (! $belongsToTeam) {
                $validator->errors()->add('owner_user_id', 'O vendedor precisa estar ativo na equipe selecionada.');
            }
        }];
    }
}
