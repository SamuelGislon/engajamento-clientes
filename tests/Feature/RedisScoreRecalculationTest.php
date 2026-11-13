<?php

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\RecalculateOrganizationCustomerScores;
use App\Models\CommercialScoreRecalculation;
use App\Models\Company;
use App\Models\CustomerScore;
use App\Models\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql' || config('queue.default') !== 'redis') {
        $this->markTestSkipped('Redis queue processing is verified against PostgreSQL and Redis.');
    }
});

it('processes a commercial score recalculation through a Redis worker', function (): void {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization);

    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $company = Company::factory()->create(['organization_id' => $organization->id]);
    CustomerScore::query()->create([
        'company_id' => $company->id,
        'volume_points' => 0,
        'frequency_points' => 0,
        'potential_points' => 20,
        'relationship_points' => 20,
        'total_score' => 40,
        'tier' => 'C',
        'is_complete' => true,
        'last_calculated_at' => now(),
    ]);

    $recalculation = CommercialScoreRecalculation::query()->create([
        'status' => 'queued',
        'attempt_number' => 1,
        'total_companies' => 1,
        'processed_companies' => 0,
        'reason' => 'Redis worker integration test',
        'settings_snapshot' => [
            'scoring_weights' => $settings->scoring_weights,
            'volume_score_bands' => $settings->volume_score_bands,
            'frequency_score_bands' => $settings->frequency_score_bands,
            'tiers' => $settings->tiers,
        ],
    ]);
    $queue = 'commercial-score-'.Str::uuid();

    RecalculateOrganizationCustomerScores::dispatch(
        $organization->id,
        $recalculation->id,
        $recalculation->attempt_number,
    )->onQueue($queue);

    $exitCode = Artisan::call('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--queue' => $queue,
        '--sleep' => 0,
        '--tries' => 1,
    ]);

    expect($exitCode)->toBe(0, Artisan::output())
        ->and($recalculation->refresh()->status)->toBe('completed')
        ->and($recalculation->processed_companies)->toBe(1);
});
