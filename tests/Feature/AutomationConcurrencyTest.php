<?php

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommercialAlertOccurrence;
use App\Models\Company;
use App\Models\CustomerScore;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TaskEventNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * @param  array<int, string>  $arguments
 * @return array{codes: array{int|null, int|null}, outputs: array{string, string}, errors: array{string, string}}
 */
function runConcurrentAutomationWorkers(string $script, array $arguments): array
{
    return runConcurrentPhpWorkers($script, $arguments);
}

function deleteAutomationConcurrencyFixture(Organization $organization, array $userIds): void
{
    DB::table('organizations')->where('id', $organization->id)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::connection()->beginTransaction();
}

it('serializes concurrent completion of the same journey step on PostgreSQL', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Journey completion concurrency is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);
    $template = JourneyTemplate::query()->create([
        'name' => 'Jornada concorrente',
        'type' => 'prospecting',
        'trigger_type' => 'manual',
        'is_active' => true,
        'is_default' => false,
    ]);
    $template->steps()->create([
        'title' => 'Primeira etapa',
        'position' => 1,
        'delay_days' => 0,
        'task_title' => 'Primeira tarefa de {company}',
        'task_type' => 'first_contact',
        'task_priority' => 'normal',
    ]);
    $template->steps()->create([
        'title' => 'Segunda etapa',
        'position' => 2,
        'delay_days' => 1,
        'task_title' => 'Segunda tarefa de {company}',
        'task_type' => 'follow_up',
        'task_priority' => 'normal',
    ]);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $seller);
    $firstTask = $enrollment->tasks()->firstOrFail();
    DB::connection()->commit();

    try {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$task = \App\Models\Task::query()->findOrFail((int) $argv[2]);
$user = \App\Models\User::query()->findOrFail((int) $argv[3]);
\Illuminate\Support\Facades\Auth::login($user);
app(\App\Domain\Journeys\JourneyService::class)->completeTask($task, $user, null);
echo 'completed';
PHP;
        $result = runConcurrentAutomationWorkers($script, [
            (string) $organization->id,
            (string) $firstTask->id,
            (string) $seller->id,
        ]);

        expect($result['codes'])->toBe([0, 0], implode(' | ', $result['errors']))
            ->and(Task::query()->where('journey_enrollment_id', $enrollment->id)->count())->toBe(2)
            ->and(DB::table('journey_step_executions')->where('journey_enrollment_id', $enrollment->id)->count())->toBe(2)
            ->and($firstTask->fresh()->status)->toBe('completed');
    } finally {
        deleteAutomationConcurrencyFixture($organization, [$seller->id]);
    }
});

it('creates one due-task reminder when two PostgreSQL scheduler workers overlap', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Scheduler concurrency is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $task = Task::factory()->create([
        'organization_id' => $organization->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'due_at' => now()->addHours(4),
        'origin' => 'manual',
    ]);
    DB::connection()->commit();

    try {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
exit($kernel->call('engagement:notify-task-deadlines'));
PHP;
        $result = runConcurrentAutomationWorkers($script, []);
        $reminderCount = DB::table('notifications')
            ->where('notifiable_id', $seller->id)
            ->where('type', TaskEventNotification::class)
            ->where('data->event', 'task_due_soon')
            ->where('data->organization_id', $organization->id)
            ->count();

        expect($result['codes'])->toBe([0, 0], implode(' | ', $result['errors']))
            ->and($task->fresh()->due_soon_notified_at)->not->toBeNull()
            ->and($reminderCount)->toBe(1);
    } finally {
        deleteAutomationConcurrencyFixture($organization, [$seller->id]);
    }
});

it('creates one inactivity occurrence and task when two PostgreSQL scans overlap', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Inactivity scan concurrency is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'sales_inactivity_days' => 30,
        'relationship_inactivity_days' => 365,
    ]);
    $company->activities()->create([
        'organization_id' => $organization->id,
        'user_id' => $seller->id,
        'type' => 'call',
        'occurred_at' => now(),
        'description' => 'Contato recente para o cenário de concorrência.',
    ]);
    $company->sales()->create([
        'seller_id' => $seller->id,
        'created_by' => $seller->id,
        'description' => 'Compra antiga para o cenário de concorrência.',
        'amount' => '1000.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(90),
        'status' => 'confirmed',
    ]);
    DB::connection()->commit();

    try {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
config(['mail.default' => 'array']);
exit($kernel->call('commercial:scan-inactivity'));
PHP;
        $result = runConcurrentAutomationWorkers($script, []);
        $occurrences = CommercialAlertOccurrence::query()
            ->where('company_id', $company->id)
            ->where('type', 'commercial_inactivity')
            ->where('status', 'active')
            ->get();

        expect($result['codes'])->toBe([0, 0], implode(' | ', $result['errors']))
            ->and($occurrences)->toHaveCount(1)
            ->and(Task::query()
                ->where('company_id', $company->id)
                ->where('origin', 'automatic')
                ->where('status', 'pending')
                ->count())->toBe(1);
    } finally {
        deleteAutomationConcurrencyFixture($organization, [$seller->id]);
    }
});

it('does not recreate a stale reactivation after a purchase commits during a PostgreSQL scan', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Purchase and reactivation concurrency is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'sales_inactivity_days' => 30,
        'relationship_inactivity_days' => 365,
    ]);
    $company->activities()->create([
        'organization_id' => $organization->id,
        'user_id' => $seller->id,
        'type' => 'call',
        'occurred_at' => now(),
        'description' => 'Contato recente para o cenário de concorrência.',
    ]);
    $company->sales()->create([
        'seller_id' => $seller->id,
        'created_by' => $seller->id,
        'description' => 'Compra antiga anterior à reativação.',
        'amount' => '1000.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(90),
        'status' => 'confirmed',
    ]);
    DB::connection()->commit();

    $marker = tempnam(sys_get_temp_dir(), 'commercial-scan-');
    expect($marker)->not->toBeFalse();

    try {
        $scanScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
config(['mail.default' => 'array']);
$marker = $argv[1];
$paused = false;
\Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$paused, $marker): void {
    if (! $paused && str_contains($query->sql, 'last_confirmed_sale_id')) {
        $paused = true;
        file_put_contents($marker, 'ready');
        usleep(1_000_000);
    }
});

exit($kernel->call('commercial:scan-inactivity'));
PHP;
        $scan = new Process([PHP_BINARY, '-r', $scanScript, $marker], base_path(), timeout: 30);
        $scan->start();
        $waitUntil = microtime(true) + 10;
        while (microtime(true) < $waitUntil && file_get_contents($marker) !== 'ready') {
            usleep(50_000);
        }
        expect(file_get_contents($marker))->toBe('ready', 'The scanner did not pause after reading its stale sale snapshot.');

        $purchaseScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['mail.default' => 'array']);
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$companyId = (int) $argv[2];
$seller = \App\Models\User::query()->findOrFail((int) $argv[3]);
\Illuminate\Support\Facades\DB::transaction(function () use ($companyId, $seller): void {
    $company = \App\Models\Company::query()->lockForUpdate()->findOrFail($companyId);
    $company->sales()->create([
        'seller_id' => $seller->id,
        'created_by' => $seller->id,
        'description' => 'Compra registrada durante a leitura de inatividade.',
        'amount' => '1500.00',
        'currency' => 'BRL',
        'sold_at' => now(),
        'status' => 'confirmed',
    ]);
    app(\App\Domain\Commercial\CommercialInactivityService::class)->resolveCommercialForCompany($company);
});
echo 'purchase recorded';
PHP;
        $purchase = new Process([
            PHP_BINARY,
            '-r',
            $purchaseScript,
            (string) $organization->id,
            (string) $company->id,
            (string) $seller->id,
        ], base_path(), timeout: 30);
        $purchase->run();
        $scan->wait();

        expect($purchase->getExitCode())->toBe(0, $purchase->getErrorOutput())
            ->and($scan->getExitCode())->toBe(0, $scan->getErrorOutput())
            ->and(CommercialAlertOccurrence::query()
                ->where('company_id', $company->id)
                ->where('type', 'commercial_inactivity')
                ->where('status', 'active')
                ->count())->toBe(0)
            ->and(Task::query()
                ->where('company_id', $company->id)
                ->where('origin', 'automatic')
                ->whereIn('status', ['pending', 'in_progress', 'postponed'])
                ->count())->toBe(0);
    } finally {
        @unlink($marker);
        deleteAutomationConcurrencyFixture($organization, [$seller->id]);
    }
});

it('recalculates a cancelled sale after a concurrent PostgreSQL score calculation', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Sale cancellation concurrency is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $sale = $company->sales()->create([
        'seller_id' => $seller->id,
        'created_by' => $seller->id,
        'description' => 'Venda concorrente para o cenário de recálculo.',
        'amount' => '1250.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(10),
        'status' => 'confirmed',
    ]);
    DB::connection()->commit();

    $marker = tempnam(sys_get_temp_dir(), 'score-recalculation-');
    expect($marker)->not->toBeFalse();

    try {
        $recalculateScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$marker = $argv[3];
$paused = false;
\Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$paused, $marker): void {
    if (! $paused && str_contains($query->sql, 'sum(') && str_contains($query->sql, 'sales')) {
        $paused = true;
        file_put_contents($marker, 'ready');
        usleep(1_000_000);
    }
});
$company = \App\Models\Company::query()->findOrFail((int) $argv[2]);
app(\App\Domain\Commercial\CustomerScoreService::class)->recalculateForCompany($company, 'Recálculo concorrente de teste');
PHP;
        $recalculation = new Process([
            PHP_BINARY,
            '-r',
            $recalculateScript,
            (string) $organization->id,
            (string) $company->id,
            $marker,
        ], base_path(), timeout: 30);
        $recalculation->start();
        $waitUntil = microtime(true) + 10;

        while (microtime(true) < $waitUntil && file_get_contents($marker) !== 'ready') {
            usleep(50_000);
        }

        expect(file_get_contents($marker))->toBe('ready', 'The score calculation did not pause after aggregating the confirmed sale.');

        $cancelScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
\Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
    $sale = \App\Models\Sale::query()->lockForUpdate()->findOrFail((int) $argv[2]);
    $sale->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'cancelled_by' => (int) $argv[3],
    ]);
    app(\App\Domain\Commercial\CommercialInactivityService::class)->refreshCommercialForCompany($sale->company);
    app(\App\Domain\Commercial\CustomerScoreService::class)->recalculateForCompany($sale->company, 'Venda cancelada em concorrência');
});
PHP;
        $cancellation = new Process([
            PHP_BINARY,
            '-r',
            $cancelScript,
            (string) $organization->id,
            (string) $sale->id,
            (string) $seller->id,
        ], base_path(), timeout: 30);
        $cancellation->run();
        $recalculation->wait();

        expect($cancellation->getExitCode())->toBe(0, $cancellation->getErrorOutput())
            ->and($recalculation->getExitCode())->toBe(0, $recalculation->getErrorOutput())
            ->and($sale->fresh()->status)->toBe('cancelled')
            ->and(CustomerScore::query()->where('company_id', $company->id)->value('volume_points'))->toBe(0)
            ->and(CustomerScore::query()->where('company_id', $company->id)->value('frequency_points'))->toBe(0);
    } finally {
        @unlink($marker);
        deleteAutomationConcurrencyFixture($organization, [$seller->id]);
    }
});

it('keeps a journey task with the new owner when a PostgreSQL reassignment overlaps completion', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Journey reassignment concurrency is verified against PostgreSQL.');
    }

    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $previousSeller = User::factory()->create();
    $newSeller = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $organization->users()->attach($previousSeller, ['role' => 'salesperson']);
    $organization->users()->attach($newSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $team = Team::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Equipe de transferência concorrente',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    assignTeamMember($organization, $team, $previousSeller, 'salesperson');
    assignTeamMember($organization, $team, $newSeller, 'salesperson');
    $company = Company::factory()->ownedBy($previousSeller)->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'relationship_status' => 'prospect',
    ]);
    $template = JourneyTemplate::query()->create([
        'name' => 'Jornada concorrente de transferência',
        'type' => 'prospecting',
        'trigger_type' => 'manual',
        'is_active' => true,
        'is_default' => false,
    ]);
    $template->steps()->createMany([
        [
            'title' => 'Etapa de transferência inicial',
            'position' => 1,
            'delay_days' => 0,
            'task_title' => 'Contatar {company}',
            'task_type' => 'first_contact',
            'task_priority' => 'normal',
        ],
        [
            'title' => 'Etapa após transferência',
            'position' => 2,
            'delay_days' => 1,
            'task_title' => 'Acompanhar {company}',
            'task_type' => 'follow_up',
            'task_priority' => 'normal',
        ],
    ]);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $previousSeller);
    $firstTask = $enrollment->tasks()->firstOrFail();
    DB::connection()->commit();

    $marker = tempnam(sys_get_temp_dir(), 'journey-transfer-');
    expect($marker)->not->toBeFalse();

    try {
        $completionScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$user = \App\Models\User::query()->findOrFail((int) $argv[3]);
\Illuminate\Support\Facades\Auth::login($user);
$marker = $argv[4];
$paused = false;
\Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$paused, $marker): void {
    if (! $paused && str_contains($query->sql, 'journey_enrollments') && str_contains($query->sql, 'for update')) {
        $paused = true;
        file_put_contents($marker, 'locked');
        usleep(1_000_000);
    }
});
$task = \App\Models\Task::query()->findOrFail((int) $argv[2]);
app(\App\Domain\Journeys\JourneyService::class)->completeTask($task, $user, null);
PHP;
        $completion = new Process([
            PHP_BINARY,
            '-r',
            $completionScript,
            (string) $organization->id,
            (string) $firstTask->id,
            (string) $previousSeller->id,
            $marker,
        ], base_path(), timeout: 30);
        $completion->start();
        $waitUntil = microtime(true) + 10;

        while (microtime(true) < $waitUntil && file_get_contents($marker) !== 'locked') {
            usleep(50_000);
        }

        expect(file_get_contents($marker))->toBe('locked', 'The completion worker did not hold the enrollment lock.');

        $assignmentScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$organization = \App\Models\Organization::query()->findOrFail((int) $argv[1]);
app(\App\Domain\Tenancy\TenantContext::class)->set($organization);
$company = \App\Models\Company::query()->findOrFail((int) $argv[2]);
$newOwner = \App\Models\User::query()->findOrFail((int) $argv[3]);
$team = \App\Models\Team::query()->findOrFail((int) $argv[4]);
$actor = \App\Models\User::query()->findOrFail((int) $argv[5]);
app(\App\Domain\Teams\CompanyAssignmentService::class)->assign($company, $newOwner->id, $team->id, $actor, 'Transferência concorrente de teste');
PHP;
        $assignment = new Process([
            PHP_BINARY,
            '-r',
            $assignmentScript,
            (string) $organization->id,
            (string) $company->id,
            (string) $newSeller->id,
            (string) $team->id,
            (string) $owner->id,
        ], base_path(), timeout: 30);
        $assignment->run();
        $completion->wait();
        $nextTask = Task::query()
            ->where('journey_enrollment_id', $enrollment->id)
            ->where('id', '!=', $firstTask->id)
            ->firstOrFail();

        expect($assignment->getExitCode())->toBe(0, $assignment->getErrorOutput())
            ->and($completion->getExitCode())->toBe(0, $completion->getErrorOutput())
            ->and($company->fresh()->owner_user_id)->toBe($newSeller->id)
            ->and($enrollment->fresh()->assigned_to)->toBe($newSeller->id)
            ->and($firstTask->fresh()->assigned_to)->toBe($previousSeller->id)
            ->and($nextTask->assigned_to)->toBe($newSeller->id);
    } finally {
        @unlink($marker);
        deleteAutomationConcurrencyFixture($organization, [$owner->id, $previousSeller->id, $newSeller->id]);
    }
});
