<?php

namespace App\Http\Requests;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommercialSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'scoring_weights' => ['sometimes', 'required', 'array:volume,frequency,potential,relationship'],
            'scoring_weights.volume' => ['required_with:scoring_weights', 'integer', 'between:0,100'],
            'scoring_weights.frequency' => ['required_with:scoring_weights', 'integer', 'between:0,100'],
            'scoring_weights.potential' => ['required_with:scoring_weights', 'integer', 'between:0,100'],
            'scoring_weights.relationship' => ['required_with:scoring_weights', 'integer', 'between:0,100'],
            'volume_score_bands' => ['sometimes', 'required', 'array', 'min:2', 'max:20'],
            'volume_score_bands.*' => ['required', 'array:max_amount,points'],
            'volume_score_bands.*.max_amount' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'volume_score_bands.*.points' => ['required', 'integer', 'between:0,100'],
            'frequency_score_bands' => ['sometimes', 'required', 'array', 'min:2', 'max:20'],
            'frequency_score_bands.*' => ['required', 'array:min_purchases,points'],
            'frequency_score_bands.*.min_purchases' => ['required', 'integer', 'between:0,1000000'],
            'frequency_score_bands.*.points' => ['required', 'integer', 'between:0,100'],
            'tiers' => ['sometimes', 'required', 'array', 'size:4'],
            'tiers.*' => ['required', 'array:key,name,min_score,max_score,color'],
            'tiers.*.key' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D'])],
            'tiers.*.name' => ['required', 'string', 'max:60'],
            'tiers.*.min_score' => ['required', 'integer', 'between:0,100'],
            'tiers.*.max_score' => ['required', 'integer', 'between:0,100'],
            'tiers.*.color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
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

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $organization = app(TenantContext::class)->organization();
            if ($organization === null) {
                return;
            }

            $defaults = app(CommercialSettingsService::class)->forOrganization($organization);
            $weights = $this->input('scoring_weights', $defaults->scoring_weights);
            $volumeBands = $this->input('volume_score_bands', $defaults->volume_score_bands);
            $frequencyBands = $this->input('frequency_score_bands', $defaults->frequency_score_bands);
            $tiers = $this->input('tiers', $defaults->tiers);

            if (array_sum($weights) !== 100) {
                $validator->errors()->add('scoring_weights', 'A soma dos pesos comerciais deve ser exatamente 100.');
            }

            $this->validateVolumeBands($validator, $volumeBands, $weights['volume']);
            $this->validateFrequencyBands($validator, $frequencyBands, $weights['frequency']);
            $this->validateTiers($validator, $tiers);
        }];
    }

    /** @param list<array{max_amount: numeric-string|null, points: int}> $bands */
    private function validateVolumeBands(Validator $validator, array $bands, int $maximumPoints): void
    {
        $previousAmount = null;
        $previousPoints = -1;
        $lastIndex = count($bands) - 1;

        foreach ($bands as $index => $band) {
            $amount = $band['max_amount'];
            $points = $band['points'];

            if ($points > $maximumPoints || $points < $previousPoints) {
                $validator->errors()->add('volume_score_bands', 'Os pontos por faturamento devem crescer sem ultrapassar o peso de volume.');
                break;
            }

            if ($index === $lastIndex) {
                if ($amount !== null || $points !== $maximumPoints) {
                    $validator->errors()->add('volume_score_bands', 'A última faixa de faturamento deve ser ilimitada e alcançar o peso de volume.');
                }
            } elseif ($amount === null || ($previousAmount !== null && bccomp((string) $amount, (string) $previousAmount, 2) <= 0)) {
                $validator->errors()->add('volume_score_bands', 'Os limites de faturamento devem crescer e somente a última faixa pode ser ilimitada.');
                break;
            }

            $previousAmount = $amount;
            $previousPoints = $points;
        }
    }

    /** @param list<array{min_purchases: int, points: int}> $bands */
    private function validateFrequencyBands(Validator $validator, array $bands, int $maximumPoints): void
    {
        if ($bands === []) {
            $validator->errors()->add('frequency_score_bands', 'Configure ao menos duas faixas de frequência.');

            return;
        }

        $previousMinimum = -1;
        $previousPoints = -1;

        foreach ($bands as $index => $band) {
            if (($index === 0 && $band['min_purchases'] !== 0)
                || $band['min_purchases'] <= $previousMinimum
                || $band['points'] < $previousPoints
                || $band['points'] > $maximumPoints) {
                $validator->errors()->add('frequency_score_bands', 'As faixas de frequência devem começar em zero, crescer em ordem e respeitar o peso.');

                return;
            }

            $previousMinimum = $band['min_purchases'];
            $previousPoints = $band['points'];
        }

        if ($bands[array_key_last($bands)]['points'] !== $maximumPoints) {
            $validator->errors()->add('frequency_score_bands', 'A última faixa de frequência deve alcançar o peso definido.');
        }
    }

    /** @param list<array{key: string, name: string, min_score: int, max_score: int, color: string}> $tiers */
    private function validateTiers(Validator $validator, array $tiers): void
    {
        $keys = array_column($tiers, 'key');
        usort($tiers, static fn (array $left, array $right): int => $left['min_score'] <=> $right['min_score']);
        $expectedMinimum = 0;

        if (count(array_unique($keys)) !== 4 || array_diff(['A', 'B', 'C', 'D'], $keys) !== []) {
            $validator->errors()->add('tiers', 'Configure uma faixa para cada código A, B, C e D.');

            return;
        }

        foreach ($tiers as $tier) {
            if ($tier['min_score'] !== $expectedMinimum || $tier['max_score'] < $tier['min_score']) {
                $validator->errors()->add('tiers', 'As faixas devem cobrir de 0 a 100 sem lacunas ou sobreposições.');

                return;
            }

            $expectedMinimum = $tier['max_score'] + 1;
        }

        if ($expectedMinimum !== 101) {
            $validator->errors()->add('tiers', 'As faixas devem cobrir de 0 a 100 sem lacunas ou sobreposições.');
        }
    }
}
