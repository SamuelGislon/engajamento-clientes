<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\JourneyStepExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $status
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $completed_at
 */
class JourneyStepExecution extends Model
{
    /** @use HasFactory<JourneyStepExecutionFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'journey_enrollment_id', 'journey_step_id', 'status', 'scheduled_at', 'activated_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<JourneyEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(JourneyEnrollment::class, 'journey_enrollment_id');
    }

    /** @return BelongsTo<JourneyStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(JourneyStep::class, 'journey_step_id');
    }

    /** @return HasOne<Task, $this> */
    public function task(): HasOne
    {
        return $this->hasOne(Task::class);
    }
}
