<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOnboardingRelationshipRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = app(TenantContext::class)->organization();

        return $organization !== null
            && $organization->onboarding_step === 'relationship'
            && $this->user()?->roleIn($organization->id) === 'owner';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sales_inactivity_days' => ['required', 'integer', 'between:7,3650'],
            'relationship_inactivity_days' => ['required', 'integer', 'between:1,365'],
        ];
    }
}
