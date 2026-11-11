<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\JourneyTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveJourneyTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $template = $this->route('template');
        $ability = $template instanceof JourneyTemplate ? 'update' : 'create';

        return $this->user()?->can($ability, $template instanceof JourneyTemplate ? $template : JourneyTemplate::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        $template = $this->route('template');
        $hasEnrollments = $template instanceof JourneyTemplate && $template->enrollments()->exists();

        return [
            'name' => ['required', 'string', 'max:180', Rule::unique('journey_templates', 'name')
                ->where('organization_id', app(TenantContext::class)->id())
                ->ignore($template instanceof JourneyTemplate ? $template->id : null)],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'steps' => [$hasEnrollments ? 'prohibited' : 'required', 'array', 'min:1', 'max:20'],
            'steps.*.title' => ['required', 'string', 'max:180'],
            'steps.*.description' => ['nullable', 'string', 'max:2000'],
            'steps.*.delay_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'steps.*.task_title' => ['required', 'string', 'max:180'],
            'steps.*.task_description' => ['nullable', 'string', 'max:5000'],
            'steps.*.task_type' => ['required', Rule::in(['first_contact', 'call', 'whatsapp', 'email', 'meeting', 'visit', 'prospecting', 'follow_up', 'reactivation', 'custom'])],
            'steps.*.task_priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ];
    }
}
