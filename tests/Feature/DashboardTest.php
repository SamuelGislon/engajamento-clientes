<?php

namespace Tests\Feature;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommercialAlertOccurrence;
use App\Models\CommercialScoreRecalculation;
use App\Models\Company;
use App\Models\CustomerScore;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        $this->withSession(['active_organization_id' => $organization->id]);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_uses_organization_tier_labels_and_colors(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $settings = app(CommercialSettingsService::class)->forOrganization($organization);
        $tiers = $settings->tiers;
        foreach ($tiers as &$tier) {
            if ($tier['key'] === 'A') {
                $tier['name'] = 'Conta Farol';
                $tier['color'] = '#123456';
            }
        }
        unset($tier);
        $settings->update(['tiers' => $tiers]);
        $company = Company::factory()->create(['organization_id' => $organization->id]);
        CustomerScore::query()->create([
            'company_id' => $company->id,
            'volume_points' => 40,
            'frequency_points' => 20,
            'potential_points' => 20,
            'relationship_points' => 20,
            'total_score' => 100,
            'tier' => 'A',
            'is_complete' => true,
            'last_calculated_at' => now(),
        ]);
        CommercialAlertOccurrence::query()->create([
            'company_id' => $company->id,
            'type' => 'relationship_inactivity',
            'occurrence_key' => 'dashboard-tier-label-test',
            'anchor_at' => now()->subDays(30),
            'status' => 'active',
            'reason' => 'Sem contato recente.',
            'first_detected_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('commercialTierLabels.A.name', 'Conta Farol')
                ->where('commercialTierLabels.A.color', '#123456')
                ->where('commercialAttention.0.tier_name', 'Conta Farol')
                ->where('commercialAttention.0.tier_color', '#123456'));
    }

    public function test_dashboard_identifies_partial_score_recalculations(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $settings = app(CommercialSettingsService::class)->forOrganization($organization);
        $recalculation = CommercialScoreRecalculation::query()->create([
            'requested_by' => $user->id,
            'status' => 'processing',
            'total_companies' => 100,
            'processed_companies' => 50,
            'reason' => 'Test dashboard partial progress',
            'settings_snapshot' => [
                'scoring_weights' => $settings->scoring_weights,
                'volume_score_bands' => $settings->volume_score_bands,
                'frequency_score_bands' => $settings->frequency_score_bands,
                'tiers' => $settings->tiers,
            ],
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('scoreRecalculation.status', 'processing')
                ->where('scoreRecalculation.processed_companies', 50)
                ->where('scoreRecalculation.total_companies', 100)
                ->where('canManageCommercialSettings', true));
    }

    public function test_dashboard_hides_organization_wide_score_progress_from_salespeople(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $settings = app(CommercialSettingsService::class)->forOrganization($organization);
        CommercialScoreRecalculation::query()->create([
            'requested_by' => $user->id,
            'status' => 'processing',
            'total_companies' => 100,
            'processed_companies' => 50,
            'reason' => 'Test salesperson dashboard privacy',
            'settings_snapshot' => [
                'scoring_weights' => $settings->scoring_weights,
                'volume_score_bands' => $settings->volume_score_bands,
                'frequency_score_bands' => $settings->frequency_score_bands,
                'tiers' => $settings->tiers,
            ],
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('scoreRecalculation.status', 'processing')
                ->missing('scoreRecalculation.processed_companies')
                ->missing('scoreRecalculation.total_companies')
                ->where('canManageCommercialSettings', false));
    }

    public function test_dashboard_hides_organization_wide_score_progress_from_managers(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user, ['role' => 'manager']);
        app(TenantContext::class)->set($organization);
        $settings = app(CommercialSettingsService::class)->forOrganization($organization);
        CommercialScoreRecalculation::query()->create([
            'requested_by' => $user->id,
            'status' => 'processing',
            'total_companies' => 100,
            'processed_companies' => 50,
            'reason' => 'Test manager dashboard privacy',
            'settings_snapshot' => [
                'scoring_weights' => $settings->scoring_weights,
                'volume_score_bands' => $settings->volume_score_bands,
                'frequency_score_bands' => $settings->frequency_score_bands,
                'tiers' => $settings->tiers,
            ],
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('scoreRecalculation.status', 'processing')
                ->missing('scoreRecalculation.processed_companies')
                ->missing('scoreRecalculation.total_companies')
                ->where('canManageCommercialSettings', true));
    }
}
