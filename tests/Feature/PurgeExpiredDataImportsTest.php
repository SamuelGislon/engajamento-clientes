<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
