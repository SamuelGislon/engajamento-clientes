<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $expires_at */
class OrganizationInvitation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'email', 'role', 'team_id', 'token_hash', 'status', 'expires_at',
        'accepted_at', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The exact SHA-256 digest is a unique capability lookup before the tenant is known.
     * This global lookup is limited to one invitation; all following access uses its organization scope.
     */
    public static function findByRawToken(string $token): ?self
    {
        return static::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    public function displayStatus(): string
    {
        if ($this->accepted_at !== null || $this->status === 'accepted') {
            return 'accepted';
        }

        if ($this->revoked_at !== null || $this->status === 'revoked') {
            return 'revoked';
        }

        return $this->expires_at->isPast() ? 'expired' : 'pending';
    }
}
