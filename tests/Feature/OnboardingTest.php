<?php

use App\Domain\Organizations\OnboardingAccessService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{User, Organization} */
function createOnboardingOwner(string $step = 'organization'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create([
        'onboarding_step' => $step,
        'onboarding_completed_at' => null,
    ]);
    $organization->users()->attach($user, ['role' => 'owner', 'is_active' => true]);
    app(TenantContext::class)->set($organization);
    $organization->journeyTemplates()->create([
        'name' => 'Jornada padrão',
        'type' => 'prospecting',
        'trigger_type' => 'company_created',
        'is_active' => true,
        'is_default' => true,
    ]);

    return [$user, $organization];
}

it('redirects only a new organization owner from the dashboard into resumable onboarding', function () {
    [$owner, $organization] = createOnboardingOwner();

    $this->actingAs($owner)
        ->withSession(['active_organization_id' => $organization->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.show'));

    $this->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->where('step', 'organization')
            ->where('organization.id', $organization->id));
});

it('saves organization details and validates timezone before advancing', function () {
    [$owner, $organization] = createOnboardingOwner();

    $this->actingAs($owner)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch(route('onboarding.organization.update'), [
            'name' => 'Nome inválido',
            'timezone' => 'Not/A-Timezone',
        ])->assertSessionHasErrors('timezone');

    expect($organization->fresh()->onboarding_step)->toBe('organization');

    $this->patch(route('onboarding.organization.update'), [
        'name' => 'Workspace atualizado',
        'timezone' => 'America/New_York',
    ])
        ->assertRedirect(route('onboarding.show'));

    expect($organization->fresh()->name)->toBe('Workspace atualizado')
        ->and($organization->fresh()->timezone)->toBe('America/New_York')
        ->and($organization->fresh()->onboarding_step)->toBe('relationship');

    expect($organization->fresh()->name)->toBe('Workspace atualizado');
});

it('saves initial commercial relationship settings for the active organization', function () {
    [$owner, $organization] = createOnboardingOwner('relationship');

    $this->actingAs($owner)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch(route('onboarding.relationship.update'), [
            'sales_inactivity_days' => 120,
            'relationship_inactivity_days' => 45,
        ])
        ->assertRedirect(route('onboarding.show'));

    $this->assertDatabaseHas('commercial_settings', [
        'organization_id' => $organization->id,
        'sales_inactivity_days' => 120,
        'relationship_inactivity_days' => 45,
    ]);
    expect($organization->fresh()->onboarding_step)->toBe('journeys');
});

it('selects an active journey only from the current organization', function () {
    [$owner, $organization] = createOnboardingOwner('journeys');
    $template = $organization->journeyTemplates()->create([
        'name' => 'Jornada prioritária',
        'type' => 'prospecting',
        'trigger_type' => 'manual',
        'is_active' => true,
        'is_default' => false,
    ]);
    $otherOrganization = Organization::factory()->create();
    app(TenantContext::class)->set($otherOrganization);
    $foreignTemplate = $otherOrganization->journeyTemplates()->create([
        'name' => 'Jornada externa',
        'type' => 'prospecting',
        'trigger_type' => 'manual',
        'is_active' => true,
        'is_default' => false,
    ]);
    app(TenantContext::class)->set($organization);

    $this->actingAs($owner)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch(route('onboarding.journeys.update'), [
            'default_journey_template_id' => $foreignTemplate->id,
            'auto_enroll_default_journey' => false,
        ])->assertSessionHasErrors('default_journey_template_id');

    expect($organization->fresh()->onboarding_step)->toBe('journeys');

    $this->patch(route('onboarding.journeys.update'), [
        'default_journey_template_id' => $template->id,
        'auto_enroll_default_journey' => true,
    ])->assertRedirect(route('onboarding.show'));

    expect($template->fresh()->is_default)->toBeTrue()
        ->and($organization->fresh()->auto_enroll_default_journey)->toBeTrue()
        ->and($organization->fresh()->onboarding_step)->toBe('invite');

    expect($organization->fresh()->onboarding_step)->toBe('invite')
        ->and($organization->fresh()->auto_enroll_default_journey)->toBeTrue();
});

it('lets the owner skip optional invitations and portfolio setup and completes onboarding', function () {
    [$owner, $organization] = createOnboardingOwner('invite');

    $this->actingAs($owner)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('onboarding.continue'))
        ->assertRedirect(route('onboarding.show'));

    expect($organization->fresh()->onboarding_step)->toBe('portfolio');

    $this->post(route('onboarding.complete'))
        ->assertRedirect(route('dashboard'));

    expect($organization->fresh()->onboarding_step)->toBeNull()
        ->and($organization->fresh()->onboarding_completed_at)->not->toBeNull();

    $this->get(route('dashboard'))->assertOk();
});

it('does not force existing organizations or non-owner members into onboarding', function () {
    $existingOrganization = Organization::factory()->create();
    $existingOwner = User::factory()->create();
    $existingOrganization->users()->attach($existingOwner, ['role' => 'owner', 'is_active' => true]);
    $newOrganization = Organization::factory()->create(['onboarding_step' => 'organization']);
    $salesperson = User::factory()->create();
    $newOrganization->users()->attach($salesperson, ['role' => 'salesperson', 'is_active' => true]);

    $this->actingAs($existingOwner)
        ->withSession(['active_organization_id' => $existingOrganization->id])
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($salesperson)
        ->withSession(['active_organization_id' => $newOrganization->id])
        ->get(route('onboarding.show'))
        ->assertForbidden();
});

it('rechecks active owner membership under the organization lock before changing onboarding state', function () {
    [$owner, $organization] = createOnboardingOwner();
    $originalName = $organization->name;

    expect($owner->roleIn($organization->id))->toBe('owner');

    DB::table('organization_user')
        ->where('organization_id', $organization->id)
        ->where('user_id', $owner->id)
        ->update(['is_active' => false]);

    expect(fn () => DB::transaction(fn () => app(OnboardingAccessService::class)
        ->lockForOwner($organization, $owner, 'organization')))
        ->toThrow(AuthorizationException::class);

    expect($organization->fresh()->name)->toBe($originalName)
        ->and($organization->fresh()->onboarding_step)->toBe('organization');
});

it('rejects an onboarding mutation if PostgreSQL commits owner revocation after initial authorization', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Onboarding membership revocation is verified against PostgreSQL.');
    }

    [$owner, $organization] = createOnboardingOwner();
    $originalName = $organization->name;
    DB::connection()->commit();

    $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organizationId = (int) $argv[1];
$ownerId = (int) $argv[2];
$barrierPrefix = $argv[count($argv) - 2];
$workerId = $argv[count($argv) - 1];
$organization = \App\Models\Organization::query()->findOrFail($organizationId);
$owner = \App\Models\User::query()->findOrFail($ownerId);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);

if ($workerId === '0') {
    if ($owner->roleIn($organizationId) !== 'owner') {
        throw new RuntimeException('Owner authorization was not present before revocation.');
    }

    file_put_contents($barrierPrefix.'.authorized', 'yes');
    $deadline = microtime(true) + 15;

    while (! file_exists($barrierPrefix.'.revoked')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Membership revocation did not finish.');
        }

        usleep(10_000);
    }

    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($organization, $owner): void {
            $lockedOrganization = app(\App\Domain\Organizations\OnboardingAccessService::class)
                ->lockForOwner($organization, $owner, 'organization');
            $lockedOrganization->update(['name' => 'Alteração sem autorização']);
        });
        echo 'mutated';
    } catch (\Illuminate\Auth\Access\AuthorizationException) {
        echo 'rejected';
    }

    return;
}

$deadline = microtime(true) + 15;

while (! file_exists($barrierPrefix.'.authorized')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Request authorization did not finish.');
    }

    usleep(10_000);
}

\Illuminate\Support\Facades\DB::transaction(function () use ($organizationId, $ownerId): void {
    \App\Models\Organization::query()->lockForUpdate()->findOrFail($organizationId);
    \Illuminate\Support\Facades\DB::table('organization_user')
        ->where('organization_id', $organizationId)
        ->where('user_id', $ownerId)
        ->update(['is_active' => false]);
});

file_put_contents($barrierPrefix.'.revoked', 'yes');
echo 'revoked';
PHP;

    try {
        $result = runConcurrentPhpWorkers($script, [
            (string) $organization->id,
            (string) $owner->id,
        ]);

        $this->assertSame([0, 0], $result['codes'], implode(' | ', $result['errors']));
        $this->assertContains('rejected', $result['outputs']);
        $this->assertContains('revoked', $result['outputs']);
        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => $originalName,
            'onboarding_step' => 'organization',
        ]);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'is_active' => false,
        ]);
    } finally {
        DB::table('organizations')->where('id', $organization->id)->delete();
        DB::table('users')->where('id', $owner->id)->delete();
        DB::connection()->beginTransaction();
    }
});
