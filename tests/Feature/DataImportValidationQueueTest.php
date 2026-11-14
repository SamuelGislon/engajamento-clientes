<?php

use App\Domain\Imports\CompanyImportService;
use App\Domain\Imports\SpreadsheetPreviewReader;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ValidateDataImport;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('large import validation is queued without analyzing rows during the request', function (): void {
    Bus::fake();
    config(['queue.default' => 'redis']);
    Storage::fake('local');

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $path = 'imports/'.$organization->id.'/large-validation.csv';
    Storage::disk('local')->put($path, "Name\n".implode("\n", array_map(
        static fn (int $number): string => 'Empresa para validar '.$number,
        range(1, 251),
    )));
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'status' => 'mapped',
        'mapping' => ['name' => 0],
        'file_path' => $path,
    ]);
    app()->instance(CompanyImportService::class, Mockery::mock(CompanyImportService::class)
        ->shouldNotReceive('analyze')
        ->getMock());

    $this->actingAs($owner)->withSession(['active_organization_id' => $organization->id])
        ->post('/imports/'.$batch->id.'/validate')
        ->assertRedirect('/imports/'.$batch->id);

    expect($batch->fresh()->status)->toBe('validation_queued')
        ->and($batch->fresh()->processed_rows)->toBe(0)
        ->and($batch->fresh()->processing_attempts)->toBe(1);

    Bus::assertDispatched(ValidateDataImport::class, fn (ValidateDataImport $job): bool => $job->organizationId === $organization->id
        && $job->dataImportId === $batch->id
        && $job->attemptNumber === 1);
});

test('queued validation analyzes and stages the import once in the tenant context', function (): void {
    Storage::fake('local');

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $path = 'imports/'.$organization->id.'/large-validation.csv';
    Storage::disk('local')->put($path, "Name\n".implode("\n", array_map(
        static fn (int $number): string => 'Empresa em lote '.$number,
        range(1, 251),
    )));
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'status' => 'validation_queued',
        'mapping' => ['name' => 0],
        'file_path' => $path,
        'processing_attempts' => 1,
    ]);
    app(TenantContext::class)->set($organization);

    $job = new ValidateDataImport($organization->id, $batch->id, 1);
    app()->call([$job, 'handle']);
    $batch->refresh();

    expect($batch->status)->toBe('validated')
        ->and($batch->total_rows)->toBe(251)
        ->and($batch->valid_rows)->toBe(251)
        ->and($batch->processed_rows)->toBe(0)
        ->and($batch->processing_attempts)->toBe(0)
        ->and(DB::table('data_import_rows')->where('data_import_id', $batch->id)->count())->toBe(251);

    app()->call([$job, 'handle']);
    expect(DB::table('data_import_rows')->where('data_import_id', $batch->id)->count())->toBe(251);
});

test('queued validation persists reader progress before a retryable analysis failure', function (): void {
    Storage::fake('local');

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $path = 'imports/'.$organization->id.'/analysis-retry.csv';
    Storage::disk('local')->put($path, "Name\n".implode("\n", array_map(
        static fn (int $number): string => 'Empresa em retentativa '.$number,
        range(1, 251),
    )));
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'status' => 'validation_queued',
        'mapping' => ['name' => 0],
        'file_path' => $path,
        'processing_attempts' => 1,
    ]);
    app(TenantContext::class)->set($organization);
    app()->instance(CompanyImportService::class, Mockery::mock(CompanyImportService::class)
        ->shouldReceive('analyze')
        ->once()
        ->andThrow(new RuntimeException('temporary database failure'))
        ->getMock());

    $job = new ValidateDataImport($organization->id, $batch->id, 1);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

    $batch->refresh();
    expect($batch->status)->toBe('validating')
        ->and($batch->processed_rows)->toBe(251)
        ->and($batch->processing_heartbeat_at)->not->toBeNull();

    $job->failed(new RuntimeException('temporary database failure'));
    expect($batch->fresh()->status)->toBe('validation_failed')
        ->and($batch->fresh()->last_error)->not->toContain('temporary database failure');
});

test('large import validation refuses the synchronous queue before changing the batch', function (): void {
    Bus::fake();
    config(['queue.default' => 'sync']);
    Storage::fake('local');

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $path = 'imports/'.$organization->id.'/large-validation.csv';
    Storage::disk('local')->put($path, "Name\n".implode("\n", array_map(
        static fn (int $number): string => 'Empresa protegida '.$number,
        range(1, 251),
    )));
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'status' => 'mapped',
        'mapping' => ['name' => 0],
        'file_path' => $path,
    ]);

    $this->actingAs($owner)->withSession(['active_organization_id' => $organization->id])
        ->from('/imports/'.$batch->id)
        ->post('/imports/'.$batch->id.'/validate')
        ->assertRedirect('/imports/'.$batch->id)
        ->assertSessionHasErrors('file');

    expect($batch->fresh()->status)->toBe('mapped');
    Bus::assertNothingDispatched();
});

test('reader stops at the requested sample limit and marks the preview incomplete', function (): void {
    Storage::fake('local');
    $path = 'imports/sample/large.csv';
    Storage::disk('local')->put($path, "Name\n".implode("\n", array_map(
        static fn (int $number): string => 'Linha '.$number,
        range(1, 1000),
    )));

    $preview = app(SpreadsheetPreviewReader::class)->preview(
        Storage::disk('local')->path($path),
        'csv',
        10,
        11,
    );

    expect($preview['complete'])->toBeFalse()
        ->and($preview['totalRows'])->toBe(11)
        ->and($preview['rows'])->toHaveCount(10);
});

test('validation failure from an oversized file is safe and can be queued again', function (): void {
    Bus::fake();
    config(['queue.default' => 'redis']);
    Storage::fake('local');

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $path = 'imports/'.$organization->id.'/oversized-validation.csv';
    Storage::disk('local')->put($path, "Name\n".implode("\n", array_map(
        static fn (int $number): string => 'Empresa além do limite '.$number,
        range(1, 5001),
    )));
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'status' => 'validation_queued',
        'mapping' => ['name' => 0],
        'file_path' => $path,
        'processing_attempts' => 1,
    ]);
    app(TenantContext::class)->set($organization);

    $job = new ValidateDataImport($organization->id, $batch->id, 1);
    app()->call([$job, 'handle']);
    $batch->refresh();

    expect($batch->status)->toBe('validation_failed')
        ->and($batch->last_error)->toContain('5.000 linhas')
        ->and($batch->last_error)->not->toContain('Empresa além do limite')
        ->and(DB::table('data_import_rows')->where('data_import_id', $batch->id)->count())->toBe(0);

    $this->actingAs($owner)->withSession(['active_organization_id' => $organization->id])
        ->post('/imports/'.$batch->id.'/validate')
        ->assertRedirect('/imports/'.$batch->id);

    expect($batch->fresh()->status)->toBe('validation_queued')
        ->and($batch->fresh()->processing_attempts)->toBe(2);
    Bus::assertDispatched(ValidateDataImport::class, fn (ValidateDataImport $retry): bool => $retry->attemptNumber === 2);
});
