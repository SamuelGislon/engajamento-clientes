<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::useCache('file');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('engagement:notify-task-deadlines')->everyFifteenMinutes();
Schedule::command('commercial:scan-inactivity')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('data-imports:purge-expired')->everyFiveMinutes()->withoutOverlapping();
