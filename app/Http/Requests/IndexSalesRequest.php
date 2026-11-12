<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Sale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSalesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Sale::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $companyRule = Rule::exists('companies', 'id')->where('organization_id', $organizationId);

        if ($this->user()?->roleIn($organizationId) === 'salesperson') {
            $companyRule->where('owner_user_id', $this->user()->id);
        }

        return [
            'status' => ['nullable', Rule::in(['confirmed', 'cancelled'])],
            'company_id' => ['nullable', 'integer', $companyRule],
            'seller_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId),
            ],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
