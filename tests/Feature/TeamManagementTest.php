<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_create_a_team_in_the_active_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $response = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/teams', [
                'name' => 'Equipe Norte',
                'description' => 'Escritórios de arquitetura da região norte.',
            ]);

        $response->assertRedirect('/teams');
        $this->assertDatabaseHas('teams', [
            'organization_id' => $organization->id,
            'name' => 'Equipe Norte',
            'status' => 'active',
        ]);
    }

    public function test_a_manager_only_sees_teams_they_manage(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        app(TenantContext::class)->set($organization);

        $managedTeam = $organization->teams()->create([
            'name' => 'Equipe Gerenciada',
            'created_by' => $manager->id,
        ]);
        $otherTeam = $organization->teams()->create([
            'name' => 'Outra Equipe',
            'created_by' => $manager->id,
        ]);
        $managedTeam->users()->attach($manager, [
            'organization_id' => $organization->id,
            'role' => 'manager',
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $formerSeller = User::factory()->create(['email' => 'former.seller@example.test']);
        $organization->users()->attach($formerSeller, ['role' => 'salesperson']);
        $managedTeam->users()->attach($formerSeller, [
            'organization_id' => $organization->id,
            'role' => 'salesperson',
            'is_active' => false,
            'joined_at' => now()->subMonth(),
        ]);
        $this->assertTrue($managedTeam->isManagedBy($manager));
        $this->assertSame('manager', $manager->roleIn($organization->id));

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/teams')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('teams/index')
                ->has('teams', 1)
                ->has('teams.0.users', 1)
                ->where('teams.0.id', $managedTeam->id)
                ->missing('teams.1.id'),
            );

        $this->assertNotSame($managedTeam->id, $otherTeam->id);
    }

    public function test_an_owner_can_assign_organization_managers_and_salespeople_to_a_team(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create([
            'name' => 'Equipe Norte',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->put("/teams/{$team->id}/members", [
                'user_ids' => [$manager->id, $seller->id],
            ]);

        $response->assertRedirect("/teams/{$team->id}");
        $this->assertDatabaseHas('team_memberships', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('team_memberships', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $seller->id,
            'role' => 'salesperson',
            'is_active' => true,
        ]);
    }

    public function test_an_owner_can_transfer_a_company_to_another_seller_and_team_with_history(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $oldSeller = User::factory()->create();
        $newSeller = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($oldSeller, ['role' => 'salesperson']);
        $organization->users()->attach($newSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $oldTeam = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $owner->id]);
        $newTeam = $organization->teams()->create(['name' => 'Equipe Sul', 'created_by' => $owner->id]);
        foreach ([[$oldTeam, $oldSeller], [$newTeam, $newSeller]] as [$team, $seller]) {
            $team->users()->attach($seller, [
                'organization_id' => $organization->id,
                'role' => 'salesperson',
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $oldSeller->id,
            'team_id' => $oldTeam->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->patch(route('companies.owner.update', $company->id), [
                'owner_user_id' => $newSeller->id,
                'team_id' => $newTeam->id,
                'reason' => 'Redistribuição mensal de carteira.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'owner_user_id' => $newSeller->id,
            'team_id' => $newTeam->id,
        ]);
        $this->assertDatabaseHas('company_assignment_histories', [
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'previous_owner_id' => $oldSeller->id,
            'new_owner_id' => $newSeller->id,
            'previous_team_id' => $oldTeam->id,
            'new_team_id' => $newTeam->id,
            'actor_id' => $owner->id,
            'reason' => 'Redistribuição mensal de carteira.',
        ]);
    }

    public function test_an_owner_can_deactivate_a_team_without_deleting_it(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create([
            'name' => 'Equipe Norte',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->patch("/teams/{$team->id}", ['status' => 'inactive'])
            ->assertRedirect(route('teams.show', $team->id));

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'organization_id' => $organization->id,
            'status' => 'inactive',
        ]);
    }

    public function test_an_owner_must_transfer_a_teams_companies_before_deactivating_it(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $owner->id]);
        Company::factory()->create(['organization_id' => $organization->id, 'team_id' => $team->id]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->patch("/teams/{$team->id}", ['status' => 'inactive'])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'status' => 'active']);
    }

    public function test_the_last_active_owner_cannot_be_demoted(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->patch(route('organization.users.update', $owner->id), [
                'role' => 'salesperson',
                'is_active' => true,
                'team_ids' => [],
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    public function test_an_admin_cannot_promote_a_member_to_owner(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($admin, ['role' => 'admin']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $organization->id])
            ->patch(route('organization.users.update', $seller->id), [
                'role' => 'owner',
                'is_active' => true,
                'team_ids' => [],
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $seller->id,
            'role' => 'salesperson',
            'is_active' => true,
        ]);
    }

    public function test_an_admin_can_transfer_multiple_companies_as_one_batch(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($admin, ['role' => 'admin']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $admin->id]);
        $team->users()->attach($seller, [
            'organization_id' => $organization->id,
            'role' => 'salesperson',
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $companies = Company::factory()->count(2)->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $organization->id])
            ->post(route('companies.portfolio.transfer'), [
                'company_ids' => $companies->modelKeys(),
                'owner_user_id' => $seller->id,
                'team_id' => $team->id,
                'reason' => 'Distribuição inicial da carteira.',
            ])
            ->assertRedirect();

        foreach ($companies as $company) {
            $this->assertDatabaseHas('companies', [
                'id' => $company->id,
                'owner_user_id' => $seller->id,
                'team_id' => $team->id,
            ]);
        }
        $this->assertDatabaseCount('company_assignment_histories', 2);
    }

    public function test_batch_transfer_rejects_foreign_tenant_companies_before_updating_any_company(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->create();
        $seller = User::factory()->create();
        $foreignSeller = User::factory()->create();
        $organization->users()->attach($admin, ['role' => 'admin']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        $otherOrganization->users()->attach($foreignSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
        ]);
        app(TenantContext::class)->set($otherOrganization);
        $foreignCompany = Company::factory()->create([
            'organization_id' => $otherOrganization->id,
            'owner_user_id' => $foreignSeller->id,
        ]);

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $organization->id])
            ->post(route('companies.portfolio.transfer'), [
                'company_ids' => [$company->id, $foreignCompany->id],
                'owner_user_id' => null,
                'team_id' => null,
            ])
            ->assertSessionHasErrors('company_ids.1');

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'owner_user_id' => $seller->id]);
        $this->assertDatabaseMissing('company_assignment_histories', ['company_id' => $company->id]);
    }

    public function test_team_membership_cannot_be_removed_while_the_seller_owns_companies_in_that_team(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $owner->id]);
        $team->users()->attach($seller, [
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

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->put("/teams/{$team->id}/members", ['user_ids' => []])
            ->assertSessionHasErrors('user_ids');

        $this->assertDatabaseHas('team_memberships', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $seller->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'owner_user_id' => $seller->id]);
    }

    public function test_removing_a_seller_from_a_team_deactivates_membership_without_deleting_its_history(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $owner->id]);
        $team->users()->attach($seller, [
            'organization_id' => $organization->id,
            'role' => 'salesperson',
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->put("/teams/{$team->id}/members", ['user_ids' => []])
            ->assertRedirect();

        $this->assertDatabaseHas('team_memberships', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $seller->id,
            'is_active' => false,
        ]);
    }
}
