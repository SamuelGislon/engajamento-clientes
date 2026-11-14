<?php

namespace App\Jobs;

use App\Domain\Imports\CompanyImportService;
use App\Domain\Imports\ContactImportService;
use App\Domain\Imports\DataImportProcessor;
use App\Domain\Imports\ImportFileException;
use App\Domain\Imports\SaleImportService;
use App\Domain\Imports\SpreadsheetPreviewReader;
use App\Domain\Tenancy\TenantContext;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ValidateDataImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $organizationId, public int $dataImportId, public int $attemptNumber) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('data-import-validation-'.$this->organizationId.'-'.$this->dataImportId.'-'.$this->attemptNumber))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    public function handle(
        SpreadsheetPreviewReader $previewReader,
        CompanyImportService $companyImports,
        ContactImportService $contactImports,
        SaleImportService $saleImports,
        DataImportProcessor $processor,
    ): void {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant = app(TenantContext::class);
        $tenant->set($organization);

        try {
            $batch = $this->claimValidation($organization);

            if ($batch === null) {
                return;
            }

            try {
                if ($batch->expires_at->isPast()) {
                    $this->expire($batch);

                    return;
                }

                $actor = $batch->creator;

                if (! $actor instanceof User) {
                    throw new AuthorizationException;
                }

                Gate::forUser($actor)->authorize('update', $batch);

                $expectedPathPrefix = 'imports/'.$this->organizationId.'/';

                if ($batch->disk !== 'local' || ! Str::startsWith($batch->file_path, $expectedPathPrefix)) {
                    throw new ImportFileException('O arquivo temporário não está disponível para validação. Envie-o novamente.');
                }

                $disk = Storage::disk('local');

                if (! $disk->exists($batch->file_path)) {
                    throw new ImportFileException('O arquivo temporário não está mais disponível. Envie-o novamente para continuar.');
                }

                $format = strtolower(pathinfo($batch->original_filename, PATHINFO_EXTENSION));
                $preview = $previewReader->preview(
                    $disk->path($batch->file_path),
                    $format,
                    SpreadsheetPreviewReader::MAX_ROWS,
                    onRowRead: function (int $rowsRead): void {
                        if ($rowsRead % 100 !== 0) {
                            return;
                        }

                        DB::table('data_imports')
                            ->where('organization_id', $this->organizationId)
                            ->where('id', $this->dataImportId)
                            ->where('processing_attempts', $this->attemptNumber)
                            ->where('status', 'validating')
                            ->update([
                                'processed_rows' => $rowsRead,
                                'processing_heartbeat_at' => Carbon::now('UTC'),
                            ]);
                    },
                );

                $this->updateProgress($preview['totalRows']);

                $importService = match ($batch->record_type) {
                    'companies' => $companyImports,
                    'contacts' => $contactImports,
                    'sales' => $saleImports,
                    default => throw new ImportFileException('O tipo de registro deste arquivo não pode ser validado.'),
                };

                $mapping = $batch->mapping;
                $duplicateResolutions = $batch->duplicate_resolutions;
                $analysis = $importService->analyze(
                    $batch,
                    $actor,
                    $preview['rows'],
                    $preview['totalRows'],
                    false,
                    $preview['malformedRows'],
                );

                DB::transaction(function () use ($actor, $mapping, $duplicateResolutions, $analysis, $processor): void {
                    $lockedBatch = DataImport::query()
                        ->where('organization_id', $this->organizationId)
                        ->lockForUpdate()
                        ->find($this->dataImportId);

                    if ($lockedBatch === null
                        || $lockedBatch->processing_attempts !== $this->attemptNumber
                        || $lockedBatch->status !== 'validating') {
                        return;
                    }

                    Gate::forUser($actor)->authorize('update', $lockedBatch);

                    if ($lockedBatch->mapping !== $mapping || $lockedBatch->duplicate_resolutions !== $duplicateResolutions) {
                        throw ValidationException::withMessages([
                            'file' => 'O mapeamento ou as decisões de duplicidade mudaram durante a validação. Valide o lote novamente.',
                        ]);
                    }

                    if ($lockedBatch->expires_at->isPast()) {
                        $this->expire($lockedBatch);

                        return;
                    }

                    $validRows = $analysis['totalRows'] > DataImportProcessor::ASYNC_ROW_THRESHOLD
                        ? $analysis['validRows']
                        : [];
                    $processor->stageRows($lockedBatch, $validRows);

                    $lockedBatch->update([
                        'status' => 'validated',
                        'total_rows' => $analysis['totalRows'],
                        'valid_rows' => count($analysis['validRows']),
                        'processed_rows' => 0,
                        'processing_attempts' => 0,
                        'queue_dispatches' => 0,
                        'last_processed_line' => null,
                        'last_error' => null,
                        'processing_started_at' => null,
                        'processing_heartbeat_at' => null,
                        'failed_at' => null,
                        'invalid_rows' => $analysis['invalidRows'],
                        'duplicate_rows' => $analysis['duplicateRows'],
                        'skipped_rows' => $analysis['invalidRows'] + $analysis['duplicateRows'],
                        'validation_errors' => $analysis['errors'],
                    ]);
                });
            } catch (ImportFileException $exception) {
                $this->failValidation($exception->getMessage());
            } catch (AuthorizationException|ValidationException) {
                $this->failValidation('O acesso ou as regras do lote mudaram durante a validação. Revise o lote e tente novamente.');
            }
        } finally {
            $tenant->clear();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->failValidation('A validação foi interrompida após várias tentativas. Você pode validar o lote novamente.');
    }

    private function claimValidation(Organization $organization): ?DataImport
    {
        return DB::transaction(function () use ($organization): ?DataImport {
            $batch = DataImport::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->find($this->dataImportId);

            if ($batch === null
                || $batch->processing_attempts !== $this->attemptNumber
                || ! in_array($batch->status, ['validation_queued', 'validating'], true)) {
                return null;
            }

            if ($batch->expires_at->isPast()) {
                $this->expire($batch);

                return null;
            }

            $batch->update([
                'status' => 'validating',
                'processing_started_at' => $batch->processing_started_at ?? Carbon::now('UTC'),
                'processing_heartbeat_at' => Carbon::now('UTC'),
                'last_error' => null,
                'failed_at' => null,
            ]);

            return $batch->fresh();
        });
    }

    private function updateProgress(int $rowsRead): void
    {
        DB::table('data_imports')
            ->where('organization_id', $this->organizationId)
            ->where('id', $this->dataImportId)
            ->where('processing_attempts', $this->attemptNumber)
            ->where('status', 'validating')
            ->update([
                'processed_rows' => $rowsRead,
                'processing_heartbeat_at' => Carbon::now('UTC'),
            ]);
    }

    private function expire(DataImport $batch): void
    {
        $batch->update([
            'status' => 'expired',
            'last_error' => 'O prazo de retenção do arquivo terminou antes da validação.',
            'failed_at' => Carbon::now('UTC'),
            'processing_started_at' => null,
            'processing_heartbeat_at' => null,
        ]);
        $batch->rows()->delete();
    }

    private function failValidation(string $message): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant = app(TenantContext::class);
        $tenant->set($organization);

        try {
            DB::transaction(function () use ($message): void {
                $batch = DataImport::query()
                    ->where('organization_id', $this->organizationId)
                    ->lockForUpdate()
                    ->find($this->dataImportId);

                if ($batch === null
                    || $batch->processing_attempts !== $this->attemptNumber
                    || ! in_array($batch->status, ['validation_queued', 'validating'], true)) {
                    return;
                }

                $batch->update([
                    'status' => 'validation_failed',
                    'failed_at' => Carbon::now('UTC'),
                    'processing_started_at' => null,
                    'processing_heartbeat_at' => null,
                    'last_error' => $message,
                ]);
            });
        } finally {
            $tenant->clear();
        }
    }
}
