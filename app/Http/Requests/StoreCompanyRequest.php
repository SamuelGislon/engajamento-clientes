<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Enums\OrganizationRole;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $salesperson = $this->user()?->roleIn($organizationId) === OrganizationRole::Salesperson->value;

        return [
            'name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'tax_id' => ['nullable', 'string', 'max:32', Rule::unique('companies', 'tax_id')->where('organization_id', $organizationId)],
            'segment' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:2000'],
            'relationship_status' => ['required', Rule::in(['prospect', 'customer', 'inactive', 'partner'])],
            'lead_source' => ['nullable', 'string', 'max:120'],
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId),
                ...($salesperson ? [Rule::in([$this->user()->id])] : []),
            ],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
