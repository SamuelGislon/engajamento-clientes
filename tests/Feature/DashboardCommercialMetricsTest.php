<?php

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

it('shows persisted commercial metrics and attention actions scoped to the salesperson portfolio', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $inactiveCompany = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'name' => 'Cliente da minha carteira',
        'relationship_status' => 'customer',
    ]);
    $recentCompany = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $prospect = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);
    $foreignCompany = Company::factory()->ownedBy($otherSeller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    foreach ([
        [$inactiveCompany, $seller, 100, 'Compra antiga', '1000.00'],
        [$recentCompany, $seller, 10, 'Compra recente', '2000.00'],
        [$foreignCompany, $otherSeller, 110, 'Compra fora da carteira', '9000.00'],
    ] as [$company, $targetSeller, $days, $description, $amount]) {
        $this->actingAs($targetSeller)->withSession(['active_organization_id' => $organization->id]);
        $this->post("/companies/{$company->id}/sales", [
            'seller_id' => $targetSeller->id,
            'description' => $description,
            'amount' => $amount,
            'currency' => 'BRL',
            'sold_at' => now()->subDays($days)->toDateTimeString(),
        ])->assertRedirect();
    }
    Mail::fake();
    Notification::fake();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('commercialMetrics.customers_with_purchases', 2)
            ->where('commercialMetrics.prospects_without_purchases', 1)
            ->where('commercialMetrics.commercially_inactive', 1)
            ->where('commercialMetrics.pending_reactivation_tasks', 1)
            ->where('commercialMetrics.confirmed_sales_period', 1)
            ->where('commercialMetrics.revenue_period', '2000.00')
            ->where('commercialMetrics.average_ticket_period', '2000.00')
            ->where('commercialAttention.0.company.name', 'Cliente da minha carteira')
            ->has('commercialAttention.0.last_purchase_at')
            ->has('commercialAttention.0.suggested_action')
            ->where('commercialAttention.0.task.type', 'reactivation'));
});

it('does not seed fictitious demonstration accounts in production', function () {
    $this->app['env'] = 'production';

    app(DatabaseSeeder::class)->run();

    expect(User::query()->whereIn('email', [
        'owner@example.test',
        'manager@example.test',
        'ana@example.test',
        'bruno@example.test',
    ])->count())->toBe(0);
});
