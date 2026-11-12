<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerScoreHistory extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'company_id', 'volume_points', 'frequency_points', 'potential_points',
        'relationship_points', 'total_score', 'tier', 'is_complete', 'reason', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'volume_points' => 'integer',
            'frequency_points' => 'integer',
            'potential_points' => 'integer',
            'relationship_points' => 'integer',
            'total_score' => 'integer',
            'is_complete' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
