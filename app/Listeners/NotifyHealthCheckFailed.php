<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Http;
use Spatie\Health\Enums\Status;
use Spatie\Health\Events\CheckEndedEvent;

class NotifyHealthCheckFailed
{
    public function handle(CheckEndedEvent $event): void
    {
        if (! $event->result->status->equals(Status::failed()) && ! $event->result->status->equals(Status::crashed())) {
            return;
        }

        $webhookUrl = config('services.discord.webhook_url');

        if (! $webhookUrl) {
            return;
        }

        $checkName = $event->check->getName();
        $message = $event->result->getNotificationMessage() ?: $event->result->getShortSummary();

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => "🚨 Health Check Failed: {$checkName}",
                'description' => $message,
                'color' => 0xE53935,
            ]],
        ]);
    }
}
