<?php

namespace App\Jobs;

use App\Models\Signal;
use App\Services\DiscordNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SignalScanPairJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $screenerPairId,
        public readonly string $pair,
        public readonly string $exchange,
        public readonly int $lookback,
        public readonly bool $progressive = false,
        public readonly ?array $tfs = null,
    ) {}

    public function handle(): void
    {
        $startedAt = now();

        $command = [
            'python3', 'run_scanner.py',
            '--screener-pair-id', (string) $this->screenerPairId,
            '--exchange', $this->exchange,
            '--lookback', (string) $this->lookback,
        ];

        if ($this->progressive) {
            $command[] = '--progressive';
        }

        if ($this->tfs !== null) {
            array_push($command, '--tfs', ...$this->tfs);
        }

        $process = new Process($command, base_path('python'), timeout: $this->timeout);
        $process->run();

        $this->logProcessOutput($process);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Scanner failed for {$this->pair} (exit {$process->getExitCode()})");
        }

        $newSignals = Signal::whereHas('pairScan', fn ($q) => $q->where('screener_pair_id', $this->screenerPairId))
            ->where('created_at', '>=', $startedAt)
            ->with('pairScan')
            ->get();

        $notifier = new DiscordNotifier;
        foreach ($newSignals as $signal) {
            rescue(fn () => $notifier->signalFound($signal));
        }
    }

    private function logProcessOutput(Process $process): void
    {
        $context = [
            'pair' => $this->pair,
            'pair_id' => $this->screenerPairId,
            'exchange' => $this->exchange,
            'mode' => $this->progressive ? 'progressive' : 'full',
            'tfs' => $this->tfs ? implode(' ', $this->tfs) : 'all',
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
