<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Task::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $user = $this->user();
        $isSalesperson = $user?->roleIn($organizationId) === 'salesperson';

        return [
            'company_id' => [
                'nullable', 'integer',
                Rule::exists('companies', 'id')->where('organization_id', $organizationId),
                ...($isSalesperson ? [Rule::exists('companies', 'id')->where('organization_id', $organizationId)->where('owner_user_id', $user->id)] : []),
            ],
            'contact_id' => [
                'nullable', 'integer',
                Rule::exists('company_contacts', 'contact_id')
                    ->where('organization_id', $organizationId)
                    ->where('company_id', $this->integer('company_id')),
            ],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId),
                ...($isSalesperson ? [Rule::in([$user->id])] : []),
            ],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['first_contact', 'call', 'whatsapp', 'email', 'meeting', 'visit', 'prospecting', 'follow_up', 'reactivation', 'custom'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['required', 'date'],
        ];
    }
}
