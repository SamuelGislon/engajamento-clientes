<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $sales_inactivity_days
 * @property int $relationship_inactivity_days
 * @property int $reactivation_repeat_cooldown_days
 * @property array{A: int, B: int, C: int, D: int} $contact_frequency_days
 * @property array{volume: int, frequency: int, potential: int, relationship: int} $scoring_weights
 * @property list<array{max_amount: numeric-string|null, points: int}> $volume_score_bands
 * @property list<array{min_purchases: int, points: int}> $frequency_score_bands
 * @property list<array{key: string, name?: string, min_score: int, max_score: int, color: string}> $tiers
 * @property int|null $post_sale_journey_template_id
 * @property int|null $reactivation_journey_template_id
 */
class CommercialSetting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'sales_inactivity_days', 'relationship_inactivity_days', 'reactivation_repeat_cooldown_days',
        'contact_frequency_days', 'scoring_weights', 'volume_score_bands', 'frequency_score_bands',
        'tiers', 'post_sale_journey_template_id', 'reactivation_journey_template_id',
    ];

    protected function casts(): array
    {
        return [
            'sales_inactivity_days' => 'integer',
            'relationship_inactivity_days' => 'integer',
            'reactivation_repeat_cooldown_days' => 'integer',
            'contact_frequency_days' => 'array',
            'scoring_weights' => 'array',
            'volume_score_bands' => 'array',
            'frequency_score_bands' => 'array',
            'tiers' => 'array',
        ];
    }
}
