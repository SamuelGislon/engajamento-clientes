<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $attempt_number
 * @property int $processed_companies
 * @property int|null $last_company_id
 * @property string $reason
 * @property string $status
 * @property array{
 *     scoring_weights: array{volume: int, frequency: int, potential: int, relationship: int},
 *     volume_score_bands: list<array{max_amount: numeric-string|null, points: int}>,
 *     frequency_score_bands: list<array{min_purchases: int, points: int}>,
 *     tiers: list<array{key: string, name?: string, min_score: int, max_score: int, color: string}>
 * } $settings_snapshot
 */
class CommercialScoreRecalculation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'requested_by',
        'status',
        'attempt_number',
        'total_companies',
        'processed_companies',
        'last_company_id',
        'reason',
        'settings_snapshot',
        'last_error',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_companies' => 'integer',
            'attempt_number' => 'integer',
            'processed_companies' => 'integer',
            'last_company_id' => 'integer',
            'settings_snapshot' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
