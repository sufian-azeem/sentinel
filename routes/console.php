<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('health:schedule-check-heartbeat')->everyMinute();
Schedule::command('health:queue-check-heartbeat')->everyMinute();
Schedule::command('health:check')->everyFiveMinutes();

Schedule::command('trading:scan-signals')
    ->everyFifteenMinutes()
    ->appendOutputTo(storage_path('logs/scanner.log'));

Schedule::command('trading:track-signals')
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/tracker.log'));
