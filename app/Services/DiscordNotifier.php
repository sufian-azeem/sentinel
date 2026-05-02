<?php

namespace App\Services;

use App\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class DiscordNotifier
{
    public function signalFound(Signal $signal): void
    {
        $scan = $signal->pairScan;
        $exchange = $scan?->exchange ?? 'unknown';
        $exchangeLabel = ucfirst($exchange);

        $fields = [
            ['name' => 'Entry',     'value' => '`'.number_format((float) $signal->entry_price, 6).'`', 'inline' => true],
            ['name' => 'TP1',       'value' => $signal->tp1_price ? '`'.number_format((float) $signal->tp1_price, 6).'`' : '—', 'inline' => true],
            ['name' => "\u{200B}",  'value' => "\u{200B}", 'inline' => true],
            ['name' => 'Stop Loss', 'value' => $signal->sl_price ? '`'.number_format((float) $signal->sl_price, 6).'`' : '—', 'inline' => true],
            ['name' => 'TP2',       'value' => $signal->tp2_price ? '`'.number_format((float) $signal->tp2_price, 6).'`' : '—', 'inline' => true],
            ['name' => "\u{200B}",  'value' => "\u{200B}", 'inline' => true],
            ['name' => 'Risk',      'value' => number_format((float) $signal->risk_pct, 2).'%', 'inline' => true],
            ['name' => 'Score',     'value' => number_format((float) $signal->screener_score, 4), 'inline' => true],
            ['name' => 'Exchange',  'value' => $exchangeLabel, 'inline' => true],
        ];

        $previewUrl = URL::temporarySignedRoute('signals.preview', now()->addDays(7), ['signal' => $signal->id]);
        $authUrl = route('signals.show', $signal->id);

        $response = $this->send(
            [
                'embeds' => [[
                    'title' => '🔔 Signal Found',
                    'url' => $previewUrl,
                    'description' => "**{$signal->pair}** · {$signal->timeframe} · {$signal->entry_type}\n[Open (login required)]({$authUrl})",
                    'color' => 0x00C853,
                    'fields' => $fields,
                    'footer' => ['text' => "Signal #{$signal->id} · anonymous link expires in 7 days"],
                    'timestamp' => now()->toIso8601String(),
                ]],
            ],
            wait: true,
        );

        // Store message ID so SL/TP can reply to this message
        if ($response && isset($response['id'])) {
            $signal->updateQuietly(['discord_thread_id' => $response['id']]);
        }
    }

    public function signalClosed(Signal $signal): void
    {
        $outcome = $signal->outcome;
        $status = $signal->status;

        [$emoji, $label, $color] = match ($status) {
            'tp1_hit' => ['✅', 'TP1 Hit', 0xFFD600],
            'tp2_hit' => ['✅', 'TP2 Hit', 0xFFD600],
            'sl_hit' => ['❌', 'SL Hit',  0xF44336],
            default => ['⏹', ucwords(str_replace('_', ' ', $status)), 0x607D8B],
        };

        $exitPrice = $outcome?->exit_price ? number_format((float) $outcome->exit_price, 6) : '—';
        $pnlPct = $outcome?->pnl_pct !== null
            ? (((float) $outcome->pnl_pct >= 0 ? '+' : '').number_format((float) $outcome->pnl_pct, 2).'%')
            : '—';
        $pnlR = $outcome?->pnl_r !== null
            ? (((float) $outcome->pnl_r >= 0 ? '+' : '').number_format((float) $outcome->pnl_r, 2).'R')
            : '—';

        $payload = [
            'embeds' => [[
                'title' => "{$emoji} {$label} — {$signal->pair} · {$signal->timeframe}",
                'description' => "Exit: `{$exitPrice}`  |  **{$pnlPct}**  |  {$pnlR}",
                'color' => $color,
                'footer' => ['text' => "Signal #{$signal->id}"],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];

        if ($signal->discord_thread_id) {
            $payload['message_reference'] = [
                'message_id' => $signal->discord_thread_id,
                'fail_if_not_exists' => false,
            ];
        }

        $this->send($payload);
    }

    private function send(array $payload, bool $wait = false): ?array
    {
        $url = config('services.discord.webhook_url');

        if (! $url) {
            return null;
        }

        if ($wait) {
            $url .= '?wait=true';
        }

        $response = Http::timeout(5)->post($url, $payload);

        return $response->successful() ? $response->json() : null;
    }
}
