<?php

namespace App\Jobs;

use App\Models\Signal;
use App\Services\DiscordNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SignalScanBatchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, array{id:int, pair:string, progressive:bool, tfs:?array<int,string>}>  $pairs
     */
    public function __construct(
        public readonly string $exchange,
        public readonly int $lookback,
        public readonly array $pairs,
    ) {}

    public function handle(): void
    {
        $startedAt = now();
        $batchFile = tempnam(sys_get_temp_dir(), 'scan-batch-').'.json';
        file_put_contents($batchFile, json_encode($this->pairs));

        $jobStartedAt = microtime(true);

        try {
            $process = new Process(
                [
                    'python3', 'run_scanner.py',
                    '--batch', $batchFile,
                    '--exchange', $this->exchange,
                    '--lookback', (string) $this->lookback,
                ],
                base_path('python'),
                timeout: $this->timeout,
            );
            $process->run();

            $elapsed = round(microtime(true) - $jobStartedAt, 2);
            $this->logProcessOutput($process);
            Log::channel('scanner')->info(
                "Batch completed in {$elapsed}s — {$this->exchange}, ".count($this->pairs).' pairs',
                ['elapsed_seconds' => $elapsed, 'exchange' => $this->exchange, 'batch_size' => count($this->pairs)]
            );

            if (! $process->isSuccessful()) {
                throw new \RuntimeException("Batch scanner failed (exit {$process->getExitCode()})");
            }

            $pairIds = array_column($this->pairs, 'id');
            $newSignals = Signal::whereHas('pairScan', fn ($q) => $q->whereIn('screener_pair_id', $pairIds))
                ->where('created_at', '>=', $startedAt)
                ->with('pairScan')
                ->get();

            $notifier = new DiscordNotifier;
            foreach ($newSignals as $signal) {
                rescue(fn () => $notifier->signalFound($signal));
            }
        } finally {
            @unlink($batchFile);
        }
    }

    private function logProcessOutput(Process $process): void
    {
        $context = [
            'batch_size' => count($this->pairs),
            'exchange' => $this->exchange,
            'pairs' => implode(',', array_column($this->pairs, 'pair')),
        ];

        $log = Log::channel('scanner');

        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }
            $log->info($line, $context);
        }

        if (! $process->isSuccessful()) {
            foreach (explode("\n", trim($process->getErrorOutput())) as $line) {
                if ($line === '') {
                    continue;
                }
                $log->error($line, $context);
            }
        }
    }
}
