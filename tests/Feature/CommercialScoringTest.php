<?php

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('calculates commercial scores from confirmed sales and records meaningful tier changes', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);

    foreach ([now()->subMonths(4), now()->subMonths(2), now()->subDays(10)] as $index => $soldAt) {
        $this->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Compra '.($index + 1),
            'amount' => '25000.00',
            'currency' => 'BRL',
            'sold_at' => $soldAt->toDateTimeString(),
        ])->assertRedirect();
    }

    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'volume_points' => 24,
        'frequency_points' => 15,
        'potential_points' => null,
        'relationship_points' => null,
        'total_score' => null,
        'tier' => null,
        'is_complete' => false,
    ]);

    $this->patch("/companies/{$company->id}/commercial-score", [
        'potential_points' => 20,
        'relationship_points' => 18,
    ])->assertRedirect();

    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'total_score' => 77,
        'tier' => 'A',
        'is_complete' => true,
    ]);

    $historyCount = DB::table('customer_score_histories')
        ->where('organization_id', $organization->id)
        ->where('company_id', $company->id)
        ->count();
    app(CustomerScoreService::class)
        ->recalculateForCompany($company, 'reprocessamento de teste');
    expect(DB::table('customer_score_histories')
        ->where('organization_id', $organization->id)
        ->where('company_id', $company->id)
        ->count())->toBe($historyCount);

    $this->patch("/companies/{$company->id}/commercial-score", [
        'potential_points' => 16,
        'relationship_points' => 18,
    ])->assertRedirect();
    $this->assertDatabaseHas('customer_scores', [
        'company_id' => $company->id,
        'total_score' => 73,
        'tier' => 'B',
    ]);

    $this->get(route('companies.show', $company->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('customerScore.total_score', 73)
            ->where('customerScore.tier', 'B')
            ->has('scoreHistory'));
});

it('rejects manual commercial score values above the configured criterion maximum', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->from("/companies/{$company->id}")
        ->patch("/companies/{$company->id}/commercial-score", [
            'potential_points' => 21,
            'relationship_points' => 10,
        ]);

    $response->assertSessionHasErrors('potential_points');
});

it('applies configured volume and frequency points below at and above each band boundary', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'volume_score_bands' => [
            ['max_amount' => '0.00', 'points' => 0],
            ['max_amount' => '100.00', 'points' => 10],
            ['max_amount' => '200.00', 'points' => 20],
            ['max_amount' => null, 'points' => 40],
        ],
    ]);
    $scoring = app(CustomerScoreService::class);

    foreach ([
        '0.00' => 0,
        '0.01' => 10,
        '99.99' => 10,
        '100.00' => 10,
        '100.01' => 20,
        '199.99' => 20,
        '200.00' => 20,
        '200.01' => 40,
    ] as $amount => $expectedPoints) {
        $company = Company::factory()->create(['organization_id' => $organization->id]);
        Sale::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'amount' => $amount,
            'sold_at' => now()->subDay(),
        ]);

        expect($scoring->recalculateForCompany($company, 'boundary test')->volume_points)
            ->toBe($expectedPoints);
    }

    foreach ([0 => 0, 1 => 5, 2 => 10, 3 => 15, 4 => 20, 5 => 20] as $purchaseCount => $expectedPoints) {
        $company = Company::factory()->create(['organization_id' => $organization->id]);
        Sale::factory()->count($purchaseCount)->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'amount' => '0.00',
            'sold_at' => now()->subDay(),
        ]);

        expect($scoring->recalculateForCompany($company, 'frequency boundary test')->frequency_points)
            ->toBe($expectedPoints);
    }
});
