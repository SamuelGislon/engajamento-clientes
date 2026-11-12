<?php

namespace Tests\Feature;

use App\Domain\Imports\CompanyImportService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
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
