<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\CommercialSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommercialSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', CommercialSetting::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();

        return [
            'sales_inactivity_days' => ['required', 'integer', 'between:7,3650'],
            'relationship_inactivity_days' => ['required', 'integer', 'between:1,365'],
            'reactivation_repeat_cooldown_days' => ['required', 'integer', 'between:7,365'],
            'contact_frequency_days' => ['required', 'array:A,B,C,D'],
            'contact_frequency_days.A' => ['required', 'integer', 'between:1,365'],
            'contact_frequency_days.B' => ['required', 'integer', 'between:1,365'],
            'contact_frequency_days.C' => ['required', 'integer', 'between:1,365'],
            'contact_frequency_days.D' => ['required', 'integer', 'between:1,365'],
            'post_sale_journey_template_id' => [
                'nullable',
                'integer',
                Rule::exists('journey_templates', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('type', 'post_sale')
                    ->where('trigger_type', 'first_sale')
                    ->where('is_active', true),
            ],
            'reactivation_journey_template_id' => [
                'nullable',
                'integer',
                Rule::exists('journey_templates', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('type', 'reactivation')
                    ->where('trigger_type', 'sales_inactivity')
                    ->where('is_active', true),
            ],
        ];
    }
}
