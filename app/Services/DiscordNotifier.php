<?php

namespace App\Services;

use App\Models\Signal;
use Illuminate\Support\Facades\Http;

class DiscordNotifier
{
    public function signalFound(Signal $signal): void
    {
        $scan = $signal->signalScan;
        $exchange = $scan?->exchange ?? 'unknown';
        $exchangeLabel = ucfirst($exchange);

        $fields = [
            ['name' => 'Entry',  'value' => '`'.number_format((float) $signal->entry_price, 6).'`', 'inline' => true],
            ['name' => 'TP1',    'value' => $signal->tp1_price ? '`'.number_format((float) $signal->tp1_price, 6).'`' : '—', 'inline' => true],
            ['name' => "\u{200B}", 'value' => "\u{200B}", 'inline' => true],
            ['name' => 'Stop Loss', 'value' => $signal->sl_price ? '`'.number_format((float) $signal->sl_price, 6).'`' : '—', 'inline' => true],
            ['name' => 'TP2',    'value' => $signal->tp2_price ? '`'.number_format((float) $signal->tp2_price, 6).'`' : '—', 'inline' => true],
            ['name' => "\u{200B}", 'value' => "\u{200B}", 'inline' => true],
            ['name' => 'Risk',   'value' => number_format((float) $signal->risk_pct, 2).'%', 'inline' => true],
            ['name' => 'Score',  'value' => number_format((float) $signal->screener_score, 4), 'inline' => true],
            ['name' => 'Exchange', 'value' => $exchangeLabel, 'inline' => true],
        ];

        $this->send([
            'embeds' => [[
                'title' => '🔔 Signal Found',
                'description' => "**{$signal->pair}** · {$signal->timeframe} · {$signal->entry_type}",
                'color' => 0x00C853, // emerald green
                'fields' => $fields,
                'footer' => ['text' => "Signal #{$signal->id}"],
                'timestamp' => now()->toIso8601String(),
            ]],
        ]);
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

        $this->send([
            'embeds' => [[
                'title' => "{$emoji} {$label} — {$signal->pair} · {$signal->timeframe}",
                'description' => "Exit: `{$exitPrice}`  |  **{$pnlPct}**  |  {$pnlR}",
                'color' => $color,
                'footer' => ['text' => "Signal #{$signal->id}"],
                'timestamp' => now()->toIso8601String(),
            ]],
        ]);
    }

    private function send(array $payload): void
    {
        $url = config('services.discord.webhook_url');

        if (! $url) {
            return;
        }

        Http::timeout(5)->post($url, $payload);
    }
}
