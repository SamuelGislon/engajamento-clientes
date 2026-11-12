<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $type
 * @property string $occurrence_key
 * @property string $status
 * @property string $reason
 * @property CarbonImmutable $anchor_at
 * @property CarbonImmutable $first_detected_at
 * @property CarbonImmutable|null $resolved_at
 * @property Task|null $task
 */
class CommercialAlertOccurrence extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'company_id', 'task_id', 'recommended_contact_id', 'type', 'occurrence_key',
        'anchor_at', 'status', 'reason', 'first_detected_at', 'resolved_at', 'resolution_reason',
    ];

    protected function casts(): array
    {
        return [
            'anchor_at' => 'immutable_datetime',
            'first_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function recommendedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recommended_contact_id');
    }
}
