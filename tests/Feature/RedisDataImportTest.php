<?php

use App\Domain\Imports\DataImportProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessDataImportChunk;
use App\Models\Company;
use App\Models\Contact;
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

it('updates a staged visible company duplicate through a Redis worker', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'owner_user_id' => $owner->id,
        'name' => 'Empresa atualizada no worker Redis',
        'city' => 'Recife',
        'state' => 'PE',
        'tax_id' => '12345678000195',
        'phone' => '1111',
        'notes' => 'Preservar pelo worker',
    ]);
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
        'attributes' => [
            'name' => $company->name,
            'city' => $company->city,
            'state' => $company->state,
            'tax_id' => $company->tax_id,
            'phone' => '2222',
        ],
        'resolution' => ['action' => 'update', 'target_id' => $company->id],
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
        ->and($batch->created_rows)->toBe(0)
        ->and($batch->updated_rows)->toBe(1);

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'phone' => '2222',
        'owner_user_id' => $owner->id,
        'notes' => 'Preservar pelo worker',
    ]);
});

it('updates a staged visible contact duplicate and links its grouped rows through a Redis worker', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    app(TenantContext::class)->set($organization);
    $previousCompany = Company::factory()->create(['organization_id' => $organization->id]);
    $firstCompany = Company::factory()->create(['organization_id' => $organization->id]);
    $secondCompany = Company::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Ana Silva',
        'email' => 'ana.redis@example.test',
        'job_title' => 'Cargo antigo',
        'status' => 'inactive',
    ]);
    $previousCompany->contacts()->attach($contact->id, [
        'organization_id' => $organization->id,
        'roles' => ['owner'],
        'is_primary' => true,
        'notes' => 'Vínculo anterior',
    ]);
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'record_type' => 'contacts',
        'status' => 'queued',
        'total_rows' => 2,
        'valid_rows' => 2,
        'processing_attempts' => 1,
    ]);
    app(DataImportProcessor::class)->stageRows($batch, [
        [
            'line' => 2,
            'identity' => 'group:2',
            'attributes' => ['name' => 'Ana Silva Atualizada', 'email' => 'ana.redis@example.test', 'job_title' => 'Diretora', 'status' => 'active'],
            'company_id' => $firstCompany->id,
            'roles' => ['buyer'],
            'is_primary' => false,
            'relationship_notes' => 'Escritório um',
            'resolution' => ['action' => 'update', 'target_id' => $contact->id],
        ],
        [
            'line' => 3,
            'identity' => 'group:2',
            'attributes' => ['name' => 'Ana Silva Atualizada', 'email' => 'ana.redis@example.test', 'job_title' => 'Diretora', 'status' => 'active'],
            'company_id' => $secondCompany->id,
            'roles' => ['specifier'],
            'is_primary' => false,
            'relationship_notes' => 'Escritório dois',
            'resolution' => ['action' => 'update', 'target_id' => $contact->id],
        ],
    ]);
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
        ->and($batch->processed_rows)->toBe(2)
        ->and($batch->created_rows)->toBe(0)
        ->and($batch->updated_rows)->toBe(1);

    $this->assertDatabaseHas('contacts', [
        'id' => $contact->id,
        'name' => 'Ana Silva Atualizada',
        'job_title' => 'Diretora',
        'status' => 'inactive',
    ]);
    $this->assertDatabaseHas('company_contacts', [
        'organization_id' => $organization->id,
        'company_id' => $previousCompany->id,
        'contact_id' => $contact->id,
        'is_primary' => true,
        'notes' => 'Vínculo anterior',
    ]);
    $this->assertDatabaseHas('company_contacts', [
        'organization_id' => $organization->id,
        'company_id' => $firstCompany->id,
        'contact_id' => $contact->id,
        'notes' => 'Escritório um',
    ]);
    $this->assertDatabaseHas('company_contacts', [
        'organization_id' => $organization->id,
        'company_id' => $secondCompany->id,
        'contact_id' => $contact->id,
        'notes' => 'Escritório dois',
    ]);
});
