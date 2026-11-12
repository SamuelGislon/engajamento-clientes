<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamCommercialScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_without_a_managed_team_cannot_list_the_organization_portfolio(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
            'name' => 'Carteira de outra equipe',
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/companies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('companies/index')
                ->where('companies.total', 0)
                ->has('companies.data', 0),
            );
    }

    public function test_a_previous_team_manager_cannot_edit_or_cancel_a_sale_after_company_transfer(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $previousTeam = $organization->teams()->create(['name' => 'Equipe anterior', 'created_by' => $manager->id]);
        $currentTeam = $organization->teams()->create(['name' => 'Equipe atual', 'created_by' => $manager->id]);
        $previousTeam->users()->attach($manager, [
            'organization_id' => $organization->id,
            'role' => 'manager',
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
            'team_id' => $previousTeam->id,
        ]);
        $sale = Sale::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'seller_id' => $seller->id,
            'created_by' => $seller->id,
            'team_id_at_sale' => $previousTeam->id,
        ]);
        $company->update(['team_id' => $currentTeam->id]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get("/sales/{$sale->id}/edit")
            ->assertForbidden();

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->patch("/sales/{$sale->id}", ['description' => 'Alteração indevida'])
            ->assertForbidden();

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->post("/sales/{$sale->id}/cancel")
            ->assertForbidden();

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'confirmed']);
    }

    public function test_a_previous_salesperson_cannot_edit_or_cancel_a_sale_after_company_transfer(): void
    {
        $organization = Organization::factory()->create();
        $previousSeller = User::factory()->create();
        $currentSeller = User::factory()->create();
        $organization->users()->attach($previousSeller, ['role' => 'salesperson']);
        $organization->users()->attach($currentSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $previousSeller->id,
        ]);
        $sale = Sale::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'seller_id' => $previousSeller->id,
            'created_by' => $previousSeller->id,
        ]);
        $company->update(['owner_user_id' => $currentSeller->id]);

        $this->actingAs($previousSeller)
            ->withSession(['active_organization_id' => $organization->id])
            ->get("/sales/{$sale->id}/edit")
            ->assertForbidden();

        $this->actingAs($previousSeller)
            ->withSession(['active_organization_id' => $organization->id])
            ->patch("/sales/{$sale->id}", ['description' => 'Edição após transferência'])
            ->assertForbidden();

        $this->actingAs($previousSeller)
            ->withSession(['active_organization_id' => $organization->id])
            ->post("/sales/{$sale->id}/cancel")
            ->assertForbidden();

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'status' => 'confirmed',
            'description' => $sale->description,
        ]);
    }

    public function test_a_manager_without_a_managed_team_cannot_open_an_unrelated_company_url(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get(route('companies.show', $company->id))
            ->assertForbidden();
    }

    public function test_a_manager_can_only_list_companies_assigned_to_a_team_they_manage(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $otherManager = User::factory()->create();
        $seller = User::factory()->create();
        $otherSeller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($otherManager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);

        $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $manager->id]);
        $otherTeam = $organization->teams()->create(['name' => 'Equipe Sul', 'created_by' => $otherManager->id]);
        $team->users()->attach($manager, [
            'organization_id' => $organization->id,
            'role' => 'manager',
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $otherTeam->users()->attach($otherManager, [
            'organization_id' => $organization->id,
            'role' => 'manager',
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $northCompany = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
            'team_id' => $team->id,
            'name' => 'Escritório Norte',
        ]);
        Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $otherSeller->id,
            'team_id' => $otherTeam->id,
            'name' => 'Escritório Sul',
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/companies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('companies/index')
                ->where('companies.total', 1)
                ->where('companies.data.0.id', $northCompany->id),
            );

        $this->get(route('teams.show', $otherTeam->id))->assertForbidden();
    }

    public function test_a_manager_without_a_managed_team_cannot_list_another_sellers_tasks(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
        ]);
        Task::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'assigned_to' => $seller->id,
            'created_by' => $seller->id,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/tasks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tasks/index')
                ->where('tasks.total', 0)
                ->has('tasks.data', 0),
            );
    }

    public function test_a_manager_cannot_open_another_sellers_task_url(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
        ]);
        $task = Task::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'assigned_to' => $seller->id,
            'created_by' => $seller->id,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get(route('tasks.show', $task->id))
            ->assertForbidden();
    }

    public function test_a_manager_without_a_team_cannot_list_another_sellers_contacts_or_sales(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
        ]);
        $contact = Contact::factory()->create(['organization_id' => $organization->id]);
        $company->contacts()->attach($contact, [
            'organization_id' => $organization->id,
            'roles' => ['buyer'],
            'is_primary' => true,
        ]);
        Sale::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'seller_id' => $seller->id,
            'created_by' => $seller->id,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->get('/contacts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('contacts/index')
                ->where('contacts.total', 0)
                ->has('contacts.data', 0),
            );

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/index')
                ->where('sales.total', 0)
                ->has('sales.data', 0),
            );
    }

    public function test_a_manager_cannot_create_a_task_for_a_company_outside_their_teams(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/tasks', [
                'company_id' => $company->id,
                'assigned_to' => $seller->id,
                'title' => 'Acompanhamento não autorizado',
                'type' => 'call',
                'priority' => 'normal',
                'due_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('company_id');

        $this->assertDatabaseMissing('tasks', [
            'organization_id' => $organization->id,
            'title' => 'Acompanhamento não autorizado',
        ]);
    }

    public function test_a_manager_cannot_record_a_sale_for_a_seller_outside_the_managed_team(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $otherSeller = User::factory()->create();
        foreach ([[$manager, 'manager'], [$seller, 'salesperson'], [$otherSeller, 'salesperson']] as [$member, $role]) {
            $organization->users()->attach($member, ['role' => $role]);
        }
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

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->post(route('companies.sales.store', $company->id), [
                'seller_id' => $otherSeller->id,
                'description' => 'Venda fora da equipe',
                'amount' => '1000.00',
                'currency' => 'BRL',
                'sold_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('seller_id');

        $this->assertDatabaseMissing('sales', [
            'organization_id' => $organization->id,
            'description' => 'Venda fora da equipe',
        ]);
    }

    public function test_a_manager_without_a_team_keeps_existing_settings_access_without_getting_portfolio_access(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $seller->id,
            'name' => 'Carteira sem acesso',
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->from('/journeys')
            ->patch('/journeys/settings', ['auto_enroll_default_journey' => false])
            ->assertRedirect('/journeys');

        $this->get('/commercial-settings')->assertOk();
        $this->get('/companies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('companies/index')
                ->where('companies.total', 0)
                ->has('companies.data', 0),
            );
    }
}
