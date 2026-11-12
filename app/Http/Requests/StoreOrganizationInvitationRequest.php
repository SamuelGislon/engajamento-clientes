<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrganizationInvitationRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();

        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['manager', 'salesperson'])],
            'team_id' => [
                'nullable', 'integer',
                Rule::exists('teams', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('role') === 'manager' && $this->input('team_id') === null) {
                $validator->errors()->add('team_id', 'Selecione uma equipe para o gestor convidado.');
            }
        }];
    }
}
