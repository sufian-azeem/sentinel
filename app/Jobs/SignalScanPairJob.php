<?php

namespace App\Jobs;

use App\Models\Signal;
use App\Services\DiscordNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class SignalScanPairJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $screenerResultId,
        public readonly string $pair,
        public readonly string $exchange,
        public readonly int $lookback,
        public readonly bool $progressive = false,
    ) {}

    public function handle(): void
    {
        $startedAt = now();

        $command = [
            'python3', 'run_scanner.py',
            '--screener-result-id', (string) $this->screenerResultId,
            '--exchange', $this->exchange,
            '--lookback', (string) $this->lookback,
        ];

        if ($this->progressive) {
            $command[] = '--progressive';
        }

        $process = new Process($command, base_path('python'), timeout: $this->timeout);
        $process->run();

        $this->appendToScannerLog($process);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Scanner failed for {$this->pair} (exit {$process->getExitCode()})");
        }

        $newSignals = Signal::whereHas('pairScan', fn ($q) => $q->where('screener_result_id', $this->screenerResultId))
            ->where('created_at', '>=', $startedAt)
            ->with('pairScan')
            ->get();

        $notifier = new DiscordNotifier;
        foreach ($newSignals as $signal) {
            rescue(fn () => $notifier->signalFound($signal));
        }
    }

    private function appendToScannerLog(Process $process): void
    {
        $output = '['.now()->toDateTimeString().'] Pair='.$this->pair.' ResultId='.$this->screenerResultId.' Exchange='.$this->exchange."\n";
        $output .= $process->getOutput();

        if (! $process->isSuccessful()) {
            $output .= "\n[STDERR]\n".$process->getErrorOutput();
        }

        File::append(storage_path('logs/scanner.log'), $output."\n");
    }
}
