<?php

namespace App\Providers;

use App\Listeners\NotifyHealthCheckFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Events\CheckEndedEvent;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(CheckEndedEvent::class, NotifyHealthCheckFailed::class);

        Gate::define('viewPulse', fn ($user = null) => true);
    }
}
