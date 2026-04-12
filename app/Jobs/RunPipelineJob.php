<?php

namespace App\Jobs;

use App\Models\ScreenerResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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
        // Clear previous signal_scans for this run so re-runs don't accumulate stale scans
        $resultIds = ScreenerResult::where('screener_run_id', $this->screenerRunId)
            ->where('qualified', true)
            ->pluck('id');

        DB::table('signal_scans')->whereIn('screener_result_id', $resultIds)->delete();

        // Dispatch one per-pair job per qualified result (up to $top)
        ScreenerResult::where('screener_run_id', $this->screenerRunId)
            ->where('qualified', true)
            ->orderByDesc('score')
            ->limit($this->top)
            ->each(function (ScreenerResult $result): void {
                SignalScanPairJob::dispatch(
                    $result->id,
                    $result->pair,
                    $this->exchange,
                    $this->lookback,
                );
            });
    }
}
