<?php

namespace Tests\Feature;

use App\Domain\Imports\DataImportProcessor;
use App\Domain\Imports\SaleImportService;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessDataImportChunk;
use App\Models\Company;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class DataImportSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_import_a_historical_sale_and_recalculate_commercial_state(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Cliente Histórico',
            'relationship_status' => 'prospect',
        ]);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'external_id' => '0', 'company_name' => '1', 'seller_email' => '2',
            'description' => '3', 'amount' => '4', 'currency' => '5',
            'sold_at' => '6', 'status' => '7', 'notes' => '8',
        ];

        $importId = $this->uploadSales($owner, $session, [
            'External ID,Company name,Seller email,Description,Amount,Currency,Sold at,Status,Notes',
            '0,Cliente Histórico,seller@example.test,Compra histórica,"1.250,75",BRL,2026-08-10 14:30:00,confirmed,Importada do sistema anterior',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping])
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'validated',
            'total_rows' => 1,
            'valid_rows' => 1,
            'invalid_rows' => 0,
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $sale = Sale::query()->where('organization_id', $organization->id)->where('external_id', '0')->firstOrFail();
        $this->assertSame($company->id, $sale->company_id);
        $this->assertSame($seller->id, $sale->seller_id);
        $this->assertSame($owner->id, $sale->created_by);
        $this->assertSame('1250.75', $sale->amount);
        $this->assertSame('confirmed', $sale->status);
        $this->assertSame('2026-08-10 17:30:00', $sale->sold_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('customer', $company->fresh()->relationship_status);
        $this->assertDatabaseHas('customer_scores', [
            'organization_id' => $organization->id,
            'company_id' => $company->id,
        ]);
        $this->assertDatabaseHas('data_imports', [
            'id' => $importId,
            'status' => 'completed',
            'created_rows' => 1,
        ]);
    }

    public function test_queued_sales_import_rejects_sellers_whose_memberships_changed_after_validation(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $inactiveSeller = User::factory()->create(['email' => 'inactive-seller@example.test']);
        $removedFromTeamSeller = User::factory()->create(['email' => 'removed-team-seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($inactiveSeller, ['role' => 'salesperson']);
        $organization->users()->attach($removedFromTeamSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $team = Team::query()->create([
            'name' => 'Equipe das vendas importadas',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);
        assignTeamMember($organization, $team, $inactiveSeller, 'salesperson');
        assignTeamMember($organization, $team, $removedFromTeamSeller, 'salesperson');
        $firstCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa do vendedor desativado',
            'team_id' => $team->id,
        ]);
        $secondCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa do vendedor removido da equipe',
            'team_id' => $team->id,
        ]);
        $batch = DataImport::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'record_type' => 'sales',
            'status' => 'validated',
            'mapping' => [
                'company_name' => 0,
                'seller_email' => 1,
                'description' => 2,
                'amount' => 3,
                'currency' => 4,
                'sold_at' => 5,
                'status' => 6,
            ],
        ]);
        $analysis = app(SaleImportService::class)->analyze($batch, $owner, [
            [$firstCompany->name, $inactiveSeller->email, 'Venda bloqueada após revogação', '100.00', 'BRL', '2026-10-10', 'confirmed'],
            [$secondCompany->name, $removedFromTeamSeller->email, 'Venda bloqueada após remoção da equipe', '200.00', 'BRL', '2026-10-11', 'confirmed'],
        ], 2);
        $this->assertCount(2, $analysis['validRows']);

        $batch->update([
            'status' => 'queued',
            'total_rows' => 2,
            'valid_rows' => 2,
            'processing_attempts' => 1,
        ]);
        app(DataImportProcessor::class)->stageRows($batch, $analysis['validRows']);
        DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $inactiveSeller->id)
            ->update(['is_active' => false, 'updated_at' => now()]);
        DB::table('team_memberships')
            ->where('organization_id', $organization->id)
            ->where('team_id', $team->id)
            ->where('user_id', $removedFromTeamSeller->id)
            ->update(['is_active' => false, 'updated_at' => now()]);

        app()->call([new ProcessDataImportChunk($organization->id, $batch->id, 1), 'handle']);

        $this->assertDatabaseHas('data_imports', [
            'id' => $batch->id,
            'status' => 'completed',
            'created_rows' => 0,
            'invalid_rows' => 2,
        ]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_sales_import_rejects_a_seller_outside_the_importing_salesperson_authority(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $seller = User::factory()->create(['email' => 'mine@example.test']);
        $otherSeller = User::factory()->create(['email' => 'other@example.test']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->ownedBy($seller)->create([
            'organization_id' => $organization->id,
            'name' => 'Minha Carteira',
        ]);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_name' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];

        $importId = $this->uploadSales($seller, $session, [
            'Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'Minha Carteira,other@example.test,Compra não autorizada,100.00,BRL,2026-08-10,confirmed',
        ]);

        $this->actingAs($seller)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping])
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($seller)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $batch = DataImport::query()->findOrFail($importId);
        $this->assertSame(0, $batch->valid_rows);
        $this->assertSame(1, $batch->invalid_rows);

        $this->actingAs($seller)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'status' => 'completed', 'created_rows' => 0]);
    }

    public function test_sales_import_preserves_cancelled_history_without_marking_the_company_as_a_customer(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Prospecto sem venda confirmada',
            'relationship_status' => 'prospect',
        ]);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_name' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $importId = $this->uploadSales($owner, $session, [
            'Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'Prospecto sem venda confirmada,seller@example.test,Compra cancelada,100,BRL,2026-08-10,cancelled',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $sale = Sale::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('cancelled', $sale->status);
        $this->assertSame($owner->id, $sale->cancelled_by);
        $this->assertNotNull($sale->cancelled_at);
        $this->assertSame('prospect', $company->fresh()->relationship_status);
    }

    public function test_sales_import_does_not_import_an_external_id_that_already_exists(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Cliente existente']);
        $existingSale = Sale::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'seller_id' => $seller->id,
            'created_by' => $seller->id,
            'external_id' => 'legacy-duplicate-1',
        ]);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'external_id' => '0', 'company_name' => '1', 'seller_email' => '2',
            'description' => '3', 'amount' => '4', 'currency' => '5', 'sold_at' => '6', 'status' => '7',
        ];
        $importId = $this->uploadSales($owner, $session, [
            'External ID,Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'legacy-duplicate-1,Cliente existente,seller@example.test,Compra repetida,50,BRL,2026-08-10,confirmed',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 0, 'duplicate_rows' => 1]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertSame(1, Sale::query()->where('organization_id', $organization->id)->count());
        $this->assertSame($existingSale->id, Sale::query()->where('organization_id', $organization->id)->value('id'));
    }

    public function test_sales_import_cannot_resolve_a_company_from_another_organization(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $otherOrganization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($otherOrganization);
        Company::factory()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Empresa em outro tenant',
            'tax_id' => '12345678000195',
        ]);
        app(TenantContext::class)->set($organization);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_tax_id' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $importId = $this->uploadSales($owner, $session, [
            'Company tax id,Seller email,Description,Amount,Currency,Sold at,Status',
            '12345678000195,seller@example.test,Venda externa,100,BRL,2026-08-10,confirmed',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 0, 'invalid_rows' => 1]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_sales_import_rejects_ambiguous_dates_and_keeps_explicit_iso_dates(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Cliente de datas']);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_name' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $importId = $this->uploadSales($owner, $session, [
            'Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'Cliente de datas,seller@example.test,Data ambígua,100,BRL,10/08/2026,confirmed',
            'Cliente de datas,seller@example.test,Data ISO,100,BRL,2026-08-10T14:30:00-03:00,confirmed',
            'Cliente de datas,seller@example.test,Data ISO fracionária local,100,BRL,2026-08-10T14:30:00.123-03:00,confirmed',
            'Cliente de datas,seller@example.test,Data ISO fracionária UTC,100,BRL,2026-08-10T14:30:00.123Z,confirmed',
            'Cliente de datas,seller@example.test,Data ISO sem fuso,100,BRL,2026-08-10T14:30:00.123,confirmed',
            'Cliente de datas,seller@example.test,Data ISO precisão alta,100,BRL,2026-08-10T14:30:00.1234567Z,confirmed',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $batch = DataImport::query()->findOrFail($importId);
        $this->assertSame(5, $batch->valid_rows);
        $this->assertSame(1, $batch->invalid_rows);
        $this->assertContains('A data da venda usa um formato ambíguo. Use AAAA-MM-DD ou ISO 8601.', array_column($batch->validation_errors, 'message'));
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseCount('sales', 5);
        $this->assertDatabaseHas('sales', ['description' => 'Data ISO fracionária local', 'sold_at' => '2026-08-10 17:30:00']);
        $this->assertDatabaseHas('sales', ['description' => 'Data ISO fracionária UTC', 'sold_at' => '2026-08-10 14:30:00']);
        $this->assertDatabaseHas('sales', ['description' => 'Data ISO sem fuso', 'sold_at' => '2026-08-10 17:30:00']);
        $this->assertDatabaseHas('sales', ['description' => 'Data ISO precisão alta', 'sold_at' => '2026-08-10 14:30:00']);
    }

    public function test_sales_import_rejects_amounts_outside_the_sale_decimal_range_without_aborting_valid_rows(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Cliente de valores']);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_name' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $importId = $this->uploadSales($owner, $session, [
            'Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'Cliente de valores,seller@example.test,Valor fora do banco,1000000000000.00,BRL,2026-08-10,confirmed',
            'Cliente de valores,seller@example.test,Valor válido,100,BRL,2026-08-10,confirmed',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 1, 'invalid_rows' => 1]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('sales', ['description' => 'Valor válido', 'amount' => '100.00']);
    }

    public function test_sales_import_resolves_a_company_when_its_stored_tax_id_is_formatted(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Cliente CNPJ formatado',
            'tax_id' => '12.345.678/0001-95',
        ]);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_tax_id' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $importId = $this->uploadSales($owner, $session, [
            'Company tax id,Seller email,Description,Amount,Currency,Sold at,Status',
            '12345678000195,seller@example.test,Compra por CNPJ,100,BRL,2026-08-10,confirmed',
            '12.345.678/0001-95,seller@example.test,Segunda compra por CNPJ,150,BRL,2026-08-11,confirmed',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 2, 'invalid_rows' => 0]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseHas('sales', ['organization_id' => $organization->id, 'company_id' => $company->id, 'description' => 'Compra por CNPJ']);
        $this->assertDatabaseHas('sales', ['organization_id' => $organization->id, 'company_id' => $company->id, 'description' => 'Segunda compra por CNPJ']);
    }

    public function test_confirming_the_same_sales_import_twice_does_not_duplicate_sales_without_external_ids(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Cliente sem identificador']);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_name' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $lines = [
            'Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'Cliente sem identificador,seller@example.test,Compra sem identificador,300.00,BRL,2026-08-10,confirmed',
        ];

        $importId = $this->uploadSales($owner, $session, $lines);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_identical_sales_without_external_ids_in_the_same_file_are_both_imported(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Cliente com compras iguais']);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_name' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $importId = $this->uploadSales($owner, $session, [
            'Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'Cliente com compras iguais,seller@example.test,Compra presencial,100,BRL,2026-08-10,confirmed',
            'Cliente com compras iguais,seller@example.test,Compra presencial,100,BRL,2026-08-10,confirmed',
        ]);

        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 2, 'duplicate_rows' => 0]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_sales_import_does_not_reveal_an_external_id_from_a_hidden_sale(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $seller = User::factory()->create(['email' => 'mine@example.test']);
        $otherSeller = User::factory()->create(['email' => 'other@example.test']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->ownedBy($seller)->create([
            'organization_id' => $organization->id,
            'name' => 'Minha empresa visível',
        ]);
        $hiddenCompany = Company::factory()->ownedBy($otherSeller)->create([
            'organization_id' => $organization->id,
            'name' => 'Empresa oculta',
        ]);
        Sale::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $hiddenCompany->id,
            'seller_id' => $otherSeller->id,
            'created_by' => $otherSeller->id,
            'external_id' => 'hidden-sale-ref',
        ]);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'external_id' => '0', 'company_name' => '1', 'seller_email' => '2',
            'description' => '3', 'amount' => '4', 'currency' => '5', 'sold_at' => '6', 'status' => '7',
        ];
        $importId = $this->uploadSales($seller, $session, [
            'External ID,Company name,Seller email,Description,Amount,Currency,Sold at,Status',
            'hidden-sale-ref,Minha empresa visível,mine@example.test,Importação conflitante,100,BRL,2026-08-10,confirmed',
        ]);

        $this->actingAs($seller)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping]);
        $this->actingAs($seller)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);

        $batch = DataImport::query()->findOrFail($importId);
        $this->assertSame(0, $batch->duplicate_rows);
        $this->assertSame(1, $batch->invalid_rows);
        $this->assertSame('A linha não pode ser importada no escopo de acesso atual.', $batch->validation_errors[0]['message']);
        $this->assertStringNotContainsString('hidden-sale-ref', json_encode($batch->validation_errors));
    }

    public function test_sales_import_reads_a_typed_excel_date_from_xlsx(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create(['email' => 'seller@example.test']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Cliente XLSX']);
        $session = ['active_organization_id' => $organization->id];
        $mapping = [
            'company_name' => '0', 'seller_email' => '1', 'description' => '2',
            'amount' => '3', 'currency' => '4', 'sold_at' => '5', 'status' => '6',
        ];
        $path = tempnam(sys_get_temp_dir(), 'codex-sale-import-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([
            'Company name', 'Seller email', 'Description', 'Amount', 'Currency', 'Sold at', 'Status',
        ]));
        $writer->addRow(Row::fromValuesWithStyles([
            'Cliente XLSX', 'seller@example.test', 'Venda em XLSX', '200.50', 'BRL',
            new \DateTimeImmutable('2026-08-10T14:30:00+00:00'), 'confirmed',
        ], columnStyles: [5 => (new Style)->setFormat('yyyy-mm-dd hh:mm:ss')]));
        $writer->addRow(Row::fromValuesWithStyles([
            'Cliente XLSX', 'seller@example.test', 'Venda de data inteira XLSX', '75.00', 'BRL',
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'), 'confirmed',
        ], columnStyles: [5 => (new Style)->setFormat('yyyy-mm-dd')]));
        $writer->close();

        try {
            $this->actingAs($owner)->withSession($session)->post('/imports', [
                'record_type' => 'sales',
                'journey_mode' => 'none',
                'file' => UploadedFile::fake()->createWithContent('sales.xlsx', (string) file_get_contents($path)),
            ])->assertRedirect('/imports');
        } finally {
            @unlink($path);
        }

        $importId = (int) DB::table('data_imports')->orderByDesc('id')->value('id');
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/mapping', ['mapping' => $mapping])
            ->assertRedirect('/imports/'.$importId);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/validate')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseHas('data_imports', ['id' => $importId, 'valid_rows' => 2, 'invalid_rows' => 0]);
        $this->actingAs($owner)->withSession($session)->post('/imports/'.$importId.'/confirm')
            ->assertRedirect('/imports/'.$importId);
        $this->assertDatabaseHas('sales', [
            'organization_id' => $organization->id,
            'description' => 'Venda em XLSX',
            'sold_at' => '2026-08-10 17:30:00',
        ]);
        $this->assertDatabaseHas('sales', [
            'organization_id' => $organization->id,
            'description' => 'Venda de data inteira XLSX',
            'sold_at' => '2026-08-11 03:00:00',
        ]);
    }

    /** @param list<string> $lines */
    private function uploadSales(User $actor, array $session, array $lines): int
    {
        $this->actingAs($actor)->withSession($session)->post('/imports', [
            'record_type' => 'sales',
            'journey_mode' => 'none',
            'file' => UploadedFile::fake()->createWithContent('sales.csv', implode("\n", $lines)),
        ])->assertRedirect('/imports');

        return (int) DB::table('data_imports')->orderByDesc('id')->value('id');
    }
}
