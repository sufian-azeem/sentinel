<?php

namespace App\Providers;

use App\Health\SupervisorCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            CacheCheck::new(),
            ScheduleCheck::new(),
            QueueCheck::new()->onQueue('health'),
            SupervisorCheck::new()
                ->label('Queue Workers')
                ->programs('queue-worker_00', 'queue-worker_01', 'health-worker'),
        ]);
    }
}
