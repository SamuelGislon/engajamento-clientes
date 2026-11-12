<?php

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $sold_at
 * @property int|string|null $purchase_count
 * @property int|string|null $total_amount
 * @property string|null $last_sold_at
 */
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'company_id', 'seller_id', 'created_by', 'external_id', 'description',
        'amount', 'currency', 'sold_at', 'status', 'notes', 'cancelled_at', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sold_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<Sale>  $query
     * @return Builder<Sale>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->roleIn(app(TenantContext::class)->id()) === 'salesperson') {
            $query->whereHas('company', fn (Builder $companies) => $companies->where('owner_user_id', $user->id));
        }

        return $query;
    }
}
