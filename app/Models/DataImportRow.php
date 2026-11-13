<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImportRow extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'data_import_id',
        'line_number',
        'group_key',
        'status',
        'encrypted_payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DataImport, $this> */
    public function dataImport(): BelongsTo
    {
        return $this->belongsTo(DataImport::class);
    }
}
