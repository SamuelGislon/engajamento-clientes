<?php

namespace Tests\Feature;

use App\Domain\Imports\CompanyImportService;
use App\Domain\Imports\DataImportProcessor;
use App\Domain\Imports\SpreadsheetPreviewReader;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessDataImportChunk;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DataImport;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_import_deduplicates_a_person_and_links_them_to_multiple_companies(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $firstCompany = Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Escritório Norte', 'tax_id' => '12345678000195']);
        $secondCompany = Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Escritório Sul', 'tax_id' => '98765432000198']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'contacts',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', implode("\n", [
                'Name,Email,Phone,Job title,Professional interests,Company tax id,Company name,Company roles,Is primary,Relationship notes',
                'Ana Silva,ana@example.test,+5511999990000,Diretora,Novos projetos,12345678000195,Escritório Norte,"owner;decision_maker",true,Decide orçamento',
                'Ana Silva,,+5511999990000,Diretora,Novos projetos,98765432000198,Escritório Sul,buyer,false,Responsável por compras',
            ])),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $mapping = [
            'name' => '0', 'email' => '1', 'phone' => '2', 'job_title' => '3',
            'professional_interests' => '4', 'company_tax_id' => '5', 'company_name' => '6',
            'company_roles' => '7', 'is_primary' => '8', 'relationship_notes' => '9',
        ];

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping])
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'validated',
            'total_rows' => 2,
            'valid_rows' => 2,
            'invalid_rows' => 0,
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $contact = Contact::query()->where('organization_id', $organization->id)->where('email', 'ana@example.test')->firstOrFail();
        $this->assertSame('Diretora', $contact->job_title);
        $this->assertSame(2, $contact->companies()->count());
        $this->assertDatabaseHas('company_contacts', [
            'organization_id' => $organization->id,
            'company_id' => $firstCompany->id,
            'contact_id' => $contact->id,
            'is_primary' => true,
            'notes' => 'Decide orçamento',
        ]);
        $this->assertDatabaseHas('company_contacts', [
            'organization_id' => $organization->id,
            'company_id' => $secondCompany->id,
            'contact_id' => $contact->id,
            'is_primary' => false,
            'notes' => 'Responsável por compras',
        ]);
        $this->assertSame(
            ['owner', 'decision_maker'],
            json_decode((string) DB::table('company_contacts')->where('company_id', $firstCompany->id)->where('contact_id', $contact->id)->value('roles'), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            ['buyer'],
            json_decode((string) DB::table('company_contacts')->where('company_id', $secondCompany->id)->where('contact_id', $contact->id)->value('roles'), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'status' => 'completed', 'created_rows' => 1]);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_contact_import_cannot_link_to_a_company_from_another_organization(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $otherOrganization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($otherOrganization);
        Company::factory()->create(['organization_id' => $otherOrganization->id, 'name' => 'Empresa de outro tenant', 'tax_id' => '12345678000195']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'contacts',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', "Name,Email,Company tax id\nPessoa Externa,pessoa@example.test,12345678000195\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'email' => '1', 'company_tax_id' => '2'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'validated',
            'valid_rows' => 0,
            'invalid_rows' => 1,
        ]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('company_contacts', 0);
    }

    public function test_a_salesperson_cannot_import_a_contact_for_a_company_outside_their_portfolio(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $salesperson = User::factory()->create();
        $otherSalesperson = User::factory()->create();
        $organization->users()->attach($salesperson, ['role' => 'salesperson']);
        $organization->users()->attach($otherSalesperson, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa fora da carteira',
            'owner_user_id' => $otherSalesperson->id,
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($salesperson)->withSession($session)->post('/imports', [
            'record_type' => 'contacts',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', "Name,Email,Company name\nPessoa fora da carteira,pessoa@example.test,Empresa fora da carteira\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'email' => '1', 'company_name' => '2'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'valid_rows' => 0,
            'invalid_rows' => 1,
        ]);
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('company_contacts', 0);
    }

    public function test_contact_import_skips_an_existing_contact_without_merging_or_updating_it(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Empresa Existente']);
        $contact = Contact::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Nome Original',
            'email' => 'duplicado@example.test',
            'job_title' => 'Cargo original',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'contacts',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', "Name,Email,Company name\nNome Alterado,duplicado@example.test,Empresa Existente\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'email' => '1', 'company_name' => '2'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 0, 'duplicate_rows' => 1]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseMissing('company_contacts', ['company_id' => $company->id, 'contact_id' => $contact->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'name' => 'Nome Original', 'job_title' => 'Cargo original']);
    }

    public function test_a_salesperson_is_not_told_that_a_contact_exists_only_in_another_portfolio(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $salesperson = User::factory()->create();
        $otherSalesperson = User::factory()->create();
        $organization->users()->attach($salesperson, ['role' => 'salesperson']);
        $organization->users()->attach($otherSalesperson, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $visibleCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa da carteira atual',
            'owner_user_id' => $salesperson->id,
        ]);
        $hiddenCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa de outra carteira',
            'owner_user_id' => $otherSalesperson->id,
        ]);
        $hiddenContact = Contact::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Contato fora do escopo',
            'email' => 'fora-do-escopo@example.test',
        ]);
        $hiddenCompany->contacts()->attach($hiddenContact->id, [
            'organization_id' => $organization->id,
            'roles' => ['buyer'],
            'is_primary' => false,
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($salesperson)->withSession($session)->post('/imports', [
            'record_type' => 'contacts',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', "Name,Email,Company name\nPessoa nova,fora-do-escopo@example.test,Empresa da carteira atual\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'email' => '1', 'company_name' => '2'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $batch = DataImport::query()->findOrFail($importId);
        $this->assertSame(0, $batch->duplicate_rows);
        $this->assertSame(1, $batch->invalid_rows);
        $this->assertContains([
            'line' => 2,
            'type' => 'invalid',
            'message' => 'A linha não pode ser importada no escopo de acesso atual.',
        ], $batch->validation_errors);
        $this->assertStringNotContainsString('fora-do-escopo@example.test', json_encode($batch->validation_errors));
        $this->assertStringNotContainsString('Contato fora do escopo', json_encode($batch->validation_errors));
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'completed',
            'duplicate_rows' => 0,
            'invalid_rows' => 1,
        ]);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_an_owner_can_validate_and_confirm_a_company_import_with_partial_row_errors(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)
            ->withSession($session)
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent(
                    'companies.csv',
                    "Name,City,Email\nEscritório Sul,Curitiba,comercial@example.test\n,Recife,sem-nome@example.test\n",
                ),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession($session)
            ->post('/imports/'.$importId.'/mapping', [
                'mapping' => ['name' => '0', 'city' => '1', 'email' => '2'],
            ])
            ->assertRedirect('/imports/'.$importId);

        $this->actingAs($owner)
            ->withSession($session)
            ->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'validated',
            'total_rows' => 2,
            'valid_rows' => 1,
            'invalid_rows' => 1,
        ]);

        $this->actingAs($owner)
            ->withSession($session)
            ->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('companies', [
            'organization_id' => $organization->id,
            'name' => 'Escritório Sul',
            'city' => 'Curitiba',
            'email' => 'comercial@example.test',
            'relationship_status' => 'prospect',
            'owner_user_id' => null,
        ]);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'completed',
            'created_rows' => 1,
            'skipped_rows' => 1,
            'invalid_rows' => 1,
        ]);
        $this->assertSame(0, Company::query()->where('name', '')->count());
        $this->assertDatabaseCount('journey_enrollments', 0);
    }

    public function test_company_import_skips_in_file_duplicates_and_repeated_confirmation_is_idempotent(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)
            ->withSession($session)
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent(
                    'companies.csv',
                    "Name,City,State\nEscritório Centro,Recife,PE\nEscritório Centro,Recife,PE\n",
                ),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1', 'state' => '2'],
        ])->assertRedirect('/imports/'.$importId);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'valid_rows' => 1,
            'duplicate_rows' => 1,
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'completed',
            'created_rows' => 1,
            'duplicate_rows' => 1,
            'skipped_rows' => 1,
        ]);
    }

    public function test_company_import_can_explicitly_update_a_visible_duplicate_without_overwriting_unmapped_fields(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório Centro',
            'tax_id' => '12345678000195',
            'city' => 'Recife',
            'state' => 'PE',
            'phone' => '1111',
            'notes' => 'Observação que deve ser preservada',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent(
                'companies.csv',
                "Name,CNPJ,City,State,Phone\nEscritório Centro,12345678000195,Recife,PE,2222\n",
            ),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'tax_id' => '1', 'city' => '2', 'state' => '3', 'phone' => '4'],
        ])->assertRedirect('/imports/'.$importId);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 0, 'duplicate_rows' => 1]);
        $duplicateError = DataImport::query()->findOrFail($importId)->validation_errors[0];
        $this->assertCount(1, $duplicateError['candidates'] ?? [], json_encode($duplicateError));

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['2' => ['action' => 'update']],
        ])->assertRedirect('/imports/'.$importId);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 1, 'duplicate_rows' => 0]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'phone' => '2222',
            'owner_user_id' => $owner->id,
            'notes' => 'Observação que deve ser preservada',
        ]);
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'created_rows' => 0, 'updated_rows' => 1]);
    }

    public function test_company_import_preserves_duplicate_decisions_across_batches_of_one_hundred_rows(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório Centro',
            'tax_id' => null,
            'city' => 'Recife',
            'state' => 'PE',
            'phone' => '1111',
        ]);
        $csv = "Name,City,State,Phone\n".str_repeat("Escritório Centro,Recife,PE,2222\n", 101);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', $csv),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1', 'state' => '2', 'phone' => '3'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $batch = DataImport::query()->findOrFail($importId);
        $this->assertCount(100, $batch->validation_errors);
        $resolutions = [];

        foreach ($batch->validation_errors as $index => $error) {
            $resolutions[(string) $error['line']] = ['action' => $index === 0 ? 'update' : 'ignore'];
        }

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => $resolutions,
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $nextBatch = DataImport::query()->findOrFail($importId);
        $this->assertSame([102], array_column($nextBatch->validation_errors, 'line'));
        $this->assertSame('update', $nextBatch->duplicate_resolutions['2']['action']);
        $this->assertSame('ignore', $nextBatch->duplicate_resolutions['101']['action']);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['102' => ['action' => 'ignore']],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertSame([], DataImport::query()->findOrFail($importId)->validation_errors);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'phone' => '2222']);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'created_rows' => 0,
            'updated_rows' => 1,
            'duplicate_rows' => 100,
            'skipped_rows' => 100,
        ]);
    }

    public function test_company_import_can_explicitly_create_a_duplicate_without_a_unique_tax_id(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório Centro',
            'city' => 'Recife',
            'state' => 'PE',
            'tax_id' => null,
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent(
                'companies.csv',
                "Name,City,State\nEscritório Centro,Recife,PE\n",
            ),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1', 'state' => '2'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $duplicateError = DataImport::query()->findOrFail($importId)->validation_errors[0];
        $this->assertTrue($duplicateError['can_create']);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['2' => ['action' => 'create']],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'created_rows' => 1, 'updated_rows' => 0]);
    }

    public function test_company_import_can_manually_resolve_an_ambiguous_duplicate_to_one_visible_company(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $firstCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório Centro',
            'city' => 'Recife',
            'state' => 'PE',
            'tax_id' => '12.345.678/0001-95',
            'phone' => '1111',
        ]);
        $secondCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório Centro',
            'city' => 'Recife',
            'state' => 'PE',
            'tax_id' => '98.765.432/0001-10',
            'phone' => '3333',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent(
                'companies.csv',
                "Name,City,State,Phone\nEscritório Centro,Recife,PE,2222\n",
            ),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1', 'state' => '2', 'phone' => '3'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $duplicateError = DataImport::query()->findOrFail($importId)->validation_errors[0];
        $this->assertCount(2, $duplicateError['candidates']);
        $this->assertSame('12.***.***/****-95', $duplicateError['candidates'][0]['tax_id_masked']);
        $this->assertSame('98.***.***/****-10', $duplicateError['candidates'][1]['tax_id_masked']);
        $this->assertArrayNotHasKey('tax_id', $duplicateError['candidates'][0]);
        $this->actingAs($owner)->withSession($session)->get('/imports/'.$importId)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('imports/show')
                ->where('import.validation_errors.0.candidates.0.tax_id_masked', '12.***.***/****-95')
                ->where('import.validation_errors.0.candidates.1.tax_id_masked', '98.***.***/****-10'));
        $foreignOrganization = Organization::factory()->create();
        $foreignCompanyId = DB::table('companies')->insertGetId([
            'organization_id' => $foreignOrganization->id,
            'tax_id' => null,
            'name' => 'Empresa de outro tenant',
            'relationship_status' => 'prospect',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['2' => ['action' => 'resolve', 'target_id' => $foreignCompanyId]],
        ])->assertSessionHasErrors('resolutions');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['2' => ['action' => 'resolve', 'target_id' => $secondCompany->id]],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('companies', ['id' => $firstCompany->id, 'phone' => '1111']);
        $this->assertDatabaseHas('companies', ['id' => $secondCompany->id, 'phone' => '2222']);
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'updated_rows' => 1]);
    }

    public function test_company_validation_rejects_analysis_when_duplicate_decisions_change_concurrently(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório Centro',
            'tax_id' => null,
            'city' => 'Recife',
            'state' => 'PE',
        ]);

        $csv = "Name,City,State\nEscritório Centro,Recife,PE\n";
        for ($index = 1; $index <= 250; $index++) {
            $csv .= "Empresa {$index},Cidade {$index},PE\n";
        }

        $session = ['active_organization_id' => $organization->id];
        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', $csv),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1', 'state' => '2'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['2' => ['action' => 'create']],
        ])->assertRedirect('/imports/'.$importId);

        $realCompanyImports = app(CompanyImportService::class);
        $concurrentCompanyImports = \Mockery::mock($realCompanyImports)->makePartial();
        $concurrentCompanyImports->shouldReceive('analyze')->once()->andReturnUsing(
            function (...$arguments) use ($realCompanyImports, $importId): array {
                $analysis = $realCompanyImports->analyze(...$arguments);
                DB::table('data_imports')->where('id', $importId)->update([
                    'duplicate_resolutions' => json_encode(['2' => ['action' => 'ignore']]),
                ]);

                return $analysis;
            },
        );
        $this->app->instance(CompanyImportService::class, $concurrentCompanyImports);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'status' => 'mapped']);
        $this->assertDatabaseCount('data_import_rows', 0);
    }

    public function test_import_duplicate_candidates_are_hidden_after_a_salesperson_loses_portfolio_access(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $salesperson = User::factory()->create();
        $newOwner = User::factory()->create();
        $organization->users()->attach($salesperson, ['role' => 'salesperson']);
        $organization->users()->attach($newOwner, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->ownedBy($salesperson)->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa da carteira',
            'city' => 'Recife',
            'state' => 'PE',
            'tax_id' => null,
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($salesperson)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent(
                'companies.csv',
                "Name,City,State\nEmpresa da carteira,Recife,PE\n",
            ),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1', 'state' => '2'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $company->update(['owner_user_id' => $newOwner->id]);

        $this->actingAs($salesperson)->withSession($session)
            ->get('/imports/'.$importId)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('imports/show')
                ->where('import.validation_errors.0.candidates', [])
                ->missing('import.duplicate_resolutions.2.target_id'));
    }

    public function test_new_ambiguity_prevents_an_earlier_single_match_update_until_manually_resolved(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $firstCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório em Revisão',
            'city' => 'Recife',
            'state' => 'PE',
            'tax_id' => null,
            'phone' => '1111',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent(
                'companies.csv',
                "Name,City,State,Phone\nEscritório em Revisão,Recife,PE,2222\n",
            ),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1', 'state' => '2', 'phone' => '3'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['2' => ['action' => 'update']],
        ])->assertRedirect('/imports/'.$importId);

        $secondCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Escritório em Revisão',
            'city' => 'Recife',
            'state' => 'PE',
            'tax_id' => null,
            'phone' => '3333',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'valid_rows' => 0,
            'duplicate_rows' => 1,
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/resolutions', [
            'resolutions' => ['2' => ['action' => 'resolve', 'target_id' => $firstCompany->id]],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('companies', ['id' => $firstCompany->id, 'phone' => '2222']);
        $this->assertDatabaseHas('companies', ['id' => $secondCompany->id, 'phone' => '3333']);
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'updated_rows' => 1]);
    }

    public function test_a_row_with_extra_columns_is_reported_without_blocking_valid_company_rows(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', implode("\n", [
                'Name,City',
                'Linha com coluna excedente,Recife,valor inesperado',
                'Empresa válida,Curitiba',
            ])),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'city' => '1'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'validated',
            'total_rows' => 2,
            'valid_rows' => 1,
            'invalid_rows' => 1,
        ]);
        $this->assertContains([
            'line' => 2,
            'type' => 'invalid',
            'message' => 'A linha contém mais colunas do que o cabeçalho.',
        ], DataImport::query()->findOrFail($importId)->validation_errors);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('companies', ['organization_id' => $organization->id, 'name' => 'Empresa válida', 'city' => 'Curitiba']);
        $this->assertDatabaseMissing('companies', ['organization_id' => $organization->id, 'name' => 'Linha com coluna excedente']);
    }

    public function test_a_member_cannot_confirm_an_import_from_another_active_organization(): void
    {
        $activeOrganization = Organization::factory()->create();
        $importOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $activeOrganization->users()->attach($owner, ['role' => 'owner']);
        $importOrganization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($importOrganization);
        $batch = DataImport::factory()->create([
            'organization_id' => $importOrganization->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $activeOrganization->id])
            ->post('/imports/'.$batch->id.'/confirm')
            ->assertNotFound();
    }

    public function test_a_manager_must_choose_a_team_they_manage_for_company_imports(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        app(TenantContext::class)->set($organization);
        $team = Team::query()->create([
            'name' => 'Equipe Leste',
            'status' => 'active',
            'created_by' => $manager->id,
        ]);
        DB::table('team_memberships')->insert([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'is_active' => true,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->from('/imports')
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Name\nEscritório Leste\n"),
            ])
            ->assertRedirect('/imports')
            ->assertSessionHasErrors('team_id');

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'team_id' => $team->id,
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Name\nEscritório Leste\n"),
            ])
            ->assertRedirect('/imports');

        $this->assertDatabaseHas('data_imports', [
            'organization_id' => $organization->id,
            'created_by' => $manager->id,
            'team_id' => $team->id,
        ]);
    }

    public function test_confirmation_rechecks_a_managers_team_membership_after_validation(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        app(TenantContext::class)->set($organization);
        $team = Team::query()->create([
            'name' => 'Equipe Revogada',
            'status' => 'active',
            'created_by' => $manager->id,
        ]);
        DB::table('team_memberships')->insert([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'is_active' => true,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($manager)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'team_id' => $team->id,
            'file' => UploadedFile::fake()->createWithContent('companies.csv', "Name\nEscritório Revogado\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($manager)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($manager)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        DB::table('team_memberships')
            ->where('organization_id', $organization->id)
            ->where('team_id', $team->id)
            ->where('user_id', $manager->id)
            ->update(['is_active' => false]);

        $this->actingAs($manager)
            ->withSession($session)
            ->from('/imports/'.$importId)
            ->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId)
            ->assertSessionHasErrors('team_id');

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'validated',
        ]);
    }

    public function test_import_analysis_fails_closed_when_the_actor_has_no_active_organization_role(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $organization->users()->attach($actor, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $actor->id,
            'mapping' => ['name' => 0],
        ]);

        DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->update(['is_active' => false]);

        try {
            app(CompanyImportService::class)->analyze($batch, $actor, [['Empresa sem vínculo']], 1);
            $this->fail('An actor without an active organization role must not analyze an import.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_an_import_cannot_assign_a_team_from_another_organization(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $otherOrganization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($otherOrganization);
        $foreignTeam = Team::query()->create([
            'name' => 'Equipe Externa',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->from('/imports')
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'team_id' => $foreignTeam->id,
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Name\nEscritório Externo\n"),
            ])
            ->assertRedirect('/imports')
            ->assertSessionHasErrors('team_id');

        $this->assertDatabaseCount('data_imports', 0);
    }

    public function test_company_import_rejects_invalid_cnpj_values(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent(
                'companies.csv',
                "Name,CNPJ\nEscritório Inválido,00000000000000\n",
            ),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'tax_id' => '1'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'valid_rows' => 0,
            'invalid_rows' => 1,
        ]);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_an_owner_can_create_a_private_import_batch_in_the_active_organization(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $response = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'organization_id' => $otherOrganization->id,
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "name,city\nEscritório Sul,Curitiba\n"),
            ]);

        $response->assertRedirect();

        $batch = DB::table('data_imports')->first();

        $this->assertNotNull($batch);
        $this->assertSame($organization->id, $batch->organization_id);
        $this->assertSame($owner->id, $batch->created_by);
        $this->assertSame('companies', $batch->record_type);
        $this->assertSame('uploaded', $batch->status);
        $this->assertSame('none', $batch->journey_mode);
        $this->assertStringStartsWith('imports/'.$organization->id.'/', $batch->file_path);
        $this->assertStringNotContainsString('companies.csv', $batch->file_path);
        Storage::disk('local')->assertExists($batch->file_path);
        Storage::disk('public')->assertMissing($batch->file_path);
    }

    public function test_an_import_cannot_reference_a_journey_template_from_another_organization(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        app(TenantContext::class)->set($otherOrganization);
        $foreignTemplate = JourneyTemplate::factory()->create([
            'name' => 'Foreign prospecting journey',
            'type' => 'prospecting',
            'trigger_type' => 'manual',
            'is_active' => true,
            'is_default' => false,
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->from('/imports')
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'template',
                'journey_template_id' => $foreignTemplate->id,
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "name\nEscritório Sul\n"),
            ]);

        $response->assertRedirect('/imports');
        $response->assertSessionHasErrors('journey_template_id');
        $this->assertDatabaseCount('data_imports', 0);
    }

    public function test_company_import_can_start_the_selected_default_journey_for_eligible_prospects(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create(['auto_enroll_default_journey' => false]);
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'default',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', "name,relationship_status\nProspecto Selecionado,prospect\nCliente Sem Jornada,customer\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $mapping = ['name' => '0', 'relationship_status' => '1'];

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping])
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $prospect = Company::query()->where('name', 'Prospecto Selecionado')->firstOrFail();
        $customer = Company::query()->where('name', 'Cliente Sem Jornada')->firstOrFail();
        $this->assertDatabaseHas('journey_enrollments', [
            'organization_id' => $organization->id,
            'company_id' => $prospect->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tasks', [
            'organization_id' => $organization->id,
            'company_id' => $prospect->id,
            'origin' => 'automatic',
        ]);
        $this->assertDatabaseMissing('journey_enrollments', ['company_id' => $customer->id]);
        $this->assertDatabaseMissing('tasks', ['company_id' => $customer->id, 'origin' => 'automatic']);
    }

    public function test_company_import_can_start_a_specific_active_prospecting_journey(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $template = JourneyTemplate::query()->create([
            'name' => 'Jornada importada escolhida',
            'type' => 'prospecting',
            'trigger_type' => 'manual',
            'is_active' => true,
            'is_default' => false,
        ]);
        $template->steps()->create([
            'title' => 'Contato inicial',
            'position' => 1,
            'delay_days' => 2,
            'task_title' => 'Falar com {company} pela jornada escolhida.',
            'task_type' => 'first_contact',
            'task_priority' => 'normal',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'template',
            'journey_template_id' => $template->id,
            'file' => UploadedFile::fake()->createWithContent('companies.csv', "name\nProspecto Jornada Escolhida\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->from('/imports/'.$importId)->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0'],
            'journey_mode' => 'template',
        ])->assertRedirect('/imports/'.$importId)
            ->assertSessionHasErrors('journey_template_id');

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0'],
            'journey_mode' => 'template',
            'journey_template_id' => (string) $template->id,
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $company = Company::query()->where('name', 'Prospecto Jornada Escolhida')->firstOrFail();
        $this->assertDatabaseHas('journey_enrollments', [
            'organization_id' => $organization->id,
            'journey_template_id' => $template->id,
            'company_id' => $company->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tasks', [
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'title' => 'Falar com Prospecto Jornada Escolhida pela jornada escolhida.',
            'origin' => 'automatic',
        ]);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'journey_mode' => 'template',
            'journey_template_id' => $template->id,
        ]);
    }

    public function test_company_importer_can_change_journey_after_the_selected_template_is_deactivated(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $template = JourneyTemplate::query()->create([
            'name' => 'Jornada que será desativada',
            'type' => 'prospecting',
            'trigger_type' => 'manual',
            'is_active' => true,
            'is_default' => false,
        ]);
        $template->steps()->create([
            'title' => 'Contato inicial',
            'position' => 1,
            'delay_days' => 2,
            'task_title' => 'Falar com {company}',
            'task_type' => 'first_contact',
            'task_priority' => 'normal',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'template',
            'journey_template_id' => $template->id,
            'file' => UploadedFile::fake()->createWithContent('companies.csv', "name\nProspecto Recuperável\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $template->update(['is_active' => false]);

        $this->from('/imports/'.$importId)->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId)
            ->assertSessionHasErrors('journey_template_id');
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'status' => 'validated']);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0'],
            'journey_mode' => 'none',
            'journey_template_id' => '',
        ])->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'mapped',
            'journey_mode' => 'none',
            'journey_template_id' => null,
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $company = Company::query()->where('name', 'Prospecto Recuperável')->firstOrFail();
        $this->assertDatabaseMissing('journey_enrollments', ['company_id' => $company->id]);
    }

    public function test_a_salesperson_is_not_told_that_a_company_duplicate_is_outside_their_portfolio(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $salesperson = User::factory()->create();
        $otherSalesperson = User::factory()->create();
        $organization->users()->attach($salesperson, ['role' => 'salesperson']);
        $organization->users()->attach($otherSalesperson, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->ownedBy($otherSalesperson)->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa de outra carteira',
            'tax_id' => '12345678000195',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($salesperson)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', "name,tax_id\nEmpresa Importada,12345678000195\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'tax_id' => '1'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $batch = DataImport::query()->findOrFail($importId);
        $this->assertSame(0, $batch->duplicate_rows);
        $this->assertSame(1, $batch->invalid_rows);
        $this->assertSame('A linha não pode ser importada no escopo de acesso atual.', $batch->validation_errors[0]['message']);
    }

    public function test_a_salesperson_sees_a_duplicate_that_is_in_their_portfolio(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $salesperson = User::factory()->create();
        $organization->users()->attach($salesperson, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->ownedBy($salesperson)->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa da própria carteira',
            'tax_id' => '12345678000195',
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($salesperson)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', "name,tax_id\nEmpresa Reimportada,12345678000195\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'tax_id' => '1'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $batch = DataImport::query()->findOrFail($importId);
        $this->assertSame(1, $batch->duplicate_rows);
        $this->assertSame(0, $batch->invalid_rows);
        $this->assertSame('duplicate', $batch->validation_errors[0]['type']);
    }

    public function test_a_hidden_company_collision_during_confirmation_is_counted_as_invalid(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $salesperson = User::factory()->create();
        $otherSalesperson = User::factory()->create();
        $organization->users()->attach($salesperson, ['role' => 'salesperson']);
        $organization->users()->attach($otherSalesperson, ['role' => 'salesperson']);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($salesperson)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', "name,tax_id\nEmpresa Concorrente,12345678000195\n"),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0', 'tax_id' => '1'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $realService = new CompanyImportService;
        $raceService = \Mockery::mock(CompanyImportService::class)->makePartial();
        $raceService->shouldReceive('analyze')->once()->andReturnUsing(
            function (
                DataImport $batch,
                User $actor,
                array $rows,
                int $totalRows,
                bool $lockDestination = false,
                array $malformedRows = [],
            ) use ($realService, $organization, $otherSalesperson): array {
                $analysis = $realService->analyze($batch, $actor, $rows, $totalRows, $lockDestination, $malformedRows);
                Company::factory()->ownedBy($otherSalesperson)->create([
                    'organization_id' => $organization->id,
                    'name' => 'Registro em outra carteira',
                    'tax_id' => '12345678000195',
                ]);

                return $analysis;
            },
        );
        app()->instance(CompanyImportService::class, $raceService);

        $this->actingAs($salesperson)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'completed',
            'valid_rows' => 0,
            'invalid_rows' => 1,
            'duplicate_rows' => 0,
            'created_rows' => 0,
            'skipped_rows' => 1,
        ]);
    }

    public function test_saving_a_mapping_does_not_reopen_an_import_completed_during_preview(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/companies.csv';
        Storage::disk('local')->put($path, "name\nNova empresa\n");
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'validated',
            'mapping' => ['name' => 0],
            'file_path' => $path,
        ]);
        $previewReader = \Mockery::mock(SpreadsheetPreviewReader::class);
        $previewReader->shouldReceive('preview')->once()->andReturnUsing(
            function () use ($batch): array {
                DB::table('data_imports')->where('id', $batch->id)->update(['status' => 'completed']);

                return [
                    'sheetName' => 'Sheet1',
                    'headers' => ['Nome'],
                    'rows' => [['Nova empresa']],
                    'totalRows' => 1,
                    'malformedRows' => [],
                ];
            },
        );
        app()->instance(SpreadsheetPreviewReader::class, $previewReader);

        $this->from('/imports/'.$batch->id)->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports/'.$batch->id.'/mapping', [
                'mapping' => ['name' => '0'],
                'journey_mode' => 'none',
            ])
            ->assertRedirect('/imports/'.$batch->id)
            ->assertSessionHasErrors('mapping');

        $this->assertDatabaseHas('data_imports', [
            'id' => $batch->id,
            'status' => 'completed',
            'mapping->name' => 0,
        ]);
    }

    public function test_large_company_import_is_queued_without_processing_inside_the_http_request(): void
    {
        Bus::fake();
        config(['queue.default' => 'redis']);
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $session = ['active_organization_id' => $organization->id];
        $csv = "Name\n".implode("\n", array_map(
            static fn (int $number): string => 'Empresa em lote '.$number,
            range(1, 251),
        ));

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', $csv),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'queued',
            'total_rows' => 251,
            'valid_rows' => 251,
            'queue_dispatches' => 1,
        ]);
        $this->assertDatabaseCount('companies', 0);
        Bus::assertDispatched(ProcessDataImportChunk::class);
    }

    public function test_large_import_requires_an_asynchronous_queue_connection(): void
    {
        Bus::fake();
        config(['queue.default' => 'sync']);
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $session = ['active_organization_id' => $organization->id];
        $csv = "Name\n".implode("\n", array_map(
            static fn (int $number): string => 'Empresa protegida contra fila síncrona '.$number,
            range(1, 251),
        ));

        $this->actingAs($owner)->withSession($session)->post('/imports', [
            'record_type' => 'companies',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('companies.csv', $csv),
        ])->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', [
            'mapping' => ['name' => '0'],
        ])->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'status' => 'validated']);
        $this->assertDatabaseCount('companies', 0);
        Bus::assertNothingDispatched();
    }

    public function test_import_job_processes_a_chunk_and_finishes_the_batch_atomically(): void
    {
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
            'attributes' => ['name' => 'Empresa importada pela fila', 'relationship_status' => 'customer'],
        ]]);

        app()->call([new ProcessDataImportChunk($organization->id, $batch->id, 1), 'handle']);

        $this->assertDatabaseHas('companies', [
            'organization_id' => $organization->id,
            'name' => 'Empresa importada pela fila',
        ]);
        $this->assertDatabaseHas('data_imports', [
            'id' => $batch->id,
            'status' => 'completed',
            'processed_rows' => 1,
            'created_rows' => 1,
        ]);
        $this->assertDatabaseHas('data_import_rows', [
            'organization_id' => $organization->id,
            'data_import_id' => $batch->id,
            'status' => 'processed',
            'encrypted_payload' => null,
        ]);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_queued_company_import_finishes_when_its_confirmed_journey_is_deactivated(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $template = JourneyTemplate::query()->create([
            'name' => 'Jornada confirmada antes da fila',
            'type' => 'prospecting',
            'trigger_type' => 'manual',
            'is_active' => true,
            'is_default' => false,
        ]);
        $template->steps()->create([
            'title' => 'Contato inicial',
            'position' => 1,
            'delay_days' => 2,
            'task_title' => 'Falar com {company}',
            'task_type' => 'first_contact',
            'task_priority' => 'normal',
        ]);
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'queued',
            'journey_mode' => 'template',
            'journey_template_id' => $template->id,
            'total_rows' => 1,
            'valid_rows' => 1,
            'processing_attempts' => 1,
        ]);
        app(DataImportProcessor::class)->stageRows($batch, [[
            'line' => 2,
            'attributes' => ['name' => 'Prospecto da jornada confirmada', 'relationship_status' => 'prospect'],
        ]]);
        $template->update(['is_active' => false]);

        app()->call([new ProcessDataImportChunk($organization->id, $batch->id, 1), 'handle']);

        $company = Company::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('name', 'Prospecto da jornada confirmada')
            ->firstOrFail();
        $this->assertDatabaseHas('data_imports', ['id' => $batch->id, 'status' => 'completed', 'created_rows' => 1]);
        $this->assertDatabaseHas('journey_enrollments', [
            'organization_id' => $organization->id,
            'journey_template_id' => $template->id,
            'company_id' => $company->id,
            'status' => 'active',
        ]);
    }

    public function test_a_failed_import_can_resume_only_its_remaining_rows(): void
    {
        Bus::fake();
        config(['queue.default' => 'redis']);

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $session = ['active_organization_id' => $organization->id];
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'failed',
            'total_rows' => 3,
            'valid_rows' => 3,
            'processed_rows' => 2,
            'processing_attempts' => 1,
            'created_rows' => 2,
            'last_error' => 'A previous attempt failed.',
        ]);
        app(DataImportProcessor::class)->stageRows($batch, [
            ['line' => 2, 'attributes' => ['name' => 'Já processada 1']],
            ['line' => 3, 'attributes' => ['name' => 'Já processada 2']],
            ['line' => 4, 'attributes' => ['name' => 'Pendente após falha']],
        ]);
        $batch->rows()->whereIn('line_number', [2, 3])->update([
            'status' => 'processed',
            'encrypted_payload' => null,
            'processed_at' => now(),
        ]);

        $this->actingAs($owner)->withSession($session)
            ->post('/imports/'.$batch->id.'/resume')
            ->assertRedirect('/imports/'.$batch->id);

        $this->assertDatabaseHas('data_imports', [
            'id' => $batch->id,
            'status' => 'queued',
            'processing_attempts' => 2,
            'queue_dispatches' => 1,
            'processed_rows' => 2,
            'created_rows' => 2,
            'last_error' => null,
        ]);
        Bus::assertDispatched(ProcessDataImportChunk::class, fn (ProcessDataImportChunk $job): bool => $job->organizationId === $organization->id
            && $job->dataImportId === $batch->id
            && $job->attemptNumber === 2
        );
    }

    public function test_failed_import_cannot_resume_inline_on_a_synchronous_queue(): void
    {
        Bus::fake();
        config(['queue.default' => 'sync']);
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'failed',
            'total_rows' => 1,
            'valid_rows' => 1,
            'processing_attempts' => 1,
        ]);
        app(DataImportProcessor::class)->stageRows($batch, [[
            'line' => 2,
            'attributes' => ['name' => 'Retomada assíncrona obrigatória'],
        ]]);

        $this->actingAs($owner)->withSession(['active_organization_id' => $organization->id])
            ->post('/imports/'.$batch->id.'/resume')
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('data_imports', ['id' => $batch->id, 'status' => 'failed', 'processing_attempts' => 1]);
        Bus::assertNothingDispatched();
    }

    public function test_a_partially_imported_failed_batch_cannot_reset_its_mapping_or_confirmed_rows(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $path = 'imports/'.$organization->id.'/partial.csv';
        Storage::disk('local')->put($path, "Name\nEmpresa já confirmada\n");
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'failed',
            'file_path' => $path,
            'mapping' => ['name' => 0],
            'total_rows' => 251,
            'valid_rows' => 251,
            'processed_rows' => 100,
            'processing_attempts' => 1,
            'created_rows' => 100,
        ]);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($owner)->withSession($session)
            ->post('/imports/'.$batch->id.'/mapping', ['mapping' => ['name' => '0']])
            ->assertSessionHasErrors('mapping');
        $this->actingAs($owner)->withSession($session)
            ->post('/imports/'.$batch->id.'/validate')
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('data_imports', [
            'id' => $batch->id,
            'status' => 'failed',
            'processed_rows' => 100,
            'created_rows' => 100,
            'processing_attempts' => 1,
        ]);
    }

    public function test_import_job_commits_one_chunk_and_queues_the_next_chunk(): void
    {
        Bus::fake();

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'queued',
            'total_rows' => 101,
            'valid_rows' => 101,
            'processing_attempts' => 1,
        ]);
        $stagedRows = [];

        for ($line = 2; $line <= 102; $line++) {
            $stagedRows[] = [
                'line' => $line,
                'attributes' => ['name' => 'Empresa em lote '.$line, 'relationship_status' => 'customer'],
            ];
        }

        app(DataImportProcessor::class)->stageRows($batch, $stagedRows);

        app()->call([new ProcessDataImportChunk($organization->id, $batch->id, 1), 'handle']);

        $this->assertDatabaseHas('data_imports', [
            'id' => $batch->id,
            'status' => 'processing',
            'processed_rows' => 100,
            'created_rows' => 100,
            'last_processed_line' => 101,
        ]);
        $batch->refresh();
        $this->assertNotNull($batch->processing_started_at);
        $this->assertNotNull($batch->processing_heartbeat_at);
        $this->assertTrue($batch->processing_heartbeat_at->greaterThanOrEqualTo($batch->processing_started_at));
        $this->assertDatabaseCount('companies', 100);
        $this->assertSame(1, DB::table('data_import_rows')->where('organization_id', $organization->id)->where('data_import_id', $batch->id)->where('status', 'pending')->count());
        Bus::assertDispatched(ProcessDataImportChunk::class, fn (ProcessDataImportChunk $job): bool => $job->organizationId === $organization->id
            && $job->dataImportId === $batch->id
            && $job->attemptNumber === 1
        );
    }

    public function test_an_import_job_cannot_process_another_organizations_batch_by_id(): void
    {
        $requestOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $requestOrganization->users()->attach($owner, ['role' => 'owner']);
        $otherOrganization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($otherOrganization);
        $batch = DataImport::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by' => $owner->id,
            'status' => 'queued',
            'total_rows' => 1,
            'valid_rows' => 1,
            'processing_attempts' => 1,
        ]);
        app(DataImportProcessor::class)->stageRows($batch, [[
            'line' => 2,
            'attributes' => ['name' => 'Não importar fora do tenant'],
        ]]);
        app(TenantContext::class)->set($requestOrganization);

        app()->call([new ProcessDataImportChunk($requestOrganization->id, $batch->id, 1), 'handle']);

        $this->assertDatabaseHas('data_imports', ['id' => $batch->id, 'status' => 'queued', 'processed_rows' => 0]);
        $this->assertDatabaseMissing('companies', ['name' => 'Não importar fora do tenant']);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_an_exhausted_import_job_does_not_store_exception_details(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'processing',
            'processing_attempts' => 1,
            'total_rows' => 1,
            'valid_rows' => 1,
        ]);

        (new ProcessDataImportChunk($organization->id, $batch->id, 1))->failed(new \RuntimeException('customer-secret@example.com'));

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertStringNotContainsString('customer-secret@example.com', $batch->last_error);
        $this->assertNull($batch->processing_started_at);
        $this->assertNotNull($batch->failed_at);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_import_page_only_lists_active_prospecting_templates_for_the_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $selectedTemplate = JourneyTemplate::query()->create([
            'name' => 'Jornada de prospecção ativa',
            'type' => 'prospecting',
            'trigger_type' => 'manual',
            'is_active' => true,
            'is_default' => false,
        ]);
        JourneyTemplate::query()->create([
            'name' => 'Jornada pós-venda ativa',
            'type' => 'post_sale',
            'trigger_type' => 'first_sale',
            'is_active' => true,
            'is_default' => false,
        ]);
        JourneyTemplate::query()->create([
            'name' => 'Jornada de prospecção arquivada',
            'type' => 'prospecting',
            'trigger_type' => 'manual',
            'is_active' => false,
            'is_default' => false,
        ]);
        app(TenantContext::class)->set($otherOrganization);
        JourneyTemplate::query()->create([
            'name' => 'Jornada de outra organização',
            'type' => 'prospecting',
            'trigger_type' => 'manual',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('imports/index')
                ->has('journeyTemplates', 1)
                ->where('journeyTemplates.0.id', $selectedTemplate->id)
                ->where('journeyTemplates.0.name', 'Jornada de prospecção ativa'));
    }

    public function test_a_user_without_an_active_membership_cannot_create_an_import_batch(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "name\nEscritório Sul\n"),
            ])
            ->assertForbidden();
    }

    public function test_a_salesperson_only_sees_import_batches_they_created(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $salesperson = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($salesperson, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);

        DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'original_filename' => 'owner-data.csv',
        ]);
        $salespersonImport = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $salesperson->id,
            'original_filename' => 'seller-data.csv',
        ]);

        $this->actingAs($salesperson)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('imports/index')
                ->has('imports.data', 1)
                ->where('imports.data.0.id', $salespersonImport->id));
    }

    public function test_an_owner_can_preview_csv_headers_and_sample_rows_before_importing(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Nome da empresa,Cidade\nEscritório Sul,Curitiba\n"),
            ])
            ->assertRedirect('/imports');

        $importId = DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports/'.$importId)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('imports/show')
                ->where('preview.headers.0', 'Nome da empresa')
                ->where('preview.headers.1', 'Cidade')
                ->where('preview.rows.0.0', 'Escritório Sul')
                ->where('preview.rows.0.1', 'Curitiba')
                ->where('preview.totalRows', 1));
    }

    public function test_an_owner_can_preview_a_real_xlsx_file(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => $this->xlsxUpload(),
            ])
            ->assertRedirect('/imports');

        $importId = DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports/'.$importId)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('imports/show')
                ->where('preview.headers.0', 'Nome da empresa')
                ->where('preview.rows.0.0', 'Escritório Norte')
                ->where('preview.totalRows', 1));
    }

    public function test_a_semicolon_delimited_csv_is_previewed_as_separate_columns(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent(
                    'companies.csv',
                    "Name;City;State\nEscritório Sul;Curitiba;PR\n",
                ),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports/'.$importId)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('imports/show')
                ->where('preview.headers.0', 'Name')
                ->where('preview.headers.1', 'City')
                ->where('preview.headers.2', 'State')
                ->where('preview.rows.0.0', 'Escritório Sul')
                ->where('preview.rows.0.1', 'Curitiba'));
    }

    public function test_an_import_creator_can_save_a_column_mapping_for_company_fields(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Nome,CNPJ,Cidade\nEmpresa Sul,123,Curitiba\n"),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports/'.$importId.'/mapping', [
                'mapping' => [
                    'name' => '0',
                    'tax_id' => '1',
                    'city' => '2',
                ],
            ])
            ->assertRedirect('/imports/'.$importId);

        $batch = DB::table('data_imports')->find($importId);
        $this->assertSame('mapped', $batch->status);
        $this->assertSame(['name' => 0, 'tax_id' => 1, 'city' => 2], json_decode($batch->mapping, true));
    }

    public function test_a_column_mapping_cannot_reference_a_column_outside_the_file(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Nome,Cidade\nEmpresa Sul,Curitiba\n"),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->from('/imports/'.$importId)
            ->post('/imports/'.$importId.'/mapping', [
                'mapping' => ['name' => '2'],
            ])
            ->assertRedirect('/imports/'.$importId)
            ->assertSessionHasErrors('mapping.name');

        $this->assertNull(DB::table('data_imports')->where('id', $importId)->value('mapping'));
    }

    public function test_preview_rejects_repeated_headers_without_normalizing_them_ambiguously(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Nome,nome\nEmpresa Sul,Outra\n"),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports/'.$importId)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('imports/show')
                ->where('fileError', 'Há nomes de coluna repetidos. Renomeie os cabeçalhos para continuar.')
                ->where('preview', null));
    }

    public function test_preview_rejects_a_file_that_exceeds_the_row_limit(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $rows = ['Nome'];

        for ($rowNumber = 1; $rowNumber <= 5001; $rowNumber++) {
            $rows[] = 'Empresa '.$rowNumber;
        }

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', implode("\n", $rows)),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports/'.$importId)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('imports/show')
                ->where('fileError', 'O arquivo ultrapassa o limite de 5.000 linhas. Divida a planilha em arquivos menores.')
                ->where('preview', null));
    }

    public function test_a_salesperson_cannot_preview_an_import_created_by_another_salesperson(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $otherSalesperson = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'salesperson']);
        $organization->users()->attach($otherSalesperson, ['role' => 'salesperson']);

        $this->actingAs($creator)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('companies.csv', "Nome\nEmpresa Sul\n"),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($otherSalesperson)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports/'.$importId)
            ->assertForbidden();
    }

    public function test_malformed_xlsx_uploads_are_reported_without_exposing_parser_details(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/imports', [
                'record_type' => 'companies',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not an XLSX archive'),
            ])
            ->assertRedirect('/imports');

        $importId = (int) DB::table('data_imports')->value('id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/imports/'.$importId)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('imports/show')
                ->where('fileError', 'Não foi possível ler o arquivo. Verifique se ele é um CSV ou XLSX válido.')
                ->where('preview', null));
    }

    private function xlsxUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'codex-import-xlsx-');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary XLSX fixture.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(['Nome da empresa', 'Cidade']));
            $writer->addRow(Row::fromValues(['Escritório Norte', 'Manaus']));
            $writer->close();

            $contents = file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if ($contents === false) {
            throw new \RuntimeException('Could not read the temporary XLSX fixture.');
        }

        return UploadedFile::fake()->createWithContent('companies.xlsx', $contents);
    }
}
