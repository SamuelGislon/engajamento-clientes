<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Contact::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'preferred_channel' => ['nullable', Rule::in(['call', 'message', 'whatsapp', 'email', 'meeting'])],
            'contact_preferences' => ['nullable', 'string', 'max:2000'],
            'professional_interests' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'company_id' => [
                'required', 'integer',
                Rule::exists('companies', 'id')->where('organization_id', $organizationId),
            ],
            'roles' => ['required', 'array', 'min:1', 'max:5'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(['owner', 'partner', 'budget_manager', 'buyer', 'decision_maker', 'influencer', 'specifier', 'finance', 'operations', 'other'])],
            'is_primary' => ['sometimes', 'boolean'],
            'relationship_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
