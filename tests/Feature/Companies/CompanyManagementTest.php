<?php

namespace Tests\Feature\Companies;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesperson_creates_a_company_assigned_to_their_own_portfolio(): void
    {
        $organization = Organization::factory()->create();
        $seller = User::factory()->create();
        $organization->users()->attach($seller, ['role' => 'salesperson']);

        $response = $this->actingAs($seller)
            ->withSession(['active_organization_id' => $organization->id])
            ->post(route('companies.store'), [
                'name' => 'Escritório Horizonte',
                'relationship_status' => 'prospect',
                'segment' => 'Arquitetura',
            ]);

        $company = Company::query()->firstOrFail();
        $response->assertRedirect(route('companies.show', $company->id));
        $this->assertSame($organization->id, $company->organization_id);
        $this->assertSame($seller->id, $company->owner_user_id);
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Escritório Horizonte',
            'relationship_status' => 'prospect',
        ]);
    }

    public function test_salesperson_cannot_view_a_company_owned_by_another_salesperson(): void
    {
        $organization = Organization::factory()->create();
        $seller = User::factory()->create();
        $otherSeller = User::factory()->create();
        $organization->users()->attach($seller, ['role' => 'salesperson']);
        $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $otherSeller->id,
        ]);

        $response = $this->actingAs($seller)
            ->withSession(['active_organization_id' => $organization->id])
            ->get(route('companies.show', $company->id));

        $response->assertForbidden();
    }

    public function test_company_owner_must_belong_to_the_active_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $seller = User::factory()->create();
        $outsider = User::factory()->create();
        $organization->users()->attach($seller, ['role' => 'owner']);
        $otherOrganization->users()->attach($outsider, ['role' => 'salesperson']);

        $response = $this->actingAs($seller)
            ->withSession(['active_organization_id' => $organization->id])
            ->from(route('companies.create'))
            ->post(route('companies.store'), [
                'name' => 'Empresa de teste',
                'relationship_status' => 'prospect',
                'owner_user_id' => $outsider->id,
            ]);

        $response->assertSessionHasErrors('owner_user_id');
        $this->assertDatabaseMissing('companies', ['name' => 'Empresa de teste']);
    }
}
