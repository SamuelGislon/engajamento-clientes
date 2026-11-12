<?php

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read CompanyContact $pivot */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name', 'email', 'phone', 'whatsapp', 'job_title', 'preferred_channel',
        'contact_preferences', 'professional_interests', 'notes', 'status',
    ];

    /** @return BelongsToMany<Company, $this, CompanyContact, 'pivot'> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_contacts')
            ->using(CompanyContact::class)
            ->withPivot('id', 'organization_id', 'roles', 'is_primary', 'notes')
            ->withTimestamps();
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('occurred_at');
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $role = $user->roleIn(app(TenantContext::class)->id());

        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        if (! in_array($role, ['manager', 'salesperson'], true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('companies', fn (Builder $companies) => $companies->visibleTo($user));
    }
}
