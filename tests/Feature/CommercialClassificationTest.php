<?php

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows an incomplete commercial classification only for the salesperson portfolio', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'name' => 'Escritório Horizonte',
        'relationship_status' => 'customer',
    ]);
    $otherCompany = Company::factory()->ownedBy($otherSeller)->create([
        'organization_id' => $organization->id,
        'name' => 'Escritório de outra carteira',
        'relationship_status' => 'customer',
    ]);

    $this->actingAs($manager)->withSession(['active_organization_id' => $organization->id]);
    foreach ([[$company, $seller], [$otherCompany, $otherSeller]] as [$targetCompany, $targetSeller]) {
        $this->post("/companies/{$targetCompany->id}/sales", [
            'seller_id' => $targetSeller->id,
            'description' => 'Compra registrada',
            'amount' => '1000.00',
            'currency' => 'BRL',
            'sold_at' => now()->subDay()->toDateTimeString(),
        ])->assertRedirect();
    }

    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id])
        ->get('/commercial/classification')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commercial/classification')
            ->where('companies.total', 1)
            ->where('companies.data.0.name', 'Escritório Horizonte')
            ->where('companies.data.0.customer_score.is_complete', false)
            ->where('companies.data.0.customer_score.tier', null)
            ->where('companies.data.0.volume_12_months', '1000.00'));
});
