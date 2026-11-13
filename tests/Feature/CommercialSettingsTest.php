<?php

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\RecalculateOrganizationCustomerScores;
use App\Models\CommercialScoreRecalculation;
use App\Models\CommercialSetting;
use App\Models\Company;
use App\Models\CustomerScore;
use App\Models\CustomerScoreHistory;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('shows organization-scoped default commercial settings to a manager', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/commercial-settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commercial/settings')
            ->where('settings.sales_inactivity_days', 90)
            ->where('settings.relationship_inactivity_days', 30)
            ->where('settings.reactivation_repeat_cooldown_days', 60));
});

it('hides organization-wide score progress counts from managers on the settings page', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    CommercialScoreRecalculation::query()->create([
        'requested_by' => $manager->id,
        'status' => 'processing',
        'total_companies' => 100,
        'processed_companies' => 50,
        'reason' => 'Test settings page privacy',
        'settings_snapshot' => [
            'scoring_weights' => $settings->scoring_weights,
            'volume_score_bands' => $settings->volume_score_bands,
            'frequency_score_bands' => $settings->frequency_score_bands,
            'tiers' => $settings->tiers,
        ],
    ]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/commercial-settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commercial/settings')
            ->where('scoreRecalculation.status', 'processing')
            ->missing('scoreRecalculation.processed_companies')
            ->missing('scoreRecalculation.total_companies'));
});

it('allows a manager to adjust commercial alert and contact intervals', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 75,
            'relationship_inactivity_days' => 25,
            'reactivation_repeat_cooldown_days' => 45,
            'contact_frequency_days' => ['A' => 12, 'B' => 28, 'C' => 40, 'D' => 55],
        ])
        ->assertRedirect('/commercial-settings');

    $this->assertDatabaseHas('commercial_settings', [
        'organization_id' => $organization->id,
        'sales_inactivity_days' => 75,
        'relationship_inactivity_days' => 25,
        'reactivation_repeat_cooldown_days' => 45,
    ]);
});

it('allows a manager to configure scoring weights, commercial bands and named colored tiers', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);

    $weights = ['volume' => 30, 'frequency' => 25, 'potential' => 25, 'relationship' => 20];
    $volumeBands = [
        ['max_amount' => '0.00', 'points' => 0],
        ['max_amount' => '1000.00', 'points' => 10],
        ['max_amount' => null, 'points' => 30],
    ];
    $frequencyBands = [
        ['min_purchases' => 0, 'points' => 0],
        ['min_purchases' => 2, 'points' => 25],
    ];
    $tiers = [
        ['key' => 'D', 'name' => 'Inicial', 'min_score' => 0, 'max_score' => 24, 'color' => '#64748b'],
        ['key' => 'C', 'name' => 'Em desenvolvimento', 'min_score' => 25, 'max_score' => 49, 'color' => '#0ea5e9'],
        ['key' => 'B', 'name' => 'Prioritário', 'min_score' => 50, 'max_score' => 74, 'color' => '#8b5cf6'],
        ['key' => 'A', 'name' => 'Estratégico', 'min_score' => 75, 'max_score' => 100, 'color' => '#f59e0b'],
    ];

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'scoring_weights' => $weights,
            'volume_score_bands' => $volumeBands,
            'frequency_score_bands' => $frequencyBands,
            'tiers' => $tiers,
        ])
        ->assertRedirect('/commercial-settings');

    $settings = CommercialSetting::query()->where('organization_id', $organization->id)->firstOrFail();
    $this->assertSame($weights, $settings->scoring_weights);
    $this->assertSame($volumeBands, $settings->volume_score_bands);
    $this->assertSame($frequencyBands, $settings->frequency_score_bands);
    $this->assertSame($tiers, $settings->tiers);
});

it('rejects incomplete scoring weights and overlapping commercial score ranges', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->from('/commercial-settings')
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'scoring_weights' => ['volume' => 30, 'frequency' => 25, 'potential' => 25, 'relationship' => 19],
            'volume_score_bands' => [
                ['max_amount' => '1000.00', 'points' => 10],
                ['max_amount' => '500.00', 'points' => 20],
                ['max_amount' => null, 'points' => 30],
            ],
            'frequency_score_bands' => [
                ['min_purchases' => 0, 'points' => 0],
                ['min_purchases' => 2, 'points' => 25],
            ],
            'tiers' => [
                ['key' => 'D', 'name' => 'Inicial', 'min_score' => 0, 'max_score' => 24, 'color' => '#64748b'],
                ['key' => 'C', 'name' => 'Regular', 'min_score' => 24, 'max_score' => 49, 'color' => '#0ea5e9'],
                ['key' => 'B', 'name' => 'Prioritário', 'min_score' => 50, 'max_score' => 74, 'color' => '#8b5cf6'],
                ['key' => 'A', 'name' => 'Estratégico', 'min_score' => 75, 'max_score' => 100, 'color' => '#f59e0b'],
            ],
        ])
        ->assertSessionHasErrors(['scoring_weights', 'volume_score_bands', 'tiers']);
});

it('recalculates existing organization scores after weight changes and keeps score history', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $company = Company::factory()->create(['organization_id' => $organization->id]);
    CustomerScore::query()->create([
        'company_id' => $company->id,
        'volume_points' => 0,
        'frequency_points' => 0,
        'potential_points' => 20,
        'relationship_points' => 20,
        'total_score' => 40,
        'tier' => 'C',
        'is_complete' => true,
        'last_calculated_at' => now(),
    ]);
    CustomerScoreHistory::query()->create([
        'company_id' => $company->id,
        'volume_points' => 0,
        'frequency_points' => 0,
        'potential_points' => 20,
        'relationship_points' => 20,
        'total_score' => 40,
        'tier' => 'C',
        'tier_name' => 'Faixa C anterior',
        'tier_color' => '#123456',
        'is_complete' => true,
        'reason' => 'Registro anterior',
        'recorded_at' => now()->subDay(),
    ]);

    $otherOrganization = Organization::factory()->create();
    app(TenantContext::class)->set($otherOrganization);
    $otherCompany = Company::factory()->create(['organization_id' => $otherOrganization->id]);
    CustomerScore::query()->create([
        'company_id' => $otherCompany->id,
        'volume_points' => 0,
        'frequency_points' => 0,
        'potential_points' => 20,
        'relationship_points' => 20,
        'total_score' => 40,
        'tier' => 'C',
        'is_complete' => true,
        'last_calculated_at' => now(),
    ]);
    app(TenantContext::class)->set($organization);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'scoring_weights' => ['volume' => 40, 'frequency' => 20, 'potential' => 30, 'relationship' => 10],
            'volume_score_bands' => $settings->volume_score_bands,
            'frequency_score_bands' => $settings->frequency_score_bands,
            'tiers' => $settings->tiers,
        ])
        ->assertRedirect('/commercial-settings');

    $score = CustomerScore::query()->where('company_id', $company->id)->firstOrFail();
    $this->assertSame(10, $score->relationship_points);
    $this->assertSame(30, $score->total_score);
    $this->assertDatabaseHas('customer_score_histories', [
        'company_id' => $company->id,
        'reason' => 'Registro anterior',
        'total_score' => 40,
        'tier_name' => 'Faixa C anterior',
        'tier_color' => '#123456',
    ]);
    $this->assertDatabaseHas('customer_score_histories', [
        'company_id' => $company->id,
        'reason' => 'Configurações comerciais atualizadas',
        'total_score' => 30,
        'tier_name' => 'Acompanhamento regular',
        'tier_color' => '#0ea5e9',
    ]);
    $this->assertSame(2, CustomerScoreHistory::query()->where('company_id', $company->id)->count());
    app(TenantContext::class)->set($otherOrganization);
    $this->assertSame(20, CustomerScore::query()->where('company_id', $otherCompany->id)->firstOrFail()->relationship_points);
});

it('uses configured criterion weights when updating a company score', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'owner']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'scoring_weights' => ['volume' => 40, 'frequency' => 20, 'potential' => 30, 'relationship' => 10],
    ]);
    $company = Company::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/companies/'.$company->id.'/commercial-score', [
            'potential_points' => 25,
            'relationship_points' => 10,
        ])
        ->assertRedirect('/companies/'.$company->id);

    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'potential_points' => 25,
        'relationship_points' => 10,
    ]);

    $this->patch('/companies/'.$company->id.'/commercial-score', [
        'potential_points' => 31,
    ])->assertSessionHasErrors('potential_points');
});

it('revalidates manual score criteria against the current weights inside the write transaction', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'scoring_weights' => ['volume' => 40, 'frequency' => 30, 'potential' => 10, 'relationship' => 20],
    ]);
    $company = Company::factory()->create(['organization_id' => $organization->id]);

    $score = app(CustomerScoreService::class)->updateManualCriteria(
        $company,
        ['potential_points' => 20, 'relationship_points' => 30],
        'Regra atualizada durante a edição',
    );

    expect($score->potential_points)->toBe(10)
        ->and($score->relationship_points)->toBe(20);
});

it('queues organization score recalculation and leaves progress visible when scoring rules change', function () {
    Bus::fake();
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $company = Company::factory()->create(['organization_id' => $organization->id]);
    $score = CustomerScore::query()->create([
        'company_id' => $company->id,
        'volume_points' => 0,
        'frequency_points' => 0,
        'potential_points' => 20,
        'relationship_points' => 20,
        'total_score' => 40,
        'tier' => 'C',
        'is_complete' => true,
        'last_calculated_at' => now(),
    ]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'scoring_weights' => ['volume' => 40, 'frequency' => 30, 'potential' => 10, 'relationship' => 20],
            'volume_score_bands' => $settings->volume_score_bands,
            'frequency_score_bands' => [
                ['min_purchases' => 0, 'points' => 0],
                ['min_purchases' => 4, 'points' => 30],
            ],
            'tiers' => $settings->tiers,
        ])
        ->assertRedirect('/commercial-settings');

    Bus::assertDispatched(RecalculateOrganizationCustomerScores::class);
    expect($score->refresh()->potential_points)->toBe(20);
});

it('does not queue a score recalculation when only tier names and colors change', function () {
    Bus::fake();
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
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

    $this->actingAs($owner)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'scoring_weights' => $settings->scoring_weights,
            'volume_score_bands' => $settings->volume_score_bands,
            'frequency_score_bands' => $settings->frequency_score_bands,
            'tiers' => $tiers,
        ])
        ->assertRedirect('/commercial-settings');

    Bus::assertNothingDispatched();
    $this->assertDatabaseCount('commercial_score_recalculations', 0);
});

it('does not let a salesperson change organization-wide commercial settings', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/commercial-settings')
        ->assertForbidden();
});

it('allows a manager to configure only a valid organization reactivation journey', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $reactivation = JourneyTemplate::query()->create([
        'name' => 'Reativação Comercial',
        'type' => 'reactivation',
        'trigger_type' => 'sales_inactivity',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'reactivation_journey_template_id' => $reactivation->id,
        ])->assertRedirect('/commercial-settings');

    $this->assertDatabaseHas('commercial_settings', [
        'organization_id' => $organization->id,
        'reactivation_journey_template_id' => $reactivation->id,
    ]);
});

it('rejects a reactivation template with the wrong trigger or from another organization', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $wrongTrigger = JourneyTemplate::query()->create([
        'name' => 'Jornada de reativação sem gatilho',
        'type' => 'reactivation',
        'trigger_type' => 'manual',
        'is_active' => true,
    ]);
    app(TenantContext::class)->set($foreignOrganization);
    $foreignTemplate = JourneyTemplate::query()->create([
        'name' => 'Jornada estrangeira',
        'type' => 'reactivation',
        'trigger_type' => 'sales_inactivity',
        'is_active' => true,
    ]);
    app(TenantContext::class)->set($organization);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->from('/commercial-settings')
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'reactivation_journey_template_id' => $wrongTrigger->id,
        ])->assertSessionHasErrors('reactivation_journey_template_id');

    $this->patch('/commercial-settings', [
        'sales_inactivity_days' => 90,
        'relationship_inactivity_days' => 30,
        'reactivation_repeat_cooldown_days' => 60,
        'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
        'reactivation_journey_template_id' => $foreignTemplate->id,
    ])->assertSessionHasErrors('reactivation_journey_template_id');
});

it('resumes a failed recalculation from its committed tenant-scoped cursor', function () {
    Bus::fake();
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'scoring_weights' => ['volume' => 40, 'frequency' => 20, 'potential' => 10, 'relationship' => 30],
    ]);

    $companyIds = [];
    for ($index = 0; $index < 51; $index++) {
        $company = Company::factory()->create(['organization_id' => $organization->id]);
        $companyIds[] = $company->id;
        CustomerScore::query()->create([
            'company_id' => $company->id,
            'volume_points' => 0,
            'frequency_points' => 0,
            'potential_points' => 20,
            'relationship_points' => 20,
            'total_score' => 40,
            'tier' => 'C',
            'is_complete' => true,
            'last_calculated_at' => now(),
        ]);
    }

    $otherOrganization = Organization::factory()->create();
    app(TenantContext::class)->set($otherOrganization);
    $otherCompany = Company::factory()->create(['organization_id' => $otherOrganization->id]);
    CustomerScore::query()->create([
        'company_id' => $otherCompany->id,
        'volume_points' => 0,
        'frequency_points' => 0,
        'potential_points' => 20,
        'relationship_points' => 20,
        'total_score' => 40,
        'tier' => 'C',
        'is_complete' => true,
        'last_calculated_at' => now(),
    ]);

    app(TenantContext::class)->set($organization);
    $recalculation = CommercialScoreRecalculation::query()->create([
        'requested_by' => $manager->id,
        'status' => 'queued',
        'total_companies' => 51,
        'processed_companies' => 0,
        'reason' => 'Configurações comerciais atualizadas',
        'settings_snapshot' => [
            'scoring_weights' => $settings->scoring_weights,
            'volume_score_bands' => $settings->volume_score_bands,
            'frequency_score_bands' => $settings->frequency_score_bands,
            'tiers' => $settings->tiers,
        ],
    ]);
    $job = new RecalculateOrganizationCustomerScores($organization->id, $recalculation->id, 1);
    $job->handle(app(CustomerScoreService::class));

    $recalculation->refresh();
    expect($recalculation->status)->toBe('processing')
        ->and($recalculation->processed_companies)->toBe(50)
        ->and($recalculation->last_company_id)->toBe($companyIds[49]);
    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $companyIds[0],
        'potential_points' => 10,
    ]);

    $lateCompany = Company::factory()->create(['organization_id' => $organization->id]);
    CustomerScore::query()->create([
        'company_id' => $lateCompany->id,
        'volume_points' => 0,
        'frequency_points' => 0,
        'potential_points' => 20,
        'relationship_points' => 20,
        'total_score' => 40,
        'tier' => 'C',
        'is_complete' => true,
        'last_calculated_at' => now(),
    ]);

    $failingScores = Mockery::mock(CustomerScoreService::class)->makePartial();
    $failingScores->shouldReceive('recalculateForCompany')->once()->andThrow(new RuntimeException('injected failure'));
    expect(fn () => $job->handle($failingScores))->toThrow(RuntimeException::class);
    expect($recalculation->refresh()->status)->toBe('processing');
    DB::table('commercial_score_recalculations')
        ->where('id', $recalculation->id)
        ->update(['updated_at' => now()->subMinutes(11)]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('commercial-settings.score-recalculations.resume', $recalculation->id))
        ->assertRedirect('/commercial-settings');

    $recalculation->refresh();
    expect($recalculation->status)->toBe('queued')
        ->and($recalculation->processed_companies)->toBe(50)
        ->and($recalculation->last_company_id)->toBe($companyIds[49])
        ->and($recalculation->attempt_number)->toBe(2);

    $job->failed(new RuntimeException('late callback from the old attempt'));
    expect($recalculation->refresh()->status)->toBe('queued');

    (new RecalculateOrganizationCustomerScores($organization->id, $recalculation->id, 2))
        ->handle(app(CustomerScoreService::class));

    expect($recalculation->refresh()->status)->toBe('completed')
        ->and($recalculation->processed_companies)->toBe(52)
        ->and($recalculation->total_companies)->toBe(52);
    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $companyIds[50],
        'potential_points' => 10,
    ]);
    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $lateCompany->id,
        'potential_points' => 10,
    ]);
    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $otherOrganization->id,
        'company_id' => $otherCompany->id,
        'potential_points' => 20,
    ]);
});

it('supersedes an in-flight recalculation when newer scoring rules are saved', function () {
    Bus::fake();
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $settingsService = app(CommercialSettingsService::class);
    $settings = $settingsService->forOrganization($organization);
    $settings->update([
        'scoring_weights' => ['volume' => 40, 'frequency' => 20, 'potential' => 10, 'relationship' => 30],
    ]);
    $settings = $settingsService->forOrganization($organization);

    $companyIds = [];
    for ($index = 0; $index < 51; $index++) {
        $company = Company::factory()->create(['organization_id' => $organization->id]);
        $companyIds[] = $company->id;
        CustomerScore::query()->create([
            'company_id' => $company->id,
            'volume_points' => 0,
            'frequency_points' => 0,
            'potential_points' => 20,
            'relationship_points' => 20,
            'total_score' => 40,
            'tier' => 'C',
            'is_complete' => true,
            'last_calculated_at' => now(),
        ]);
    }

    foreach ([0, 50] as $saleIndex) {
        Sale::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $companyIds[$saleIndex],
            'amount' => '100.00',
            'sold_at' => now()->subDay(),
        ]);
    }

    $oldRecalculation = CommercialScoreRecalculation::query()->create([
        'requested_by' => $manager->id,
        'status' => 'queued',
        'total_companies' => 51,
        'processed_companies' => 0,
        'reason' => 'Regras antigas',
        'settings_snapshot' => [
            'scoring_weights' => $settings->scoring_weights,
            'volume_score_bands' => $settings->volume_score_bands,
            'frequency_score_bands' => $settings->frequency_score_bands,
            'tiers' => $settings->tiers,
        ],
    ]);
    $oldJob = new RecalculateOrganizationCustomerScores($organization->id, $oldRecalculation->id, 1);
    $scores = app(CustomerScoreService::class);
    $oldJob->handle($scores);
    expect($oldRecalculation->refresh()->processed_companies)->toBe(50);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch('/commercial-settings', [
            'sales_inactivity_days' => 90,
            'relationship_inactivity_days' => 30,
            'reactivation_repeat_cooldown_days' => 60,
            'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
            'scoring_weights' => ['volume' => 40, 'frequency' => 20, 'potential' => 15, 'relationship' => 25],
            'volume_score_bands' => [
                ['max_amount' => '0.00', 'points' => 0],
                ['max_amount' => '100.00', 'points' => 40],
                ['max_amount' => null, 'points' => 40],
            ],
            'frequency_score_bands' => $settings->frequency_score_bands,
            'tiers' => $settings->tiers,
        ])
        ->assertRedirect('/commercial-settings');

    $newRecalculation = CommercialScoreRecalculation::query()
        ->where('organization_id', $organization->id)
        ->where('id', '>', $oldRecalculation->id)
        ->firstOrFail();
    expect($newRecalculation->settings_snapshot['scoring_weights']['potential'])->toBe(15);

    $oldJob->handle($scores);
    expect($oldRecalculation->refresh()->status)->toBe('superseded');
    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $companyIds[50],
        'potential_points' => 20,
    ]);

    $newJob = new RecalculateOrganizationCustomerScores($organization->id, $newRecalculation->id, 1);
    $newJob->handle($scores);
    $newJob->handle($scores);

    expect($newRecalculation->refresh()->status)->toBe('completed');
    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $companyIds[0],
        'potential_points' => 10,
        'volume_points' => 40,
    ]);
    $this->assertDatabaseHas('customer_scores', [
        'organization_id' => $organization->id,
        'company_id' => $companyIds[50],
        'potential_points' => 15,
        'volume_points' => 40,
    ]);
});
