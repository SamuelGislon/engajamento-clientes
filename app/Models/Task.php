<?php

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $company_id
 * @property int|null $contact_id
 * @property int|null $journey_enrollment_id
 * @property int $assigned_to
 * @property int $created_by
 * @property string $title
 * @property string $status
 * @property string $origin
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $due_soon_notified_at
 * @property CarbonImmutable|null $overdue_notified_at
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'company_id', 'contact_id', 'assigned_to', 'created_by', 'title', 'description',
        'type', 'priority', 'status', 'due_at', 'started_at', 'completed_at', 'completed_by',
        'completion_notes', 'origin', 'journey_enrollment_id', 'journey_step_execution_id',
        'due_soon_notified_at', 'overdue_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'due_soon_notified_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
        ];
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<JourneyEnrollment, $this> */
    public function journeyEnrollment(): BelongsTo
    {
        return $this->belongsTo(JourneyEnrollment::class);
    }

    /** @return BelongsTo<JourneyStepExecution, $this> */
    public function journeyStepExecution(): BelongsTo
    {
        return $this->belongsTo(JourneyStepExecution::class);
    }

    /** @return HasMany<CommercialAlertOccurrence, $this> */
    public function commercialAlertOccurrences(): HasMany
    {
        return $this->hasMany(CommercialAlertOccurrence::class);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
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
            return $query->where('assigned_to', $user->id)
                ->where(function (Builder $nested) use ($user): void {
                    $nested->whereNull('company_id')
                        ->orWhereHas('company', fn (Builder $companies) => $companies->visibleTo($user));
                });
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
        $managedSellerIds = DB::table('team_memberships')
            ->select('user_id')
            ->where('organization_id', $organizationId)
            ->whereIn('team_id', $managedTeamIds)
            ->where('role', 'salesperson')
            ->where('is_active', true);

        return $query->where(function (Builder $nested) use ($user, $managedSellerIds): void {
            $nested->whereHas('company', fn (Builder $companies) => $companies->visibleTo($user))
                ->orWhere(fn (Builder $standalone) => $standalone
                    ->whereNull('company_id')
                    ->whereIn('assigned_to', $managedSellerIds));
        });
    }
}
