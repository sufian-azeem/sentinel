<?php

namespace App\Console\Commands;

use App\Jobs\SignalScanPairJob;
use App\Models\ScreenerResult;
use App\Models\ScreenerRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

            $pairs = ScreenerResult::where('screener_run_id', $run->id)
                ->where('qualified', true)
                ->orderByDesc('score')
                ->get();

            if ($pairs->isEmpty()) {
                continue;
            }

            $dispatched = 0;
            $skipped = 0;

            foreach ($pairs as $result) {
                $hasActiveSignal = DB::table('signals')
                    ->join('signal_scans', 'signals.signal_scan_id', '=', 'signal_scans.id')
                    ->where('signal_scans.screener_result_id', $result->id)
                    ->where('signals.status', 'active')
                    ->exists();

                if ($hasActiveSignal) {
                    $skipped++;

                    continue;
                }

                // Delete stale scans without signals before fresh scan
                DB::table('signal_scans')
                    ->where('screener_result_id', $result->id)
                    ->whereNotIn('id', fn ($q) => $q->select('signal_scan_id')->from('signals'))
                    ->delete();

                SignalScanPairJob::dispatch(
                    $result->id,
                    $result->pair,
                    $exchange,
                    $lookback,
                    progressive: true,
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
