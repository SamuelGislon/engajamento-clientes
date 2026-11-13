<?php

use App\Domain\Imports\CompanyImportService;
use App\Domain\Imports\ContactImportService;
use App\Domain\Imports\DataImportProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('serializes a concurrent team deactivation with PostgreSQL import confirmation checks', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Import destination locking is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $team = Team::query()->create([
        'name' => 'Equipe Importação Concorrente',
        'status' => 'active',
        'created_by' => $manager->id,
    ]);
    DB::table('team_memberships')->insert([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'user_id' => $manager->id,
        'role' => 'manager',
        'is_active' => true,
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $manager->id,
        'team_id' => $team->id,
        'mapping' => ['name' => 0],
        'status' => 'validated',
    ]);
    DB::connection()->commit();

    $startedMarker = tempnam(sys_get_temp_dir(), 'import-team-lock-');
    expect($startedMarker)->not->toBeFalse();
    @unlink($startedMarker);
    $worker = null;

    try {
        DB::beginTransaction();
        Organization::query()->lockForUpdate()->findOrFail($organization->id);
        $analysis = app(CompanyImportService::class)->analyze($batch, $manager, [['Nova empresa']], 1, true);
        expect($analysis['validRows'])->toHaveCount(1);

        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$marker = $argv[2];
file_put_contents($marker.'.started', 'started');
\Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
    $team = \App\Models\Team::query()->lockForUpdate()->findOrFail((int) $argv[3]);
    $team->update(['status' => 'inactive']);
});
file_put_contents($marker.'.done', 'done');
PHP;
        $worker = new Process([
            PHP_BINARY,
            '-r',
            $script,
            (string) $organization->id,
            $startedMarker,
            (string) $team->id,
        ], base_path(), timeout: 30);
        $worker->start();

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && ! file_exists($startedMarker.'.started')) {
            usleep(25_000);
        }

        expect(file_exists($startedMarker.'.started'))->toBeTrue('The competing team update did not start.');
        usleep(300_000);
        expect($worker->isRunning())->toBeTrue('The team update should wait for the import destination lock.');
        expect(file_exists($startedMarker.'.done'))->toBeFalse();

        DB::connection()->commit();
        $worker->wait();

        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput())
            ->and(file_exists($startedMarker.'.done'))->toBeTrue()
            ->and($team->fresh()->status)->toBe('inactive');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($worker?->isRunning()) {
            $worker->stop();
        }

        @unlink($startedMarker);
        @unlink($startedMarker.'.started');
        @unlink($startedMarker.'.done');
        DB::table('organizations')->where('id', $organization->id)->delete();
        DB::table('users')->where('id', $manager->id)->delete();
        DB::connection()->beginTransaction();
    }
});

it('serializes contact import with manager membership revocation from a referenced company team', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Cross-team authorization locking is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $destinationTeam = Team::query()->create([
        'name' => 'Equipe destino do lote',
        'status' => 'active',
        'created_by' => $manager->id,
    ]);
    $companyTeam = Team::query()->create([
        'name' => 'Equipe da empresa vinculada',
        'status' => 'active',
        'created_by' => $manager->id,
    ]);
    assignTeamMember($organization, $destinationTeam, $manager, 'manager');
    assignTeamMember($organization, $companyTeam, $manager, 'manager');
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Empresa da segunda equipe',
        'team_id' => $companyTeam->id,
    ]);
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $manager->id,
        'team_id' => $destinationTeam->id,
        'record_type' => 'contacts',
        'status' => 'validated',
        'mapping' => ['name' => 0, 'email' => 1, 'phone' => 2, 'company_name' => 3, 'company_roles' => 4],
    ]);
    DB::connection()->commit();

    $startedMarker = tempnam(sys_get_temp_dir(), 'contact-team-lock-');
    expect($startedMarker)->not->toBeFalse();
    @unlink($startedMarker);
    $worker = null;

    try {
        DB::beginTransaction();
        Organization::query()->lockForUpdate()->findOrFail($organization->id);
        $analysis = app(ContactImportService::class)->analyze(
            $batch,
            $manager,
            [['Contato de teste', 'contato-equipe@example.test', '+5511999991111', $company->name, 'buyer']],
            1,
            true,
        );
        expect($analysis['validRows'])->toHaveCount(1);
        $result = app(ContactImportService::class)->import($batch, $manager, $analysis['validRows']);
        expect($result['createdRows'])->toBe(1);

        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$marker = $argv[2];
file_put_contents($marker.'.started', 'started');
\Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
    \App\Models\Team::query()->where('organization_id', (int) $argv[1])->lockForUpdate()->findOrFail((int) $argv[3]);
    \Illuminate\Support\Facades\DB::table('team_memberships')
        ->where('organization_id', (int) $argv[1])
        ->where('team_id', (int) $argv[3])
        ->where('user_id', (int) $argv[4])
        ->update(['is_active' => false, 'updated_at' => now()]);
});
file_put_contents($marker.'.done', 'done');
PHP;
        $worker = new Process([
            PHP_BINARY,
            '-r',
            $script,
            (string) $organization->id,
            $startedMarker,
            (string) $companyTeam->id,
            (string) $manager->id,
        ], base_path(), timeout: 30);
        $worker->start();

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && ! file_exists($startedMarker.'.started')) {
            usleep(25_000);
        }

        expect(file_exists($startedMarker.'.started'))->toBeTrue('The competing team membership update did not start.');
        usleep(300_000);
        expect($worker->isRunning())->toBeTrue('Membership revocation must wait until the contact import commits.');
        expect(file_exists($startedMarker.'.done'))->toBeFalse();

        DB::connection()->commit();
        $worker->wait();

        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput())
            ->and(file_exists($startedMarker.'.done'))->toBeTrue()
            ->and(DB::table('company_contacts')->where('company_id', $company->id)->count())->toBe(1)
            ->and(DB::table('team_memberships')->where('team_id', $companyTeam->id)->where('user_id', $manager->id)->value('is_active'))->toBeFalse();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($worker?->isRunning()) {
            $worker->stop();
        }

        @unlink($startedMarker);
        @unlink($startedMarker.'.started');
        @unlink($startedMarker.'.done');
        DB::table('organizations')->where('id', $organization->id)->delete();
        DB::table('users')->where('id', $manager->id)->delete();
        DB::connection()->beginTransaction();
    }
});

it('rechecks an importer membership after waiting for a concurrent organization revocation', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Queued import membership locking is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $organization->users()->attach($otherOwner, ['role' => 'owner']);
    app(TenantContext::class)->set($organization);
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'status' => 'queued',
        'total_rows' => 1,
        'valid_rows' => 1,
        'processing_attempts' => 1,
    ]);
    app(DataImportProcessor::class)->stageRows($batch, [[
        'line' => 2,
        'attributes' => ['name' => 'Não criar após revogação'],
    ]]);
    DB::connection()->commit();

    $startedMarker = tempnam(sys_get_temp_dir(), 'import-member-lock-');
    expect($startedMarker)->not->toBeFalse();
    @unlink($startedMarker);
    $worker = null;

    try {
        DB::beginTransaction();
        Organization::query()->lockForUpdate()->findOrFail($organization->id);
        DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $owner->id)
            ->update(['is_active' => false, 'updated_at' => now()]);

        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
file_put_contents($argv[3].'.started', 'started');
app()->call([new \App\Jobs\ProcessDataImportChunk((int) $argv[1], (int) $argv[2], 1), 'handle']);
file_put_contents($argv[3].'.done', 'done');
PHP;
        $worker = new Process([
            PHP_BINARY,
            '-r',
            $script,
            (string) $organization->id,
            (string) $batch->id,
            $startedMarker,
        ], base_path(), timeout: 30);
        $worker->start();

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && ! file_exists($startedMarker.'.started')) {
            usleep(25_000);
        }

        expect(file_exists($startedMarker.'.started'))->toBeTrue('The queued import worker did not start.');
        usleep(300_000);
        expect($worker->isRunning())->toBeTrue('The worker must wait for the in-flight organization membership update.');
        expect(file_exists($startedMarker.'.done'))->toBeFalse();

        DB::connection()->commit();
        $worker->wait();

        expect($worker->getExitCode())->not->toBe(0)
            ->and(file_exists($startedMarker.'.done'))->toBeFalse()
            ->and($batch->fresh()->status)->toBe('queued')
            ->and(Company::query()->where('name', 'Não criar após revogação')->exists())->toBeFalse();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($worker?->isRunning()) {
            $worker->stop();
        }

        @unlink($startedMarker);
        @unlink($startedMarker.'.started');
        @unlink($startedMarker.'.done');
        DB::table('organizations')->where('id', $organization->id)->delete();
        DB::table('users')->whereIn('id', [$owner->id, $otherOwner->id])->delete();
        DB::connection()->beginTransaction();
    }
});

it('rechecks manager access to a duplicate company after a concurrent team membership revocation', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Duplicate company access locking is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $destinationTeam = Team::query()->create([
        'name' => 'Equipe importação de destino',
        'status' => 'active',
        'created_by' => $manager->id,
    ]);
    $companyTeam = Team::query()->create([
        'name' => 'Equipe da empresa duplicada',
        'status' => 'active',
        'created_by' => $manager->id,
    ]);
    assignTeamMember($organization, $destinationTeam, $manager, 'manager');
    assignTeamMember($organization, $companyTeam, $manager, 'manager');
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Empresa duplicada de outra equipe',
        'city' => 'Recife',
        'state' => 'PE',
        'tax_id' => null,
        'phone' => '1111',
        'team_id' => $companyTeam->id,
    ]);
    $batch = DataImport::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $manager->id,
        'team_id' => $destinationTeam->id,
        'status' => 'validated',
    ]);
    DB::connection()->commit();

    $startedMarker = tempnam(sys_get_temp_dir(), 'company-duplicate-team-lock-');
    expect($startedMarker)->not->toBeFalse();
    @unlink($startedMarker);
    $worker = null;

    try {
        DB::beginTransaction();
        Team::query()->lockForUpdate()->findOrFail($companyTeam->id);
        DB::table('team_memberships')
            ->where('organization_id', $organization->id)
            ->where('team_id', $companyTeam->id)
            ->where('user_id', $manager->id)
            ->where('role', 'manager')
            ->update(['is_active' => false, 'updated_at' => now()]);

        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
file_put_contents($argv[4].'.started', 'started');
$batch = \App\Models\DataImport::query()->findOrFail((int) $argv[2]);
$manager = \App\Models\User::query()->findOrFail((int) $argv[3]);
$company = \App\Models\Company::query()->findOrFail((int) $argv[5]);
app(\App\Domain\Imports\CompanyImportService::class)->import(
    $batch,
    $manager,
    [[
        'line' => 2,
        'attributes' => ['name' => $company->name, 'city' => $company->city, 'state' => $company->state, 'phone' => '2222'],
        'resolution' => ['action' => 'update', 'target_id' => $company->id],
    ]],
    app(\App\Domain\Commercial\CustomerScoreService::class),
    null,
    app(\App\Domain\Journeys\JourneyService::class),
);
file_put_contents($argv[4].'.done', 'done');
PHP;
        $worker = new Process([
            PHP_BINARY,
            '-r',
            $script,
            (string) $organization->id,
            (string) $batch->id,
            (string) $manager->id,
            $startedMarker,
            (string) $company->id,
        ], base_path(), timeout: 30);
        $worker->start();

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && ! file_exists($startedMarker.'.started')) {
            usleep(25_000);
        }

        expect(file_exists($startedMarker.'.started'))->toBeTrue('The duplicate import worker did not start.');
        usleep(300_000);
        expect($worker->isRunning())->toBeTrue('A duplicate update must wait for the target team access lock.');
        expect(file_exists($startedMarker.'.done'))->toBeFalse();

        DB::connection()->commit();
        $worker->wait();

        expect($worker->getExitCode())->not->toBe(0)
            ->and(file_exists($startedMarker.'.done'))->toBeFalse()
            ->and($company->fresh()->phone)->toBe('1111');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($worker?->isRunning()) {
            $worker->stop();
        }

        @unlink($startedMarker);
        @unlink($startedMarker.'.started');
        @unlink($startedMarker.'.done');
        DB::table('organizations')->where('id', $organization->id)->delete();
        DB::table('users')->where('id', $manager->id)->delete();
        DB::connection()->beginTransaction();
    }
});
