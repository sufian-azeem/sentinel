<?php

namespace App\Services;

use App\Models\Signal;
use App\Models\SignalOutcome;

class SignalTrackerService
{
    /**
     * Close a signal by recording outcome and updating status.
     *
     * @param  string  $newStatus  One of: tp1_hit, tp2_hit, sl_hit
     * @param  float  $exitPrice  The price at which the level was hit
     */
    public function close(Signal $signal, string $newStatus, float $exitPrice): void
    {
        $now = now();
        $entry = (float) $signal->entry_price;
        $sl = $signal->sl_price !== null ? (float) $signal->sl_price : null;
        $tp2 = $signal->tp2_price !== null ? (float) $signal->tp2_price : null;

        $outcomeData = ['status' => $newStatus];

        // Determine if this is a final exit (no more levels to watch)
        $isFinalExit = match ($newStatus) {
            'tp1_hit' => $tp2 === null, // TP1 is final only if there's no TP2
            'tp2_hit', 'sl_hit' => true,
            default => true,
        };

        match ($newStatus) {
            'tp1_hit' => $outcomeData += ['tp1_hit_price' => $exitPrice, 'tp1_hit_at' => $now],
            'tp2_hit' => $outcomeData += ['tp2_hit_price' => $exitPrice, 'tp2_hit_at' => $now],
            'sl_hit' => $outcomeData += ['sl_hit_price' => $exitPrice, 'sl_hit_at' => $now],
            default => null,
        };

        if ($isFinalExit) {
            $outcomeData['exit_price'] = $exitPrice;
            $outcomeData['exit_time'] = $now;
            $outcomeData['pnl_pct'] = round(($exitPrice - $entry) / $entry * 100, 4);

            if ($sl !== null && $entry !== $sl) {
                $outcomeData['pnl_r'] = round(($exitPrice - $entry) / ($entry - $sl), 4);
            }
        }

        SignalOutcome::updateOrCreate(
            ['signal_id' => $signal->id],
            $outcomeData,
        );

        $signal->update(['status' => $newStatus]);

        // Auto move SL to break-even on MEXC after TP1 (only when TP2 exists)
        if ($newStatus === 'tp1_hit' && $tp2 !== null) {
            $trade = $signal->executedTrades()->open()->latest()->first();
            if ($trade?->tp2_price) {
                rescue(fn () => (new MexcSpotService)->moveBreakeven($trade));
            }
        }

        rescue(fn () => (new DiscordNotifier)->signalClosed($signal->fresh()->load('outcome')));
    }
}
