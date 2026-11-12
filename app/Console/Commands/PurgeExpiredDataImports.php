<?php

namespace App\Console\Commands;

use App\Models\DataImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('data-imports:purge-expired')]
#[Description('Remove arquivos e mapeamentos de importações expiradas')]
class PurgeExpiredDataImports extends Command
{
    private const BATCH_SIZE = 500;

    public function handle(): int
    {
        $purged = 0;

        /**
         * This bounded maintenance scan intentionally crosses organizations so it can enforce each batch's
         * retention deadline without a request tenant context. Every candidate is expired and path-checked.
         *
         * @var list<DataImport> $expiredImports
         */
        $expiredImports = DataImport::withoutGlobalScopes()
            ->where('status', '<>', 'expired')
            ->where('expires_at', '<=', now())
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

            $updated = DataImport::withoutGlobalScopes()
                ->whereKey($dataImport->id)
                ->where('status', '<>', 'expired')
                ->where('file_path', $dataImport->file_path)
                ->update([
                    'status' => 'expired',
                    'original_filename' => 'Arquivo expirado',
                    'file_path' => '',
                    'mapping' => null,
                    'updated_at' => now(),
                ]);

            $purged += $updated;
        }

        $this->info("{$purged} lote(s) expirado(s) processado(s).");

        return self::SUCCESS;
    }
}
