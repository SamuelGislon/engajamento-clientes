<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $companyId = (int) $this->route('company');

        return [
            'contact_id' => [
                'nullable', 'integer',
                Rule::exists('company_contacts', 'contact_id')
                    ->where('organization_id', $organizationId)
                    ->where('company_id', $companyId),
            ],
            'type' => ['required', Rule::in(['call', 'message', 'whatsapp', 'email', 'meeting', 'visit', 'presentation', 'material', 'quote_follow_up', 'other'])],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'description' => ['required', 'string', 'max:10000'],
            'result' => ['nullable', 'string', 'max:5000'],
            'next_action' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
