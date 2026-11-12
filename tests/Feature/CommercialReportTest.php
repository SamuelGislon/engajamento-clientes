<?php

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('manager report uses visible teams and excludes future and cancelled tasks from completion rate', function (): void {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $manager->id]);
    $otherTeam = $organization->teams()->create(['name' => 'Equipe Sul', 'created_by' => $manager->id]);
    $team->users()->attach($manager, [
        'organization_id' => $organization->id,
        'role' => 'manager',
        'is_active' => true,
        'joined_at' => now(),
    ]);
    $team->users()->attach($seller, [
        'organization_id' => $organization->id,
        'role' => 'salesperson',
        'is_active' => true,
        'joined_at' => now(),
    ]);
    $otherTeam->users()->attach($otherSeller, [
        'organization_id' => $organization->id,
        'role' => 'salesperson',
        'is_active' => true,
        'joined_at' => now(),
    ]);
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'owner_user_id' => $seller->id,
        'team_id' => $team->id,
    ]);
    $otherCompany = Company::factory()->create([
        'organization_id' => $organization->id,
        'owner_user_id' => $otherSeller->id,
        'team_id' => $otherTeam->id,
    ]);

    Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'status' => 'completed',
        'due_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),
    ]);
    Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'status' => 'pending',
        'due_at' => now()->subDay(),
    ]);
    Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'status' => 'pending',
        'due_at' => now()->endOfDay(),
    ]);
    Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'status' => 'cancelled',
        'due_at' => now()->subDay(),
    ]);
    Sale::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'seller_id' => $seller->id,
        'created_by' => $seller->id,
        'team_id_at_sale' => $team->id,
        'amount' => '100.00',
        'sold_at' => now()->subDay(),
    ]);
    Sale::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $otherCompany->id,
        'seller_id' => $otherSeller->id,
        'created_by' => $otherSeller->id,
        'team_id_at_sale' => $otherTeam->id,
        'amount' => '900.00',
        'sold_at' => now()->subDay(),
    ]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/reports?from='.now()->startOfMonth()->format('Y-m-d').'&to='.now()->format('Y-m-d'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('summary.tasks.eligible', 2)
            ->where('summary.tasks.completed_on_time', 0)
            ->where('summary.tasks.completed_late', 1)
            ->where('summary.tasks.overdue', 1)
            ->where('summary.tasks.pending', 1)
            ->where('summary.tasks.cancelled', 1)
            ->where('summary.tasks.completion_rate', 50)
            ->where('summary.sales.count', 1)
            ->where('summary.sales.revenue', '100.00')
            ->has('salesByTeam', 1)
            ->where('salesByTeam.0.team_id', $team->id)
            ->where('salesByTeam.0.revenue', '100.00')
            ->has('sellerPerformance.data', 1)
            ->where('sellerPerformance.data.0.id', $seller->id));
});

test('manager cannot use a team filter for a team they do not manage', function (): void {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($owner, ['role' => 'owner']);
    app(TenantContext::class)->set($organization);
    $otherTeam = $organization->teams()->create(['name' => 'Equipe Restrita', 'created_by' => $owner->id]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/reports?team_id='.$otherTeam->id)
        ->assertSessionHasErrors('team_id');
});

test('manager dashboard performance contains sellers from managed teams only', function (): void {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $managedTeam = $organization->teams()->create(['name' => 'Equipe gerenciada', 'created_by' => $manager->id]);
    $otherTeam = $organization->teams()->create(['name' => 'Equipe restrita', 'created_by' => $manager->id]);
    $managedTeam->users()->attach($manager, [
        'organization_id' => $organization->id,
        'role' => 'manager',
        'is_active' => true,
        'joined_at' => now(),
    ]);
    $managedTeam->users()->attach($seller, [
        'organization_id' => $organization->id,
        'role' => 'salesperson',
        'is_active' => true,
        'joined_at' => now(),
    ]);
    $otherTeam->users()->attach($otherSeller, [
        'organization_id' => $organization->id,
        'role' => 'salesperson',
        'is_active' => true,
        'joined_at' => now(),
    ]);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('managerReport.sellerPerformance.data', 1)
            ->where('managerReport.sellerPerformance.data.0.id', $seller->id));
});

test('salesperson cannot expand report to another seller by changing the filter', function (): void {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->get('/reports?seller_id='.$otherSeller->id)
        ->assertSessionHasErrors('seller_id');
});
