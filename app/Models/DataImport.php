<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\DataImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $organization_id
 * @property int $created_by
 * @property int|null $team_id
 * @property array<string, int>|null $mapping
 * @property list<array{line: int, type: string, message: string}>|null $validation_errors
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $confirmed_at
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
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'created_rows',
        'updated_rows',
        'skipped_rows',
        'duplicate_rows',
        'expires_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'mapping' => 'array',
            'validation_errors' => 'array',
            'confirmed_at' => 'immutable_datetime',
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
}
