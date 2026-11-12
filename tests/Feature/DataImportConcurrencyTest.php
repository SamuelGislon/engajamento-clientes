<?php

use App\Domain\Imports\CompanyImportService;
use App\Domain\Tenancy\TenantContext;
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
