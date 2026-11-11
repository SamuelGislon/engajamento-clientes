<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'organization_name' => 'Test Workspace',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('organizations', ['name' => 'Test Workspace']);
        $organizationId = DB::table('organizations')->where('name', 'Test Workspace')->value('id');
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organizationId,
            'user_id' => auth()->id(),
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('journey_templates', [
            'organization_id' => $organizationId,
            'name' => 'Prospecção Comercial',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->assertSame(4, DB::table('journey_steps')->where('organization_id', $organizationId)->count());
    }
}
