<?php

namespace App\Console\Commands;

use App\Jobs\SignalScanPairJob;
use App\Models\PairScan;
use App\Models\ScreenerPair;
use App\Models\ScreenerRun;
use App\Models\Signal;
use Illuminate\Console\Command;

class ScanSignalsCommand extends Command
{
    protected $signature = 'trading:scan-signals {--run= : Scan a specific run ID (ignores expiry)}';

    protected $description = 'Progressively scan qualified pairs for signals across all active (non-expired) screener runs';

    public function handle(): int
    {
        $lookback = 1;

        if ($runId = $this->option('run')) {
            $runs = ScreenerRun::completed()->where('id', $runId)->get();
        } else {
            ScreenerRun::where('status', 'completed')
                ->where('started_at', '<', now()->subHours(ScreenerRun::EXPIRY_HOURS))
                ->update(['status' => 'expired']);

            $runs = ScreenerRun::completed()->get();
        }

        if ($runs->isEmpty()) {
            $this->info('No active screener runs found.');

            return self::SUCCESS;
        }

        $totalDispatched = 0;
        $totalSkipped = 0;

        foreach ($runs as $run) {
            $exchange = $run->filters_json['exchange'] ?? 'binance';

            $pairs = ScreenerPair::where('screener_run_id', $run->id)
                ->qualified()
                ->orderByDesc('score')
                ->get();

            if ($pairs->isEmpty()) {
                continue;
            }

            $dispatched = 0;
            $skipped = 0;

            foreach ($pairs as $pair) {
                $hasActiveSignal = Signal::whereHas('pairScan', fn ($q) => $q->where('screener_pair_id', $pair->id))
                    ->active()
                    ->exists();

                if ($hasActiveSignal) {
                    $skipped++;

                    continue;
                }

                $hasExistingScan = PairScan::where('screener_pair_id', $pair->id)->exists();

                // Delete stale scans without signals before fresh scan
                PairScan::where('screener_pair_id', $pair->id)
                    ->whereNotIn('id', Signal::select('pair_scan_id'))
                    ->delete();

                SignalScanPairJob::dispatch(
                    $pair->id,
                    $pair->pair,
                    $exchange,
                    $lookback,
                    progressive: $hasExistingScan,
                );

                $dispatched++;
            }

            $this->info("Run #{$run->id} ({$exchange}) — dispatched {$dispatched}, skipped {$skipped} (active signal)");
            $totalDispatched += $dispatched;
            $totalSkipped += $skipped;
        }

        $this->info("Total: {$totalDispatched} dispatched, {$totalSkipped} skipped across {$runs->count()} run(s).");

        return self::SUCCESS;
    }
}
