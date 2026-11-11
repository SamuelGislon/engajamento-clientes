<?php

use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows scoped task and journey metrics on the dashboard', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);
    $otherCompany = Company::factory()->ownedBy($otherSeller)->create(['organization_id' => $organization->id]);
    Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'due_at' => now()->addHours(2),
    ]);
    Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $otherCompany->id,
        'assigned_to' => $otherSeller->id,
        'created_by' => $otherSeller->id,
        'due_at' => now()->subHour(),
    ]);
    $template = app(JourneyService::class)->ensureDefaultTemplate($organization);
    app(JourneyService::class)->enroll($company, $template, $seller);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->withoutVite()
        ->get(route('dashboard'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('metrics.tasks_due_today', 1)
            ->where('metrics.overdue_tasks', 0)
            ->where('metrics.active_journeys', 1),
        );
});
