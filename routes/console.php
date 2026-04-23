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

Schedule::command('trading:scan-signals')->everyFifteenMinutes();

Schedule::command('trading:track-signals')->everyFiveMinutes();

Schedule::call(function () {
    $log = storage_path('logs/worker.log');
    if (! file_exists($log) || filesize($log) === 0) {
        return;
    }
    $dated = storage_path('logs/worker-' . now()->subDay()->format('Y-m-d') . '.log');
    rename($log, $dated);
    touch($log);
    chmod($log, 0664);
})->daily()->name('rotate-worker-log')->withoutOverlapping();
