<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('trading:run-scanner
    {--screener-run-id= : Load qualified pairs from this screener run ID (DB-driven mode)}
    {--file= : Path to local JSON file — runs full screener+scanner (legacy mode)}
    {--top=20 : Number of screened pairs to check}
    {--exchange=hyperliquid : CCXT exchange to fetch candles from}
    {--lookback=1 : Number of recent closed candles to check per pair}')]
#[Description('Run the signal scanner — checks Alligator BUY signals for screened pairs')]
class RunScanner extends Command
{
    public function handle(): int
    {
        $pythonDir = base_path('python');

        $cmd = ['python3', 'run_scanner.py'];

        if ($runId = $this->option('screener-run-id')) {
            $cmd[] = '--screener-run-id';
            $cmd[] = $runId;
        } elseif ($file = $this->option('file')) {
            $cmd[] = '--file';
            $cmd[] = $file;
        }

        $cmd[] = '--top';
        $cmd[] = $this->option('top');

        $cmd[] = '--exchange';
        $cmd[] = $this->option('exchange');

        $cmd[] = '--lookback';
        $cmd[] = $this->option('lookback');

        $process = new Process($cmd, $pythonDir);
        $process->setTimeout(600);

        $process->run(function (string $type, string $output): void {
            $this->output->write($output);
        });

        return $process->getExitCode() ?? self::SUCCESS;
    }
}
