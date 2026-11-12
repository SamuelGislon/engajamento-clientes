<?php

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function createManagedTeam(Organization $organization, User $manager, string $name = 'Equipe'): Team
{
    $team = $organization->teams()->create([
        'name' => $name,
        'created_by' => $manager->id,
    ]);

    assignTeamMember($organization, $team, $manager, 'manager');

    return $team;
}

function assignTeamMember(Organization $organization, Team $team, User $user, string $role): void
{
    $team->users()->syncWithoutDetaching([
        $user->id => [
            'organization_id' => $organization->id,
            'role' => $role,
            'is_active' => true,
            'joined_at' => now(),
        ],
    ]);
}

/**
 * @param  array<int, string>  $arguments
 * @return array{codes: array{int|null, int|null}, outputs: array{string, string}, errors: array{string, string}}
 */
function runConcurrentPhpWorkers(string $script, array $arguments): array
{
    $barrierPath = tempnam(sys_get_temp_dir(), 'codex-process-barrier-');

    if ($barrierPath === false) {
        throw new RuntimeException('Could not create a process synchronization marker.');
    }

    unlink($barrierPath);

    $barrierSnippet = <<<'PHP'
$barrierWorkerId = $argv[count($argv) - 1];
$barrierPrefix = $argv[count($argv) - 2];
$readyPath = $barrierPrefix.'.ready.'.$barrierWorkerId;
file_put_contents($readyPath, 'ready');
$barrierDeadline = microtime(true) + 15;

while (! file_exists($barrierPrefix.'.release')) {
    if (microtime(true) >= $barrierDeadline) {
        throw new RuntimeException('Concurrent worker did not receive the release signal.');
    }

    usleep(10_000);
}
PHP;
    $scriptWithBarrier = preg_replace_callback(
        '/->bootstrap\(\);/',
        fn (array $matches): string => $matches[0]."\n".$barrierSnippet,
        $script,
        1,
    );

    if ($scriptWithBarrier === null || $scriptWithBarrier === $script) {
        throw new RuntimeException('Concurrent worker script has no Laravel bootstrap call for synchronization.');
    }

    $processes = [
        new Process([PHP_BINARY, '-r', $scriptWithBarrier, ...$arguments, $barrierPath, '0'], base_path(), timeout: 30),
        new Process([PHP_BINARY, '-r', $scriptWithBarrier, ...$arguments, $barrierPath, '1'], base_path(), timeout: 30),
    ];

    try {
        foreach ($processes as $process) {
            $process->start();
        }

        $deadline = microtime(true) + 15;
        $ready = false;

        while (microtime(true) < $deadline) {
            $ready = true;

            foreach ($processes as $index => $process) {
                if (file_exists($barrierPath.'.ready.'.$index)) {
                    continue;
                }

                if (! $process->isRunning()) {
                    $process->wait();
                    throw new RuntimeException('A concurrent worker exited before the synchronization barrier: '.$process->getErrorOutput());
                }

                $ready = false;
            }

            if ($ready) {
                break;
            }

            usleep(10_000);
        }

        if (! $ready) {
            throw new RuntimeException('Concurrent workers did not both reach the synchronization barrier.');
        }

        file_put_contents($barrierPath.'.release', 'start');

        foreach ($processes as $process) {
            $process->wait();
        }

        return [
            'codes' => [$processes[0]->getExitCode(), $processes[1]->getExitCode()],
            'outputs' => [$processes[0]->getOutput(), $processes[1]->getOutput()],
            'errors' => [$processes[0]->getErrorOutput(), $processes[1]->getErrorOutput()],
        ];
    } finally {
        foreach ($processes as $index => $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }

            @unlink($barrierPath.'.ready.'.$index);
        }

        @unlink($barrierPath.'.release');
    }
}
