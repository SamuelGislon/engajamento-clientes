<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\JourneyTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JourneyTemplate extends Model
{
    /** @use HasFactory<JourneyTemplateFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['name', 'description', 'type', 'trigger_type', 'is_active', 'is_default'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean'];
    }

    /** @return HasMany<JourneyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(JourneyStep::class)->orderBy('position');
    }

    /** @return HasMany<JourneyEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(JourneyEnrollment::class);
    }
}
