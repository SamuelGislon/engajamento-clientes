<?php

namespace App\Domain\Imports;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Journeys\JourneyService;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\JourneyTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * @phpstan-import-type ContactImportError from ContactImportService
 *
 * @phpstan-type CompanyImportRow array{line: int, attributes: array<string, mixed>, resolution?: array{action: string, target_id?: int}}
 * @phpstan-type ContactImportRow array{line: int, identity: string, attributes: array<string, mixed>, company_id: int, roles: list<string>, is_primary: bool, relationship_notes: string|null, resolution?: array{action: string, target_id?: int}}
 * @phpstan-type SaleImportRow array{line: int, external_id: string|null, company_id: int, seller_id: int, description: string, amount: string, currency: string, sold_at: string, status: string, notes: string|null, resolution?: array{action: string, target_id?: int}}
 * @phpstan-type ImportValidationError array{line: int, type: string, message: string, candidates?: list<array{id: int, name: string, email_masked?: string|null, phone_masked?: string|null, city?: string|null, state?: string|null, tax_id_masked?: string|null, detail?: string}>, can_create?: bool, can_update?: bool, duplicate_source?: 'file'|'database', lines?: list<int>}
 */
class DataImportProcessor
{
    public const ASYNC_ROW_THRESHOLD = 250;

    public const ROWS_PER_CHUNK = 100;

    /**
     * @param  list<array<string, mixed>>  $validRows
     *
     * @throws JsonException
     */
    public function stageRows(DataImport $batch, array $validRows): void
    {
        $batch->rows()->delete();
        $now = now();
        $stagedRows = [];

        foreach ($validRows as $validRow) {
            $lineNumber = (int) $validRow['line'];
            $groupKey = $batch->record_type === 'contacts'
                ? (string) $validRow['identity']
                : 'line:'.$lineNumber;

            $stagedRows[] = [
                'organization_id' => $batch->organization_id,
                'data_import_id' => $batch->id,
                'line_number' => $lineNumber,
                'group_key' => $groupKey,
                'status' => 'pending',
                'encrypted_payload' => Crypt::encryptString(json_encode($validRow, JSON_THROW_ON_ERROR)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($stagedRows, self::ROWS_PER_CHUNK) as $rows) {
            DB::table('data_import_rows')->insert($rows);
        }
    }

    /**
     * @return EloquentCollection<int, DataImportRow>
     */
    public function nextRows(DataImport $batch): EloquentCollection
    {
        $firstRows = $batch->rows()
            ->where('status', 'pending')
            ->orderBy('line_number')
            ->limit(self::ROWS_PER_CHUNK)
            ->get();

        if ($batch->record_type !== 'contacts' || $firstRows->isEmpty()) {
            return $firstRows;
        }

        return $batch->rows()
            ->where('status', 'pending')
            ->whereIn('group_key', $firstRows->pluck('group_key')->unique()->all())
            ->orderBy('line_number')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, DataImportRow>  $rows
     * @return list<array<string, mixed>>
     */
    public function decodeRows(EloquentCollection $rows): array
    {
        $decodedRows = [];

        foreach ($rows as $row) {
            if ($row->encrypted_payload === null) {
                throw new RuntimeException('The staged import row has no payload.');
            }

            $payload = json_decode(Crypt::decryptString($row->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new RuntimeException('The staged import row is invalid.');
            }

            $decodedRows[] = $payload;
        }

        return $decodedRows;
    }

    /**
     * @param  EloquentCollection<int, DataImportRow>  $stagedRows
     * @return array{createdRows: int, updatedRows: int, duplicateRows: int, invalidRows: int, errors: list<ImportValidationError>}
     */
    public function importRows(
        DataImport $batch,
        User $actor,
        EloquentCollection $stagedRows,
        ?JourneyTemplate $journeyTemplate,
        CompanyImportService $companies,
        ContactImportService $contacts,
        SaleImportService $sales,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
        JourneyService $journeys,
    ): array {
        return match ($batch->record_type) {
            'companies' => $companies->import($batch, $actor, $this->companyRows($stagedRows), $scores, $journeyTemplate, $journeys),
            'contacts' => $contacts->import($batch, $actor, $this->contactRows($stagedRows)),
            'sales' => $sales->import($batch, $actor, $this->saleRows($stagedRows), $scores, $inactivity, $journeys),
            default => throw new RuntimeException('The import record type is unsupported.'),
        };
    }

    /**
     * @param  EloquentCollection<int, DataImportRow>  $stagedRows
     * @return list<CompanyImportRow>
     */
    private function companyRows(EloquentCollection $stagedRows): array
    {
        $validRows = [];

        foreach ($this->decodeRows($stagedRows) as $row) {
            if (! is_int($row['line'] ?? null) || ! is_array($row['attributes'] ?? null)) {
                throw new RuntimeException('The staged company import row is invalid.');
            }

            $resolution = null;
            $rawResolution = $row['resolution'] ?? null;
            if ($rawResolution !== null) {
                if (! is_array($rawResolution)) {
                    throw new RuntimeException('The staged company import resolution is invalid.');
                }

                $action = $rawResolution['action'] ?? null;
                $targetId = $rawResolution['target_id'] ?? null;

                if (! is_string($action)
                    || ! in_array($action, ['create', 'update', 'resolve'], true)
                    || ($targetId !== null && ! is_int($targetId))) {
                    throw new RuntimeException('The staged company import resolution is invalid.');
                }

                $resolution = [
                    'action' => $action,
                    ...(is_int($targetId) ? ['target_id' => $targetId] : []),
                ];
            }

            $attributes = [];
            foreach ($row['attributes'] as $key => $value) {
                if (! is_string($key)) {
                    throw new RuntimeException('The staged company import attributes are invalid.');
                }

                $attributes[$key] = $value;
            }

            $validRows[] = [
                'line' => $row['line'],
                'attributes' => $attributes,
                ...($resolution !== null ? ['resolution' => $resolution] : []),
            ];
        }

        return $validRows;
    }

    /**
     * @param  EloquentCollection<int, DataImportRow>  $stagedRows
     * @return list<ContactImportRow>
     */
    private function contactRows(EloquentCollection $stagedRows): array
    {
        $validRows = [];

        foreach ($this->decodeRows($stagedRows) as $row) {
            $roles = $row['roles'] ?? null;
            $relationshipNotes = $row['relationship_notes'] ?? null;
            $resolution = null;
            $rawResolution = $row['resolution'] ?? null;

            if ($rawResolution !== null) {
                if (! is_array($rawResolution)) {
                    throw new RuntimeException('The staged contact import resolution is invalid.');
                }

                $action = $rawResolution['action'] ?? null;
                $targetId = $rawResolution['target_id'] ?? null;

                if (! is_string($action)
                    || ! in_array($action, ['create', 'update', 'resolve'], true)
                    || ($targetId !== null && ! is_int($targetId))) {
                    throw new RuntimeException('The staged contact import resolution is invalid.');
                }

                $resolution = [
                    'action' => $action,
                    ...(is_int($targetId) ? ['target_id' => $targetId] : []),
                ];
            }

            if (! is_int($row['line'] ?? null)
                || ! is_string($row['identity'] ?? null)
                || ! is_array($row['attributes'] ?? null)
                || ! is_int($row['company_id'] ?? null)
                || ! is_array($roles)
                || ! is_bool($row['is_primary'] ?? null)
                || (! is_string($relationshipNotes) && $relationshipNotes !== null)) {
                throw new RuntimeException('The staged contact import row is invalid.');
            }

            $contactRoles = [];
            foreach ($roles as $role) {
                if (! is_string($role)) {
                    throw new RuntimeException('The staged contact roles are invalid.');
                }

                $contactRoles[] = $role;
            }

            $attributes = [];
            foreach ($row['attributes'] as $key => $value) {
                if (! is_string($key)) {
                    throw new RuntimeException('The staged contact attributes are invalid.');
                }

                $attributes[$key] = $value;
            }

            $validRows[] = [
                'line' => $row['line'],
                'identity' => $row['identity'],
                'attributes' => $attributes,
                'company_id' => $row['company_id'],
                'roles' => $contactRoles,
                'is_primary' => $row['is_primary'],
                'relationship_notes' => $relationshipNotes,
                ...($resolution !== null ? ['resolution' => $resolution] : []),
            ];
        }

        return $validRows;
    }

    /**
     * @param  EloquentCollection<int, DataImportRow>  $stagedRows
     * @return list<SaleImportRow>
     */
    private function saleRows(EloquentCollection $stagedRows): array
    {
        $validRows = [];

        foreach ($this->decodeRows($stagedRows) as $row) {
            $externalId = $row['external_id'] ?? null;
            $notes = $row['notes'] ?? null;
            $resolution = null;
            $rawResolution = $row['resolution'] ?? null;

            if ($rawResolution !== null) {
                if (! is_array($rawResolution)
                    || ($rawResolution['action'] ?? null) !== 'update'
                    || ! is_int($rawResolution['target_id'] ?? null)) {
                    throw new RuntimeException('The staged sale import resolution is invalid.');
                }

                $resolution = [
                    'action' => 'update',
                    'target_id' => $rawResolution['target_id'],
                ];
            }

            if (! is_int($row['line'] ?? null)
                || (! is_string($externalId) && $externalId !== null)
                || ! is_int($row['company_id'] ?? null)
                || ! is_int($row['seller_id'] ?? null)
                || ! is_string($row['description'] ?? null)
                || ! is_string($row['amount'] ?? null)
                || ! is_string($row['currency'] ?? null)
                || ! is_string($row['sold_at'] ?? null)
                || ! is_string($row['status'] ?? null)
                || (! is_string($notes) && $notes !== null)) {
                throw new RuntimeException('The staged sale import row is invalid.');
            }

            $validRows[] = [
                'line' => $row['line'],
                'external_id' => $externalId,
                'company_id' => $row['company_id'],
                'seller_id' => $row['seller_id'],
                'description' => $row['description'],
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'sold_at' => $row['sold_at'],
                'status' => $row['status'],
                'notes' => $notes,
                ...($resolution !== null ? ['resolution' => $resolution] : []),
            ];
        }

        return $validRows;
    }
}
