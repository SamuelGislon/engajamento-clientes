<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\JourneyEnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $organization_id
 * @property int $company_id
 * @property int $assigned_to
 * @property string $status
 * @property CarbonImmutable|null $completed_at
 */
class JourneyEnrollment extends Model
{
    /** @use HasFactory<JourneyEnrollmentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'journey_template_id', 'company_id', 'assigned_to', 'status', 'enrolled_at',
        'completed_at', 'paused_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<JourneyTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(JourneyTemplate::class, 'journey_template_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<JourneyStepExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(JourneyStepExecution::class)->orderBy('id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
