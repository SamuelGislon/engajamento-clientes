<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\DataImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $organization_id
 * @property int $created_by
 * @property int|null $team_id
 * @property array<string, int>|null $mapping
 * @property list<array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, city: string|null, state: string|null, tax_id_masked: string|null}>, can_create?: bool}>|null $validation_errors
 * @property array<int|string, array{action: string, target_id?: int}>|null $duplicate_resolutions
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int $processed_rows
 * @property int $processing_attempts
 * @property int $queue_dispatches
 * @property int|null $last_processed_line
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $processing_heartbeat_at
 * @property CarbonImmutable|null $failed_at
 */
class DataImport extends Model
{
    /** @use HasFactory<DataImportFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'created_by',
        'team_id',
        'journey_template_id',
        'record_type',
        'status',
        'original_filename',
        'disk',
        'file_path',
        'journey_mode',
        'mapping',
        'validation_errors',
        'duplicate_resolutions',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'created_rows',
        'updated_rows',
        'skipped_rows',
        'duplicate_rows',
        'processed_rows',
        'processing_attempts',
        'queue_dispatches',
        'last_processed_line',
        'last_error',
        'processing_started_at',
        'processing_heartbeat_at',
        'failed_at',
        'expires_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'mapping' => 'array',
            'validation_errors' => 'array',
            'duplicate_resolutions' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processing_heartbeat_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<JourneyTemplate, $this> */
    public function journeyTemplate(): BelongsTo
    {
        return $this->belongsTo(JourneyTemplate::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<DataImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(DataImportRow::class);
    }
}
