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

    private const int BATCH_SIZE = 10;

    public function __construct(
        public readonly int $screenerRunId,
        public readonly string $exchange,
        public readonly int $top,
        public readonly int $lookback,
    ) {}

    public function handle(): void
    {
        $pairs = ScreenerPair::where('screener_run_id', $this->screenerRunId)
            ->qualified()
            ->orderByDesc('score')
            ->limit($this->top)
            ->get();

        $pairIds = $pairs->pluck('id');

        // Clear previous pair_scans so re-runs don't accumulate stale scans
        PairScan::whereIn('screener_pair_id', $pairIds)->delete();

        $batchPairs = $pairs->map(fn (ScreenerPair $pair) => [
            'id' => $pair->id,
            'pair' => $pair->pair,
            'progressive' => false,
            'tfs' => null,
        ])->all();

        foreach (array_chunk($batchPairs, self::BATCH_SIZE) as $chunk) {
            SignalScanBatchJob::dispatch($this->exchange, $this->lookback, $chunk);
        }
    }
}
