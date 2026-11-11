<?php

namespace App\Domain\Journeys;

use App\Domain\Tenancy\TenantContext;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JourneyTemplateService
{
    /** @param array<string, mixed> $attributes */
    public function save(?JourneyTemplate $template, array $attributes): JourneyTemplate
    {
        return DB::transaction(function () use ($template, $attributes): JourneyTemplate {
            $organization = app(TenantContext::class)->organization();
            abort_if($organization === null, 403);
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            if ($template !== null && array_key_exists('steps', $attributes) && $template->enrollments()->exists()) {
                throw ValidationException::withMessages([
                    'steps' => 'As etapas não podem ser alteradas depois que o modelo tiver inscrições; crie um novo modelo para preservar o histórico.',
                ]);
            }

            $steps = Arr::pull($attributes, 'steps');
            $isActive = (bool) $attributes['is_active'];
            $isDefault = (bool) $attributes['is_default'] && $isActive;

            if ($isDefault) {
                JourneyTemplate::query()
                    ->where('is_default', true)
                    ->when($template !== null, fn ($query) => $query->where('id', '!=', $template->id))
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $template ??= new JourneyTemplate;
            $template->fill([
                ...$attributes,
                'type' => 'prospecting',
                'trigger_type' => $isDefault ? 'company_created' : 'manual',
                'is_default' => $isDefault,
            ]);
            $template->save();

            if (is_array($steps)) {
                $template->steps()->delete();

                foreach (array_values($steps) as $index => $step) {
                    $template->steps()->create([
                        ...$step,
                        'position' => $index + 1,
                    ]);
                }
            }

            return $template->load('steps');
        });
    }
}
