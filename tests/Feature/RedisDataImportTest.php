<?php

use App\Domain\Imports\DataImportProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessDataImportChunk;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql' || config('queue.default') !== 'redis') {
        $this->markTestSkipped('Redis import processing is verified against PostgreSQL and Redis.');
    }
});

it('processes a staged company import through a Redis worker', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    app(TenantContext::class)->set($organization);
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'status' => 'queued',
        'total_rows' => 1,
        'valid_rows' => 1,
        'processing_attempts' => 1,
    ]);
    app(DataImportProcessor::class)->stageRows($batch, [[
        'line' => 2,
        'attributes' => ['name' => 'Empresa importada pelo worker Redis', 'relationship_status' => 'customer'],
    ]]);
    $queue = 'data-import-'.Str::uuid();

    ProcessDataImportChunk::dispatch($organization->id, $batch->id, 1)->onQueue($queue);

    $exitCode = Artisan::call('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--queue' => $queue,
        '--sleep' => 0,
        '--tries' => 1,
    ]);

    expect($exitCode)->toBe(0, Artisan::output())
        ->and($batch->refresh()->status)->toBe('completed')
        ->and($batch->processed_rows)->toBe(1)
        ->and($batch->created_rows)->toBe(1);
});
