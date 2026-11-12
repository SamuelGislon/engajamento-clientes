<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property-read OrganizationMembership $pivot */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'timezone', 'auto_enroll_default_journey'];

    protected function casts(): array
    {
        return ['auto_enroll_default_journey' => 'boolean'];
    }

    /** @return BelongsToMany<User, $this, OrganizationMembership, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationMembership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasOne<CommercialSetting, $this> */
    public function commercialSetting(): HasOne
    {
        return $this->hasOne(CommercialSetting::class);
    }

    /** @return HasMany<JourneyTemplate, $this> */
    public function journeyTemplates(): HasMany
    {
        return $this->hasMany(JourneyTemplate::class);
    }

    /** @return HasMany<JourneyEnrollment, $this> */
    public function journeyEnrollments(): HasMany
    {
        return $this->hasMany(JourneyEnrollment::class);
    }
}
