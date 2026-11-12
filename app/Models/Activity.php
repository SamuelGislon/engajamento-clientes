<?php

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'company_id', 'contact_id', 'user_id', 'type', 'occurred_at',
        'description', 'result', 'next_action',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $organizationId = app(TenantContext::class)->id();
        $role = $user->roleIn($organizationId);

        if ($organizationId === null || $role === null) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        return $query->whereHas('company', fn (Builder $companies) => $companies->visibleTo($user));
    }
}
