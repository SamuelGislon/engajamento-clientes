<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('task')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        $task = $this->route('task');
        $organizationId = app(TenantContext::class)->id();
        $user = $this->user();
        $isSalesperson = $user?->roleIn($organizationId) === 'salesperson';
        $manualTaskRules = $task instanceof Task && $task->origin === 'manual';

        return [
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'required', Rule::in(['first_contact', 'call', 'whatsapp', 'email', 'meeting', 'visit', 'prospecting', 'follow_up', 'reactivation', 'custom'])],
            'priority' => ['sometimes', 'required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['sometimes', 'required', 'date'],
            'company_id' => [
                ...($manualTaskRules ? ['sometimes', 'nullable', 'integer'] : ['prohibited']),
                ...($manualTaskRules ? [Rule::exists('companies', 'id')->where('organization_id', $organizationId)] : []),
                ...($manualTaskRules && $isSalesperson ? [Rule::exists('companies', 'id')->where('organization_id', $organizationId)->where('owner_user_id', $user->id)] : []),
            ],
            'contact_id' => [
                ...($manualTaskRules ? ['sometimes', 'nullable', 'integer'] : ['prohibited']),
                ...($manualTaskRules ? [Rule::exists('company_contacts', 'contact_id')
                    ->where('organization_id', $organizationId)
                    ->where('company_id', $this->integer('company_id', (int) $task->company_id))] : []),
            ],
            'assigned_to' => [
                ...($manualTaskRules ? ['sometimes', 'nullable', 'integer'] : ['prohibited']),
                ...($manualTaskRules ? [Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId)] : []),
                ...($manualTaskRules && $isSalesperson ? [Rule::in([$user->id])] : []),
            ],
        ];
    }
}
