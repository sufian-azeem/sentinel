<?php

namespace App\Jobs;

use App\Models\PairScan;
use App\Models\ScreenerPair;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPipelineJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public readonly int $screenerRunId,
        public readonly string $exchange,
        public readonly int $top,
        public readonly int $lookback,
    ) {}

    public function handle(): void
    {
        // Clear previous pair_scans for this run so re-runs don't accumulate stale scans
        $pairIds = ScreenerPair::where('screener_run_id', $this->screenerRunId)
            ->qualified()
            ->pluck('id');

        PairScan::whereIn('screener_result_id', $pairIds)->delete();

        // Dispatch one per-pair job per qualified result (up to $top)
        ScreenerPair::where('screener_run_id', $this->screenerRunId)
            ->qualified()
            ->orderByDesc('score')
            ->limit($this->top)
            ->each(function (ScreenerPair $result): void {
                SignalScanPairJob::dispatch(
                    $result->id,
                    $result->pair,
                    $this->exchange,
                    $this->lookback,
                );
            });
    }
}
