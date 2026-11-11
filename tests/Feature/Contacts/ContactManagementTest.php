<?php

namespace Tests\Feature\Contacts;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContactManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_is_created_and_linked_with_its_company_specific_roles(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        app(TenantContext::class)->set($organization);
        $company = Company::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->post(route('contacts.store'), [
                'name' => 'Marina Souza',
                'email' => 'marina@example.test',
                'job_title' => 'Sócia-proprietária',
                'company_id' => $company->id,
                'roles' => ['owner', 'decision_maker'],
                'is_primary' => true,
            ]);

        $contact = Contact::query()->where('email', 'marina@example.test')->firstOrFail();
        $response->assertRedirect(route('companies.show', $company->id));
        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'organization_id' => $organization->id,
            'name' => 'Marina Souza',
        ]);
        $pivot = DB::table('company_contacts')->where('company_id', $company->id)->where('contact_id', $contact->id)->first();
        $this->assertNotNull($pivot);
        $this->assertSame(['owner', 'decision_maker'], json_decode($pivot->roles, true, flags: JSON_THROW_ON_ERROR));
        $this->assertTrue((bool) $pivot->is_primary);
    }

    public function test_existing_contact_can_be_linked_to_another_company_in_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $organization->users()->attach($manager, ['role' => 'manager']);
        app(TenantContext::class)->set($organization);
        $firstCompany = Company::factory()->create(['organization_id' => $organization->id]);
        $secondCompany = Company::factory()->create(['organization_id' => $organization->id]);
        $contact = Contact::factory()->create(['organization_id' => $organization->id]);
        $firstCompany->contacts()->attach($contact->id, [
            'organization_id' => $organization->id,
            'roles' => ['owner'],
        ]);

        $response = $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->post(route('companies.contacts.store', $secondCompany->id), [
                'contact_id' => $contact->id,
                'roles' => ['specifier', 'influencer'],
                'is_primary' => true,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('company_contacts', [
            'organization_id' => $organization->id,
            'company_id' => $firstCompany->id,
            'contact_id' => $contact->id,
        ]);
        $this->assertDatabaseHas('company_contacts', [
            'organization_id' => $organization->id,
            'company_id' => $secondCompany->id,
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);
    }
}
