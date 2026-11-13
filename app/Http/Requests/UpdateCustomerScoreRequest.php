<?php

namespace App\Http\Requests;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerScoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('company')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $weights = app(CommercialSettingsService::class)->forOrganization($organization)->scoring_weights;

        return [
            'potential_points' => ['sometimes', 'nullable', 'integer', 'between:0,'.$weights['potential']],
            'relationship_points' => ['sometimes', 'nullable', 'integer', 'between:0,'.$weights['relationship']],
        ];
    }
}
