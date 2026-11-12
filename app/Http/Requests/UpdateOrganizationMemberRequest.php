<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOrganizationMemberRequest extends FormRequest
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
            'role' => ['required', Rule::in(['owner', 'admin', 'manager', 'salesperson'])],
            'is_active' => ['required', 'boolean'],
            'team_ids' => ['present', 'array', 'max:20'],
            'team_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('teams', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active'),
            ],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $organizationId = app(TenantContext::class)->id();
            $role = $this->user()?->roleIn($organizationId);
            $requestedRole = $this->input('role');
            $teamIds = $this->input('team_ids', []);

            if ($role !== 'owner' && $requestedRole === 'owner') {
                $validator->errors()->add('role', 'Somente o proprietário pode conceder esse papel.');
            }

            if ($requestedRole === 'manager' && count($teamIds) === 0) {
                $validator->errors()->add('team_ids', 'Gestores precisam administrar ao menos uma equipe.');
            }

            if (in_array($requestedRole, ['owner', 'admin'], true) && count($teamIds) > 0) {
                $validator->errors()->add('team_ids', 'Proprietários e administradores não precisam de vínculo com equipes.');
            }
        }];
    }

    /** @return array{role: string, is_active: bool, team_ids: array<int, int>} */
    public function memberAttributes(): array
    {
        $validated = $this->validated();
        $teamIds = is_array($validated['team_ids'] ?? null) ? $validated['team_ids'] : [];

        return [
            'role' => (string) ($validated['role'] ?? ''),
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'team_ids' => array_values(array_map(static fn (mixed $teamId): int => (int) $teamId, $teamIds)),
        ];
    }
}
