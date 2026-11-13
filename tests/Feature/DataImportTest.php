<?php

namespace Tests\Feature;

use App\Domain\Imports\CompanyImportService;
use App\Domain\Tenancy\TenantContext;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
