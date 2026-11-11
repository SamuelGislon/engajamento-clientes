<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\JourneyStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JourneyStep extends Model
{
    /** @use HasFactory<JourneyStepFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'journey_template_id', 'title', 'description', 'position', 'delay_days',
        'task_title', 'task_description', 'task_type', 'task_priority',
    ];

    /** @return BelongsTo<JourneyTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(JourneyTemplate::class, 'journey_template_id');
    }

    /** @return HasMany<JourneyStepExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(JourneyStepExecution::class);
    }
}
