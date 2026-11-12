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
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int|null $last_confirmed_sale_id
 * @property string|null $last_confirmed_sale_at
 * @property int|null $last_activity_id
 * @property string|null $last_activity_at
 * @property string|null $activities_max_occurred_at
 * @property string|null $last_purchase_at
 * @property string|null $volume_12_months
 * @property int|null $purchase_count_12_months
 */
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

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasOne<CustomerScore, $this> */
    public function customerScore(): HasOne
    {
        return $this->hasOne(CustomerScore::class);
    }

    /** @return HasMany<CustomerScoreHistory, $this> */
    public function customerScoreHistory(): HasMany
    {
        return $this->hasMany(CustomerScoreHistory::class)->latest('recorded_at');
    }

    /** @return HasMany<CommercialAlertOccurrence, $this> */
    public function commercialAlertOccurrences(): HasMany
    {
        return $this->hasMany(CommercialAlertOccurrence::class);
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
