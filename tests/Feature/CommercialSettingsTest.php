<?php

use App\Domain\Tenancy\TenantContext;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\User;
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
