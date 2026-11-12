<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncTeamMembersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = app(TenantContext::class)->organization();
        $team = $organization?->teams()->find((int) $this->route('team'));

        return $team !== null && ($this->user()?->can('manageMembers', $team) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array', 'max:100'],
            'user_ids.*' => [
                'integer', 'distinct',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', app(TenantContext::class)->id())
                    ->whereIn('role', ['manager', 'salesperson'])
                    ->where('is_active', true),
            ],
        ];
    }
}
