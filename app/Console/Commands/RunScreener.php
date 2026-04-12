<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('trading:run-screener
    {--file= : Path to local JSON file (relative to python/ directory)}
    {--top=10 : Number of top results to show}
    {--min-volume= : Min 1H volume in USD}
    {--min-rvol= : Min relative volume on 15M}
    {--min-bullish-tfs= : Min number of bullish TFs required}')]
#[Description('Run the Orion Terminal screener against a local data file')]
class RunScreener extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pythonDir = base_path('python');

        $cmd = ['python3', 'run_screener.py'];

        if ($file = $this->option('file')) {
            $cmd[] = '--file';
            $cmd[] = $file;
        }

        $cmd[] = '--top';
        $cmd[] = $this->option('top');

        if ($minVolume = $this->option('min-volume')) {
            $cmd[] = '--min-volume';
            $cmd[] = $minVolume;
        }

        if ($minRvol = $this->option('min-rvol')) {
            $cmd[] = '--min-rvol';
            $cmd[] = $minRvol;
        }

        if ($minBullishTfs = $this->option('min-bullish-tfs')) {
            $cmd[] = '--min-bullish-tfs';
            $cmd[] = $minBullishTfs;
        }

        $process = new Process($cmd, $pythonDir);
        $process->setTimeout(300);

        $process->run(function (string $type, string $output): void {
            $this->output->write($output);
        });

        return $process->getExitCode() ?? self::SUCCESS;
    }
}
