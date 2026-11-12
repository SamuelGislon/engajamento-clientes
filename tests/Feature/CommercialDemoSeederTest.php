<?php

use App\Models\CommercialAlertOccurrence;
use App\Models\Company;
use App\Models\CustomerScoreHistory;
use App\Models\Sale;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('seeds idempotent fictional commercial scenarios for local demonstration', function () {
    Mail::fake();
    Notification::fake();

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Company::query()->whereIn('name', [
        'Escritório Horizonte',
        'Estúdio Vale',
        'Escritório Brisa',
        'Escritório Recomeço',
        'Escritório Crescente',
    ])->count())->toBe(5)
        ->and(Sale::query()->count())->toBeGreaterThanOrEqual(7)
        ->and(CommercialAlertOccurrence::query()->where('status', 'active')->exists())->toBeTrue()
        ->and(CustomerScoreHistory::query()->exists())->toBeTrue();
});
