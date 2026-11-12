<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerScore extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'company_id', 'volume_points', 'frequency_points', 'potential_points',
        'relationship_points', 'total_score', 'tier', 'is_complete', 'last_calculated_at',
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
            'last_calculated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<CustomerScoreHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(CustomerScoreHistory::class)->latest('recorded_at');
    }
}
