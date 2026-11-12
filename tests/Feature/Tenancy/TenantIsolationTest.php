<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_read_or_link_records_from_another_organization(): void
    {
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $firstOrganization->users()->attach($firstOwner, ['role' => 'owner']);
        $secondOrganization->users()->attach($secondOwner, ['role' => 'owner']);

        app(TenantContext::class)->set($secondOrganization);
        $foreignCompany = Company::factory()->create([
            'organization_id' => $secondOrganization->id,
            'owner_user_id' => $secondOwner->id,
        ]);

        $this->actingAs($firstOwner)
            ->withSession(['active_organization_id' => $firstOrganization->id])
            ->get(route('companies.show', $foreignCompany->id))
            ->assertNotFound();

        $response = $this->from(route('contacts.create'))
            ->post(route('contacts.store'), [
                'name' => 'Contato externo',
                'company_id' => $foreignCompany->id,
                'roles' => ['buyer'],
            ]);

        $response->assertSessionHasErrors('company_id');
        $this->assertDatabaseMissing('contacts', ['name' => 'Contato externo']);
    }

    public function test_switching_organization_changes_the_visible_dashboard_scope(): void
    {
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $user = User::factory()->create();
        $firstOrganization->users()->attach($user, ['role' => 'owner']);
        $secondOrganization->users()->attach($user, ['role' => 'manager']);

        app(TenantContext::class)->set($firstOrganization);
        Company::factory()->create(['organization_id' => $firstOrganization->id]);
        app(TenantContext::class)->set($secondOrganization);
        $team = createManagedTeam($secondOrganization, $user);
        Company::factory()->create(['organization_id' => $secondOrganization->id, 'team_id' => $team->id]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $firstOrganization->id])
            ->post(route('organizations.switch'), ['organization_id' => $secondOrganization->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('active_organization_id', $secondOrganization->id);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('metrics.companies', 1),
            );
    }

    public function test_a_user_cannot_switch_to_an_organization_they_do_not_belong_to(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->from(route('dashboard'))
            ->post(route('organizations.switch'), ['organization_id' => $otherOrganization->id])
            ->assertSessionHasErrors('organization_id')
            ->assertSessionHas('active_organization_id', $organization->id);
    }
}
