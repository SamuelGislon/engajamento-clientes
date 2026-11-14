<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDataImportChunk;
use App\Jobs\ValidateDataImport;
use App\Models\DataImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('data-imports:purge-expired')]
#[Description('Remove arquivos e mapeamentos de importações expiradas')]
class PurgeExpiredDataImports extends Command
{
    private const BATCH_SIZE = 500;

    private const PROCESSING_LEASE_MINUTES = 15;

    private const QUEUED_RETRY_MINUTES = 10;

    private const QUEUE_DISPATCH_LIMIT = 3;

    public function handle(): int
    {
        $this->failStaleImports();
        $this->requeueStaleQueuedImports();
        $purged = 0;

        /**
         * This bounded maintenance scan intentionally crosses organizations so it can enforce each batch's
         * retention deadline without a request tenant context. Every candidate is expired and path-checked.
         *
         * @var list<DataImport> $expiredImports
         */
        $expiredImports = DataImport::withoutGlobalScopes()
            ->where('expires_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNotIn('status', ['processing', 'validating'])
                    ->orWhereRaw(
                        '(COALESCE(processing_heartbeat_at, processing_started_at) IS NULL OR COALESCE(processing_heartbeat_at, processing_started_at) <= ?)',
                        [now()->subMinutes(self::PROCESSING_LEASE_MINUTES)],
                    );
            })
            ->whereNotNull('file_path')
            ->where('file_path', '<>', '')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get()
            ->all();

        foreach ($expiredImports as $dataImport) {
            $expectedPrefix = 'imports/'.$dataImport->organization_id.'/';

            if ($dataImport->disk !== 'local' || ! Str::startsWith($dataImport->file_path, $expectedPrefix)) {
                $this->warn('Um lote expirado possui caminho de arquivo inesperado; ele foi ignorado.');

                continue;
            }

            $disk = Storage::disk('local');

            if ($disk->exists($dataImport->file_path) && ! $disk->delete($dataImport->file_path)) {
                $this->warn('Não foi possível remover um arquivo temporário expirado. O comando tentará novamente.');

                continue;
            }

            $updated = DB::transaction(function () use ($dataImport): int {
                $lockedImport = DataImport::withoutGlobalScopes()
                    ->where('organization_id', $dataImport->organization_id)
                    ->lockForUpdate()
                    ->find($dataImport->id);

                if ($lockedImport === null
                    || $lockedImport->expires_at->isFuture()
                    || $lockedImport->file_path !== $dataImport->file_path
                    || (in_array($lockedImport->status, ['processing', 'validating'], true) && $this->hasActiveWorkerLease($lockedImport))) {
                    return 0;
                }

                $lockedImport->update([
                    'status' => 'expired',
                    'original_filename' => 'Arquivo expirado',
                    'file_path' => '',
                    'mapping' => null,
                    'last_error' => null,
                    'processing_started_at' => null,
                    'processing_heartbeat_at' => null,
                    'queue_dispatches' => 0,
                    'updated_at' => now(),
                ]);

                DB::table('data_import_rows')
                    ->where('organization_id', $dataImport->organization_id)
                    ->where('data_import_id', $dataImport->id)
                    ->delete();

                return 1;
            });

            $purged += $updated;
        }

        $this->info("{$purged} lote(s) expirado(s) processado(s).");

        return self::SUCCESS;
    }

    private function failStaleImports(): void
    {
        // The scheduler is a bounded maintenance sweep across tenants; expired rows keep their tenant and are locked before mutation.
        $staleImports = DataImport::withoutGlobalScopes()
            ->whereIn('status', ['processing', 'validating'])
            ->where('expires_at', '>', now())
            ->whereRaw(
                '(COALESCE(processing_heartbeat_at, processing_started_at) IS NULL OR COALESCE(processing_heartbeat_at, processing_started_at) <= ?)',
                [now()->subMinutes(self::PROCESSING_LEASE_MINUTES)],
            )
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'organization_id']);

        foreach ($staleImports as $staleImport) {
            DB::transaction(function () use ($staleImport): void {
                $lockedImport = DataImport::withoutGlobalScopes()
                    ->where('organization_id', $staleImport->organization_id)
                    ->lockForUpdate()
                    ->find($staleImport->id);

                if ($lockedImport === null
                    || ! in_array($lockedImport->status, ['processing', 'validating'], true)
                    || $lockedImport->expires_at->isPast()
                    || $this->hasActiveWorkerLease($lockedImport)) {
                    return;
                }

                $isValidation = $lockedImport->status === 'validating';
                $lockedImport->update([
                    'status' => $isValidation ? 'validation_failed' : 'failed',
                    'processing_started_at' => null,
                    'processing_heartbeat_at' => null,
                    'failed_at' => now(),
                    'last_error' => $isValidation
                        ? 'A validação ficou sem atividade. Você pode validar o lote novamente.'
                        : 'O processamento ficou sem atividade e pode ser retomado a partir das linhas pendentes.',
                    'updated_at' => now(),
                ]);
            });
        }
    }

    private function requeueStaleQueuedImports(): void
    {
        $cutoff = now()->subMinutes(self::QUEUED_RETRY_MINUTES);
        $staleImports = DataImport::withoutGlobalScopes()
            ->whereIn('status', ['queued', 'validation_queued'])
            ->where('processing_attempts', '>', 0)
            ->where('expires_at', '>', now())
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'organization_id']);

        foreach ($staleImports as $staleImport) {
            /** @var array{organization_id: int, data_import_id: int, attempt_number: int, status: string}|null $jobDetails */
            $jobDetails = DB::transaction(function () use ($staleImport, $cutoff): ?array {
                $lockedImport = DataImport::withoutGlobalScopes()
                    ->where('organization_id', $staleImport->organization_id)
                    ->lockForUpdate()
                    ->find($staleImport->id);

                if ($lockedImport === null
                    || ! in_array($lockedImport->status, ['queued', 'validation_queued'], true)
                    || $lockedImport->expires_at->isPast()
                    || $lockedImport->updated_at->isAfter($cutoff)) {
                    return null;
                }

                if ($lockedImport->queue_dispatches >= self::QUEUE_DISPATCH_LIMIT) {
                    $isValidation = $lockedImport->status === 'validation_queued';
                    $lockedImport->update([
                        'status' => $isValidation ? 'validation_failed' : 'failed',
                        'failed_at' => now(),
                        'last_error' => $isValidation
                            ? 'A validação não iniciou após tentativas limitadas de envio; você pode validar o lote novamente.'
                            : 'O lote não iniciou após tentativas limitadas de envio para a fila; você pode retomá-lo para tentar novamente.',
                        'updated_at' => now(),
                    ]);

                    return null;
                }

                $lockedImport->forceFill([
                    'queue_dispatches' => $lockedImport->queue_dispatches + 1,
                    'updated_at' => now(),
                ])->save();

                return [
                    'organization_id' => $lockedImport->organization_id,
                    'data_import_id' => $lockedImport->id,
                    'attempt_number' => $lockedImport->processing_attempts,
                    'status' => $lockedImport->status,
                ];
            });

            if ($jobDetails !== null) {
                if ($jobDetails['status'] === 'validation_queued') {
                    ValidateDataImport::dispatch(
                        $jobDetails['organization_id'],
                        $jobDetails['data_import_id'],
                        $jobDetails['attempt_number'],
                    );
                } else {
                    ProcessDataImportChunk::dispatch(
                        $jobDetails['organization_id'],
                        $jobDetails['data_import_id'],
                        $jobDetails['attempt_number'],
                    );
                }
            }
        }
    }

    private function hasActiveWorkerLease(DataImport $dataImport): bool
    {
        $lease = $dataImport->processing_heartbeat_at ?? $dataImport->processing_started_at;

        return $lease !== null && $lease->isAfter(now()->subMinutes(self::PROCESSING_LEASE_MINUTES));
    }
}
