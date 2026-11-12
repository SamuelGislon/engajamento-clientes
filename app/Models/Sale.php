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
use Illuminate\Support\Facades\DB;

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
        'company_id', 'seller_id', 'team_id_at_sale', 'created_by', 'external_id', 'description',
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

    /** @return BelongsTo<Team, $this> */
    public function teamAtSale(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id_at_sale');
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
        $organizationId = app(TenantContext::class)->id();
        $role = $user->roleIn($organizationId);

        if ($organizationId === null || $role === null) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        if ($role === 'salesperson') {
            return $query->where(fn (Builder $sales) => $sales
                ->where('seller_id', $user->id)
                ->orWhereHas('company', fn (Builder $companies) => $companies->visibleTo($user)));
        }

        if ($role !== 'manager') {
            return $query->whereRaw('1 = 0');
        }

        $managedTeamIds = DB::table('team_memberships')
            ->select('team_id')
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->whereExists(fn ($teams) => $teams->selectRaw('1')
                ->from('teams')
                ->whereColumn('teams.id', 'team_memberships.team_id')
                ->whereColumn('teams.organization_id', 'team_memberships.organization_id')
                ->where('teams.status', 'active'));

        return $query->where(fn (Builder $sales) => $sales
            ->whereIn('team_id_at_sale', $managedTeamIds)
            ->orWhere(fn (Builder $legacySales) => $legacySales
                ->whereNull('team_id_at_sale')
                ->whereHas('company', fn (Builder $companies) => $companies->visibleTo($user))));
    }
}
