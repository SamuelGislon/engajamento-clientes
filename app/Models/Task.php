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

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->roleIn(app(TenantContext::class)->id()) === 'salesperson') {
            $query->where('assigned_to', $user->id)
                ->where(function (Builder $nested) use ($user): void {
                    $nested->whereNull('company_id')
                        ->orWhereHas('company', fn (Builder $companies) => $companies->where('owner_user_id', $user->id));
                });
        }

        return $query;
    }
}
