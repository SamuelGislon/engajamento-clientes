<?php

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\CustomerScore;
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
    $team = createManagedTeam($organization, $manager);
    assignTeamMember($organization, $team, $seller, 'salesperson');
    assignTeamMember($organization, $team, $otherSeller, 'salesperson');
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'name' => 'Escritório Horizonte',
        'relationship_status' => 'customer',
    ]);
    $otherCompany = Company::factory()->ownedBy($otherSeller)->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
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

it('renders configured score tier names colors and weights for the active organization', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $tiers = $settings->tiers;
    $tiers[3]['name'] = 'Conta estratégica';
    $tiers[3]['color'] = '#123456';
    $settings->update([
        'scoring_weights' => ['volume' => 35, 'frequency' => 20, 'potential' => 25, 'relationship' => 20],
        'tiers' => $tiers,
    ]);
    $company = Company::factory()->create(['organization_id' => $organization->id, 'name' => 'Conta Azul']);
    CustomerScore::query()->create([
        'company_id' => $company->id,
        'volume_points' => 35,
        'frequency_points' => 20,
        'potential_points' => 25,
        'relationship_points' => 20,
        'total_score' => 100,
        'tier' => 'A',
        'is_complete' => true,
        'last_calculated_at' => now(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/commercial/classification')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commercial/classification')
            ->where('scoringWeights.volume', 35)
            ->where('tiers.3.name', 'Conta estratégica')
            ->where('companies.data.0.customer_score.tier_name', 'Conta estratégica')
            ->where('companies.data.0.customer_score.tier_color', '#123456'));

    $this->get('/companies/'.$company->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scoringWeights.potential', 25)
            ->where('customerScore.tier_name', 'Conta estratégica'));
});
