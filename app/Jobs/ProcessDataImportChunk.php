<?php

namespace App\Jobs;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Imports\CompanyImportService;
use App\Domain\Imports\ContactImportService;
use App\Domain\Imports\DataImportProcessor;
use App\Domain\Imports\SaleImportService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\DataImport;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProcessDataImportChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $organizationId, public int $dataImportId, public int $attemptNumber) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        DataImportProcessor $processor,
        CompanyImportService $companyImports,
        ContactImportService $contactImports,
        SaleImportService $saleImports,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
        JourneyService $journeys,
    ): void {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant = app(TenantContext::class);
        $tenant->set($organization);

        try {
            $hasMoreRows = DB::transaction(function () use (
                $processor,
                $companyImports,
                $contactImports,
                $saleImports,
                $scores,
                $inactivity,
                $journeys,
                $organization,
            ): bool {
                $batch = DataImport::query()
                    ->where('organization_id', $this->organizationId)
                    ->lockForUpdate()
                    ->find($this->dataImportId);

                if ($batch === null
                    || $batch->processing_attempts !== $this->attemptNumber
                    || ! in_array($batch->status, ['queued', 'processing'], true)) {
                    return false;
                }

                Organization::query()->lockForUpdate()->findOrFail($organization->id);

                if ($batch->expires_at->isPast()) {
                    $batch->update([
                        'status' => 'expired',
                        'last_error' => 'O prazo de retenção do arquivo terminou antes do processamento.',
                        'failed_at' => Carbon::now('UTC'),
                        'processing_started_at' => null,
                        'processing_heartbeat_at' => null,
                    ]);
                    $batch->rows()->delete();

                    return false;
                }

                $actor = $batch->creator;

                if (! $actor instanceof User) {
                    throw new AuthorizationException;
                }

                Gate::forUser($actor)->authorize('update', $batch);

                $batch->update([
                    'status' => 'processing',
                    'processing_started_at' => $batch->processing_started_at ?? Carbon::now('UTC'),
                    'processing_heartbeat_at' => Carbon::now('UTC'),
                    'last_error' => null,
                    'failed_at' => null,
                ]);

                $rows = $processor->nextRows($batch);

                if ($rows->isEmpty()) {
                    if ($batch->processed_rows < $batch->total_rows) {
                        throw new RuntimeException('The staged import rows are incomplete.');
                    }

                    $batch->update([
                        'status' => 'completed',
                        'confirmed_at' => Carbon::now('UTC'),
                        'processing_started_at' => null,
                        'processing_heartbeat_at' => null,
                    ]);

                    return false;
                }

                $journeyTemplate = $this->journeyTemplate($batch, $organization, $journeys);
                $importResult = $processor->importRows(
                    $batch,
                    $actor,
                    $rows,
                    $journeyTemplate,
                    $companyImports,
                    $contactImports,
                    $saleImports,
                    $scores,
                    $inactivity,
                    $journeys,
                );

                $processedRows = $rows->count();
                $processedRowsIds = $rows->modelKeys();
                $rows->each(function ($row): void {
                    $row->update([
                        'status' => 'processed',
                        'encrypted_payload' => null,
                        'processed_at' => Carbon::now('UTC'),
                    ]);
                });

                $validationErrors = array_slice(array_merge(
                    $batch->validation_errors ?? [],
                    $importResult['errors'],
                ), 0, 100);

                $batch->increment('processed_rows', $processedRows);
                $batch->increment('created_rows', $importResult['createdRows']);
                $batch->increment('duplicate_rows', $importResult['duplicateRows']);
                $batch->increment('invalid_rows', $importResult['invalidRows']);
                $batch->increment('skipped_rows', $importResult['duplicateRows'] + $importResult['invalidRows']);
                $batch->update(['last_processed_line' => $rows->max('line_number'), 'validation_errors' => $validationErrors]);

                $hasPendingRows = $batch->rows()->where('status', 'pending')->exists();

                if (! $hasPendingRows) {
                    if ($batch->fresh()->processed_rows !== $batch->total_rows) {
                        throw new RuntimeException('The staged import row count does not match the analyzed file.');
                    }

                    $batch->update([
                        'status' => 'completed',
                        'confirmed_at' => Carbon::now('UTC'),
                        'processing_started_at' => null,
                        'processing_heartbeat_at' => null,
                    ]);

                    return false;
                }

                return true;
            });

            if ($hasMoreRows) {
                self::dispatch($this->organizationId, $this->dataImportId, $this->attemptNumber)->afterCommit();
            }
        } finally {
            $tenant->clear();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant = app(TenantContext::class);
        $tenant->set($organization);

        try {
            DB::transaction(function (): void {
                $batch = DataImport::query()
                    ->where('organization_id', $this->organizationId)
                    ->lockForUpdate()
                    ->find($this->dataImportId);

                if ($batch === null
                    || $batch->processing_attempts !== $this->attemptNumber
                    || ! in_array($batch->status, ['queued', 'processing'], true)) {
                    return;
                }

                $batch->update([
                    'status' => 'failed',
                    'failed_at' => Carbon::now('UTC'),
                    'processing_started_at' => null,
                    'processing_heartbeat_at' => null,
                    'last_error' => 'O processamento foi interrompido após esgotar as tentativas. Retome o lote para continuar.',
                ]);
            });
        } finally {
            $tenant->clear();
        }
    }

    private function journeyTemplate(DataImport $batch, Organization $organization, JourneyService $journeys): ?JourneyTemplate
    {
        if ($batch->record_type !== 'companies') {
            return null;
        }

        return match ($batch->journey_mode) {
            'none' => null,
            'default' => $journeys->ensureDefaultTemplate($organization),
            'template' => JourneyTemplate::query()
                ->where('organization_id', $this->organizationId)
                ->where('type', 'prospecting')
                ->lockForUpdate()
                ->find($batch->journey_template_id)
                ?? throw ValidationException::withMessages([
                    'journey_template_id' => 'O modelo selecionado deixou de estar disponível durante a importação.',
                ]),
            default => throw ValidationException::withMessages([
                'journey_mode' => 'A opção de jornada selecionada não é válida.',
            ]),
        };
    }
}
