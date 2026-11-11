<?php

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name', 'trade_name', 'legal_name', 'tax_id', 'segment', 'phone', 'email',
        'website', 'city', 'state', 'address', 'relationship_status', 'lead_source',
        'owner_user_id', 'notes',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsToMany<Contact, $this, CompanyContact, 'pivot'> */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'company_contacts')
            ->using(CompanyContact::class)
            ->withPivot('id', 'organization_id', 'roles', 'is_primary', 'notes')
            ->withTimestamps();
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('occurred_at');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->roleIn(app(TenantContext::class)->id()) === 'salesperson') {
            $query->where('owner_user_id', $user->id);
        }

        return $query;
    }
}
