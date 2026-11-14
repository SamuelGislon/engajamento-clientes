<?php

namespace Tests\Feature;

use App\Domain\Imports\DataImportProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessDataImportChunk;
use App\Jobs\ValidateDataImport;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeExpiredDataImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_import_files_and_mapping_are_removed_but_the_summary_is_retained(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);

        $path = 'imports/'.$organization->id.'/expired.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'original_filename' => 'customer-list.csv',
            'mapping' => ['name' => 0],
            'total_rows' => 12,
            'valid_rows' => 10,
            'invalid_rows' => 2,
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('local')->put($path, 'synthetic private data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $dataImport->refresh();
        $this->assertSame('expired', $dataImport->status);
        $this->assertSame('', $dataImport->file_path);
        $this->assertSame('Arquivo expirado', $dataImport->original_filename);
        $this->assertNull($dataImport->mapping);
        $this->assertSame(12, $dataImport->total_rows);
        $this->assertSame(10, $dataImport->valid_rows);
        $this->assertSame(2, $dataImport->invalid_rows);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_unexpired_import_files_are_left_in_private_storage(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);

        $path = 'imports/'.$organization->id.'/active.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'expires_at' => now()->addHour(),
        ]);
        Storage::disk('local')->put($path, 'synthetic private data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('uploaded', $dataImport->fresh()->status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_purging_the_same_expired_batch_more_than_once_is_safe(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);

        $path = 'imports/'.$organization->id.'/expired.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('local')->put($path, 'synthetic private data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();
        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('expired', $dataImport->fresh()->status);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_expiring_a_batch_also_removes_encrypted_staged_rows(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/expired.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'validated',
            'expires_at' => now()->subMinute(),
            'total_rows' => 1,
            'valid_rows' => 1,
        ]);
        app(DataImportProcessor::class)->stageRows($dataImport, [[
            'line' => 2,
            'attributes' => ['name' => 'Payload temporário'],
        ]]);
        Storage::disk('local')->put($path, 'private test data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertDatabaseMissing('data_import_rows', ['data_import_id' => $dataImport->id]);
        $this->assertSame('expired', $dataImport->fresh()->status);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_expired_import_is_not_purged_while_a_worker_has_an_active_processing_lease(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/processing.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes(5),
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('local')->put($path, 'private test data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('processing', $dataImport->fresh()->status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_stale_processing_import_becomes_resumable_without_losing_pending_rows(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/stale.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes(20),
            'processing_attempts' => 1,
            'total_rows' => 1,
            'valid_rows' => 1,
            'expires_at' => now()->addDay(),
        ]);
        app(DataImportProcessor::class)->stageRows($dataImport, [[
            'line' => 2,
            'attributes' => ['name' => 'Pendente e retomável'],
        ]]);
        Storage::disk('local')->put($path, 'private test data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('failed', $dataImport->fresh()->status);
        $this->assertNotNull($dataImport->fresh()->failed_at);
        $this->assertDatabaseHas('data_import_rows', [
            'organization_id' => $organization->id,
            'data_import_id' => $dataImport->id,
            'status' => 'pending',
        ]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_recent_processing_heartbeat_keeps_a_long_running_import_active(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/active-long-running.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes(25),
            'processing_heartbeat_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($path, 'private test data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('processing', $dataImport->fresh()->status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_purger_removes_source_files_left_on_batches_already_marked_expired(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/expired-by-worker.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'expired',
            'expires_at' => now()->subMinute(),
            'total_rows' => 1,
            'valid_rows' => 1,
        ]);
        app(DataImportProcessor::class)->stageRows($dataImport, [[
            'line' => 2,
            'attributes' => ['name' => 'Sensitive staged data'],
        ]]);
        Storage::disk('local')->put($path, 'private test data');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('', $dataImport->fresh()->file_path);
        $this->assertDatabaseMissing('data_import_rows', ['data_import_id' => $dataImport->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_expired_worker_batch_source_file_is_removed_by_retention_cleanup(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/expired-while-queued.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'queued',
            'processing_attempts' => 1,
            'expires_at' => now()->subMinute(),
        ]);
        app(DataImportProcessor::class)->stageRows($dataImport, [[
            'line' => 2,
            'attributes' => ['name' => 'Expirado antes do processamento'],
        ]]);
        Storage::disk('local')->put($path, 'private test data');

        app()->call([new ProcessDataImportChunk($organization->id, $dataImport->id, 1), 'handle']);
        $this->assertSame('expired', $dataImport->fresh()->status);
        Storage::disk('local')->assertExists($path);

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('', $dataImport->fresh()->file_path);
        $this->assertDatabaseMissing('data_import_rows', ['data_import_id' => $dataImport->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_stale_queued_import_is_reenqueued_with_the_same_attempt(): void
    {
        Bus::fake();

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'status' => 'queued',
            'processing_attempts' => 3,
            'queue_dispatches' => 1,
            'updated_at' => now()->subMinutes(11),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        Bus::assertDispatched(ProcessDataImportChunk::class, fn (ProcessDataImportChunk $job): bool => $job->organizationId === $organization->id
            && $job->dataImportId === $dataImport->id
            && $job->attemptNumber === 3);
        $this->assertSame(2, $dataImport->fresh()->queue_dispatches);
        $this->assertTrue($dataImport->fresh()->updated_at->isAfter(now()->subMinutes(1)));
    }

    public function test_stale_validation_queue_is_reenqueued_with_the_same_attempt(): void
    {
        Bus::fake();

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'status' => 'validation_queued',
            'processing_attempts' => 2,
            'queue_dispatches' => 1,
            'updated_at' => now()->subMinutes(11),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        Bus::assertDispatched(ValidateDataImport::class, fn (ValidateDataImport $job): bool => $job->organizationId === $organization->id
            && $job->dataImportId === $dataImport->id
            && $job->attemptNumber === 2);
        $this->assertSame('validation_queued', $dataImport->fresh()->status);
        $this->assertSame(2, $dataImport->fresh()->queue_dispatches);
    }

    public function test_stale_validation_worker_becomes_retryable_without_removing_its_source_file(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/stale-validation.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'validating',
            'processing_attempts' => 1,
            'processing_started_at' => now()->subMinutes(20),
            'processing_heartbeat_at' => now()->subMinutes(20),
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($path, 'private validation file');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertDatabaseHas('data_imports', [
            'id' => $dataImport->id,
            'status' => 'validation_failed',
            'processing_attempts' => 1,
        ]);
        $this->assertNotNull($dataImport->fresh()->failed_at);
        Storage::disk('local')->assertExists($path);
    }

    public function test_expired_validation_file_is_not_purged_while_a_worker_lease_is_active(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/active-validation.csv';
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'file_path' => $path,
            'status' => 'validating',
            'processing_started_at' => now()->subMinutes(5),
            'processing_heartbeat_at' => now()->subMinute(),
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('local')->put($path, 'private active validation file');

        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        $this->assertSame('validating', $dataImport->fresh()->status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_stale_queue_redeliveries_are_bounded_and_leave_the_batch_resumable(): void
    {
        Bus::fake();

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $dataImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'status' => 'queued',
            'processing_attempts' => 4,
            'queue_dispatches' => 1,
            'updated_at' => now()->subMinutes(11),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('data-imports:purge-expired')->assertSuccessful();
        $this->travel(11)->minutes();
        $this->artisan('data-imports:purge-expired')->assertSuccessful();
        $this->travel(11)->minutes();
        $this->artisan('data-imports:purge-expired')->assertSuccessful();

        Bus::assertDispatchedTimes(ProcessDataImportChunk::class, 2);
        $this->assertDatabaseHas('data_imports', [
            'id' => $dataImport->id,
            'status' => 'failed',
            'processing_attempts' => 4,
            'queue_dispatches' => 3,
        ]);
        $this->assertSame(
            'O lote não iniciou após tentativas limitadas de envio para a fila; você pode retomá-lo para tentar novamente.',
            $dataImport->fresh()->last_error,
        );
    }

    public function test_retention_and_stale_worker_cleanup_runs_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'data-imports:purge-expired'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
