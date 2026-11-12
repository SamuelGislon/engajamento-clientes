<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAssignmentHistory extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'company_id', 'previous_owner_id', 'new_owner_id', 'previous_team_id',
        'new_team_id', 'actor_id', 'reason', 'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function previousOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function newOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_owner_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function previousTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'previous_team_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function newTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'new_team_id');
    }
}
