<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['name', 'description', 'status', 'created_by'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_memberships')
            ->withPivot(['organization_id', 'role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function openInvitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class)
            ->where('status', 'pending')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at');
    }

    public function isManagedBy(User $user): bool
    {
        return $this->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'manager')
            ->wherePivot('is_active', true)
            ->exists();
    }
}
