<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Mail\OrganizationInvitationMail;
use App\Models\Company;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_email_renders_using_laravels_markdown_mail_components(): void
    {
        $mail = new OrganizationInvitationMail(
            organizationName: 'Organização Demonstração',
            roleLabel: 'Gestor',
            teamName: 'Equipe Norte',
            invitationUrl: 'http://localhost/invitations/'.str_repeat('a', 64),
        );

        $html = $mail->render();

        $this->assertStringContainsString('Você recebeu um convite', $html);
        $this->assertStringContainsString('Equipe Norte', $html);
    }

    public function test_an_owner_can_invite_a_salesperson_with_a_hashed_single_use_token(): void
    {
        Mail::fake();
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/organization/invitations', [
                'email' => '  New.Seller@Example.com ',
                'role' => 'salesperson',
            ])
            ->assertRedirect('/organization/users');

        $invitation = OrganizationInvitation::query()->firstOrFail();
        $this->assertSame('new.seller@example.com', $invitation->email);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $invitation->token_hash);
        $this->assertNull($invitation->accepted_at);
        $this->assertNull($invitation->revoked_at);
        Mail::assertQueued(OrganizationInvitationMail::class, fn (OrganizationInvitationMail $mail): bool => hash('sha256', parse_url($mail->invitationUrl, PHP_URL_PATH) ? basename(parse_url($mail->invitationUrl, PHP_URL_PATH)) : '') === $invitation->token_hash,
        );
    }

    public function test_a_verified_invitee_can_accept_once_and_become_an_active_organization_member(): void
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        $user = User::factory()->create([
            'email' => 'invited@example.com',
            'email_verified_at' => now(),
        ]);
        app(TenantContext::class)->set($organization);
        $token = bin2hex(random_bytes(32));
        $invitation = OrganizationInvitation::query()->create([
            'email' => $user->email,
            'role' => 'salesperson',
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $creator->id,
        ]);

        $this->actingAs($user)
            ->post(route('invitations.accept', $token))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'salesperson',
            'is_active' => true,
        ]);
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertNotSame(hash('sha256', $token), $invitation->fresh()->token_hash);

        $this->actingAs($user)->post(route('invitations.accept', $token))->assertNotFound();
    }

    public function test_invitation_cannot_be_accepted_by_a_different_email_address(): void
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        $user = User::factory()->create([
            'email' => 'wrong@example.com',
            'email_verified_at' => now(),
        ]);
        app(TenantContext::class)->set($organization);
        $token = bin2hex(random_bytes(32));
        OrganizationInvitation::query()->create([
            'email' => 'invited@example.com',
            'role' => 'salesperson',
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $creator->id,
        ]);

        $this->actingAs($user)
            ->post(route('invitations.accept', $token))
            ->assertForbidden();

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_invitation_landing_page_does_not_expose_the_token_hash(): void
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $token = bin2hex(random_bytes(32));
        $invitation = OrganizationInvitation::query()->create([
            'email' => 'invited@example.com',
            'role' => 'salesperson',
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $creator->id,
        ]);

        $this->get(route('invitations.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invitations/show')
                ->where('invitation.email', 'invited@example.com')
                ->where('invitation.status', 'pending')
                ->missing('invitation.token_hash'));
    }

    public function test_a_new_invitee_can_register_without_creating_an_unrequested_workspace(): void
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $organization->users()->attach($creator, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $token = bin2hex(random_bytes(32));
        OrganizationInvitation::query()->create([
            'email' => 'new.person@example.com',
            'role' => 'salesperson',
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $creator->id,
        ]);

        $this->withSession(['pending_invitation_token' => $token])
            ->post('/register', [
                'name' => 'New Person',
                'email' => 'new.person@example.com',
                'password' => 'Password123!Secure',
                'password_confirmation' => 'Password123!Secure',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertCount(0, $user->organizations()->get());
        $this->assertNull($user->email_verified_at);
    }

    public function test_a_seller_cannot_be_removed_from_a_team_while_they_still_own_its_companies(): void
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
            ->patch("/organization/users/{$seller->id}", [
                'role' => 'salesperson',
                'is_active' => true,
                'team_ids' => [],
            ])
            ->assertSessionHasErrors('team_ids');

        $this->assertDatabaseHas('team_memberships', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $seller->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'team_id' => $team->id]);
    }

    public function test_a_team_with_a_pending_manager_invitation_cannot_be_deactivated(): void
    {
        Mail::fake();
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $invitee = User::factory()->create([
            'email' => 'manager@example.com',
            'email_verified_at' => now(),
        ]);
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/organization/invitations', [
                'email' => $invitee->email,
                'role' => 'manager',
                'team_id' => $team->id,
            ])
            ->assertRedirect('/organization/users');

        $invitation = OrganizationInvitation::query()->firstOrFail();

        $this->patch("/teams/{$team->id}", ['status' => 'inactive'])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id, 'status' => 'pending']);

        $this->from('/organization/users')
            ->post(route('organization.invitations.resend', $invitation))
            ->assertRedirect('/organization/users');

        $this->get('/organization/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/users/index')
                ->where('invitations.data.0.status', 'pending'),
            );

        $resentInvitationMail = Mail::queued(OrganizationInvitationMail::class)
            ->last(fn (OrganizationInvitationMail $mail): bool => $mail->teamName === $team->name);
        $token = basename((string) parse_url($resentInvitationMail->invitationUrl, PHP_URL_PATH));

        $this->actingAs($invitee)
            ->post(route('invitations.accept', $token))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('organization_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('team_memberships', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
    }

    public function test_an_expired_invitation_must_be_revoked_before_its_team_can_be_deactivated(): void
    {
        Mail::fake();
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create(['name' => 'Equipe Norte', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->post('/organization/invitations', [
                'email' => 'manager@example.com',
                'role' => 'manager',
                'team_id' => $team->id,
            ])
            ->assertRedirect('/organization/users');

        $invitation = OrganizationInvitation::query()->firstOrFail();
        $this->travel(8)->days();

        $this->get('/organization/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/users/index')
                ->where('invitations.data.0.status', 'expired'),
            );

        $this->patch("/teams/{$team->id}", ['status' => 'inactive'])
            ->assertSessionHasErrors('status');

        $this->delete(route('organization.invitations.revoke', $invitation))
            ->assertRedirect();
        $this->assertDatabaseHas('organization_invitations', [
            'id' => $invitation->id,
            'status' => 'revoked',
        ]);

        $this->patch("/teams/{$team->id}", ['status' => 'inactive'])
            ->assertRedirect(route('teams.show', $team->id));
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'status' => 'inactive']);
    }

    public function test_only_one_concurrent_process_can_accept_the_same_invitation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent invitation acceptance is verified against PostgreSQL.');
        }

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $invitee = User::factory()->create([
            'email' => 'manager-concurrency-'.fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
        ]);
        $organization->users()->attach($owner, ['role' => 'owner']);
        app(TenantContext::class)->set($organization);
        $team = $organization->teams()->create(['name' => 'Equipe Concorrente', 'created_by' => $owner->id]);
        $token = bin2hex(random_bytes(32));
        $invitation = OrganizationInvitation::query()->create([
            'email' => $invitee->email,
            'role' => 'manager',
            'team_id' => $team->id,
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $owner->id,
        ]);

        DB::connection()->commit();

        try {
            $childScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$user = \App\Models\User::query()->findOrFail((int) $argv[2]);
try {
    app(\App\Domain\Teams\OrganizationInvitationService::class)->accept($argv[3], $user);
    echo 'accepted';
} catch (\Throwable $exception) {
    echo 'rejected:'.get_class($exception);
}
PHP;
            $result = runConcurrentPhpWorkers($childScript, [
                (string) $organization->id,
                (string) $invitee->id,
                $token,
            ]);

            $this->assertSame([0, 0], $result['codes'], implode(' | ', $result['errors']));

            $outcomes = $result['outputs'];
            $acceptedCount = count(array_filter($outcomes, fn (string $outcome): bool => $outcome === 'accepted'));
            $rejectedCount = count(array_filter($outcomes, fn (string $outcome): bool => str_starts_with($outcome, 'rejected:')));

            $this->assertSame(1, $acceptedCount, implode(' | ', $outcomes));
            $this->assertSame(1, $rejectedCount, implode(' | ', $outcomes));
            $this->assertSame('accepted', $invitation->fresh()->status);
            $this->assertDatabaseCount('organization_user', 2);
            $this->assertDatabaseCount('team_memberships', 1);
        } finally {
            DB::table('organization_invitations')->where('id', $invitation->id)->delete();
            DB::table('team_memberships')->where('organization_id', $organization->id)->delete();
            DB::table('teams')->where('organization_id', $organization->id)->delete();
            DB::table('organization_user')->where('organization_id', $organization->id)->delete();
            DB::table('organizations')->where('id', $organization->id)->delete();
            DB::table('users')->whereIn('id', [$owner->id, $invitee->id])->delete();
            DB::connection()->beginTransaction();
        }
    }
}
