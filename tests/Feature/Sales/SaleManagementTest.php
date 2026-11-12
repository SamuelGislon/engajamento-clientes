<?php

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

it('records a confirmed sale for a company in the salesperson portfolio', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Primeiro pedido de revestimentos',
            'amount' => '12500.50',
            'currency' => 'BRL',
            'sold_at' => '2026-10-20 14:30:00',
            'notes' => 'Pedido fictício usado no teste.',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('sales', [
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'seller_id' => $seller->id,
        'created_by' => $seller->id,
        'description' => 'Primeiro pedido de revestimentos',
        'amount' => '12500.50',
        'currency' => 'BRL',
        'status' => 'confirmed',
    ]);
    expect($company->fresh()->relationship_status)->toBe('customer');
});

it('stores local sale times as UTC using the organization timezone', function () {
    $this->travelTo('2026-11-11 01:00:00');
    $organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Horário local de São Paulo',
            'amount' => '125.00',
            'currency' => 'BRL',
            'sold_at' => '2026-11-10 21:30:00',
        ])->assertRedirect();

    expect(Sale::query()->firstOrFail()->sold_at->utc()->format('Y-m-d H:i:s'))
        ->toBe('2026-11-11 00:30:00');
});

it('creates valid organization memberships when using the sale factory', function () {
    $sale = Sale::factory()->create();

    expect($sale->organization->users()->whereKey($sale->seller_id)->exists())->toBeTrue()
        ->and($sale->organization->users()->whereKey($sale->created_by)->exists())->toBeTrue();
});

it('resolves commercial inactivity when the only confirmed sale is cancelled', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra antiga',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    Mail::fake();
    Notification::fake();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    $sale = Sale::query()->firstOrFail();
    $task = Task::query()->where('company_id', $company->id)->where('type', 'reactivation')->firstOrFail();

    $this->post("/sales/{$sale->id}/cancel")->assertRedirect();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    expect(DB::table('commercial_alert_occurrences')
        ->where('company_id', $company->id)->where('type', 'commercial_inactivity')->where('status', 'active')->exists())->toBeFalse()
        ->and($task->fresh()->status)->toBe('cancelled');
});

it('ends prospecting and starts a configured post-sale journey on a prospect first purchase', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);
    $journeys = app(JourneyService::class);
    $prospectingTemplate = $journeys->ensureDefaultTemplate($organization);
    $prospectingEnrollment = $journeys->enroll($company, $prospectingTemplate, $seller);
    $prospectingTask = $prospectingEnrollment->tasks()->firstOrFail();
    $postSaleTemplate = JourneyTemplate::query()->create([
        'name' => 'Pós-primeira compra',
        'type' => 'post_sale',
        'trigger_type' => 'first_sale',
        'is_active' => true,
        'is_default' => false,
    ]);
    $postSaleTemplate->steps()->create([
        'title' => 'Acompanhar primeira compra',
        'description' => 'Verificar a experiência do escritório.',
        'position' => 1,
        'delay_days' => 3,
        'task_title' => 'Acompanhar a primeira compra de {company}.',
        'task_type' => 'follow_up',
        'task_priority' => 'normal',
    ]);
    app(CommercialSettingsService::class)->forOrganization($organization)
        ->update(['post_sale_journey_template_id' => $postSaleTemplate->id]);
    Mail::fake();
    Notification::fake();

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Primeira compra',
            'amount' => '1000.00',
            'currency' => 'BRL',
            'sold_at' => now()->subDay()->toDateTimeString(),
        ])->assertRedirect();

    expect($prospectingEnrollment->fresh()->status)->toBe('cancelled')
        ->and($prospectingTask->fresh()->status)->toBe('cancelled')
        ->and($postSaleTemplate->enrollments()->where('company_id', $company->id)->where('status', 'active')->exists())->toBeTrue();
});

it('rejects a sale with a negative amount', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->from("/companies/{$company->id}")
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Valor inválido',
            'amount' => '-1.00',
            'currency' => 'BRL',
            'sold_at' => now()->subDay()->toDateTimeString(),
        ]);

    $response->assertSessionHasErrors('amount');
    $this->assertDatabaseMissing('sales', ['description' => 'Valor inválido']);
});

it('rejects a sale dated in the future', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->from("/companies/{$company->id}")
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Data futura',
            'amount' => '100.00',
            'currency' => 'BRL',
            'sold_at' => now()->addDay()->toDateTimeString(),
        ]);

    $response->assertSessionHasErrors('sold_at');
    $this->assertDatabaseMissing('sales', ['description' => 'Data futura']);
});

it('does not let a salesperson record a sale for a company outside their portfolio', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($otherSeller)->create([
        'organization_id' => $organization->id,
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Venda sem autorização',
            'amount' => '100.00',
            'currency' => 'BRL',
            'sold_at' => now()->subDay()->toDateTimeString(),
        ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('sales', ['description' => 'Venda sem autorização']);
});

it('does not let a salesperson attribute a sale to another organization member', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->from("/companies/{$company->id}")
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $otherSeller->id,
            'description' => 'Atribuição inválida',
            'amount' => '100.00',
            'currency' => 'BRL',
            'sold_at' => now()->subDay()->toDateTimeString(),
        ]);

    $response->assertSessionHasErrors('seller_id');
    $this->assertDatabaseMissing('sales', ['description' => 'Atribuição inválida']);
});

it('allows an authorized user to edit a sale while preserving it when cancelled', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Pedido original',
            'amount' => '100.00',
            'currency' => 'BRL',
            'sold_at' => now()->subDay()->toDateTimeString(),
        ]);
    $sale = Sale::query()->firstOrFail();

    $this->get("/sales/{$sale->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/edit')
            ->where('sale.id', $sale->id));

    $updateResponse = $this->patch("/sales/{$sale->id}", [
        'description' => 'Pedido corrigido',
        'amount' => '250.50',
        'currency' => 'BRL',
        'sold_at' => now()->subDay()->toDateTimeString(),
        'notes' => 'Valor conferido com o vendedor.',
    ]);

    $updateResponse->assertRedirect();
    $this->assertDatabaseHas('sales', [
        'id' => $sale->id,
        'description' => 'Pedido corrigido',
        'amount' => '250.50',
    ]);

    $cancelResponse = $this->post("/sales/{$sale->id}/cancel");

    $cancelResponse->assertRedirect();
    $this->assertDatabaseHas('sales', [
        'id' => $sale->id,
        'status' => 'cancelled',
        'cancelled_by' => $seller->id,
    ]);

    $this->get(route('companies.show', $company->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('salesSummary.total_amount', '0.00')
            ->where('salesSummary.purchase_count', 0));
});

it('shows confirmed purchase totals and last purchase on the company page', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);

    foreach ([['Compra anterior', '100.00', now()->subDays(20)], ['Compra recente', '300.00', now()->subDays(3)]] as [$description, $amount, $soldAt]) {
        $this->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => $description,
            'amount' => $amount,
            'currency' => 'BRL',
            'sold_at' => $soldAt->toDateTimeString(),
        ])->assertRedirect();
    }

    $this->get(route('companies.show', $company->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('companies/show')
            ->where('salesSummary.total_amount', '400.00')
            ->where('salesSummary.purchase_count', 2)
            ->where('salesSummary.average_amount', '200.00')
            ->where('salesSummary.last_sold_at', fn (string $value) => str_starts_with($value, now()->subDays(3)->toDateString()))
            ->has('sales', 2));
});

it('lists only sales for companies in the salesperson portfolio and applies company filters', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $ownedCompany = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);
    $otherCompany = Company::factory()->ownedBy($otherSeller)->create(['organization_id' => $organization->id]);

    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$ownedCompany->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Venda da carteira',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDay()->toDateTimeString(),
    ])->assertRedirect();
    $this->actingAs($otherSeller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$otherCompany->id}/sales", [
        'seller_id' => $otherSeller->id,
        'description' => 'Venda de outra carteira',
        'amount' => '900.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDay()->toDateTimeString(),
    ])->assertRedirect();

    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id])
        ->get('/sales')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/index')
            ->where('sales.total', 1)
            ->where('sales.data.0.description', 'Venda da carteira'));

    $this->get("/sales?company_id={$otherCompany->id}")
        ->assertSessionHasErrors('company_id');
});
