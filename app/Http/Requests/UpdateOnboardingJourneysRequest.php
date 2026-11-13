<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnboardingJourneysRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = app(TenantContext::class)->organization();

        return $organization !== null
            && $organization->onboarding_step === 'journeys'
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
            'default_journey_template_id' => [
                'required',
                'integer',
                Rule::exists('journey_templates', 'id')
                    ->where('organization_id', app(TenantContext::class)->id())
                    ->where('type', 'prospecting')
                    ->where('is_active', true),
            ],
            'auto_enroll_default_journey' => ['required', 'boolean'],
        ];
    }
}
