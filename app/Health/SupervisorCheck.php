<?php

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class SupervisorCheck extends Check
{
    /** @var string[] */
    protected array $programs = [];

    public function programs(string ...$programs): static
    {
        $this->programs = $programs;

        return $this;
    }

    public function run(): Result
    {
        $output = shell_exec('supervisorctl status 2>&1') ?? '';

        $statuses = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(\S+)\s+(\S+)/', trim($line), $m)) {
                $statuses[$m[1]] = $m[2];
            }
        }

        $failed = array_filter(
            $this->programs,
            fn ($p) => ($statuses[$p] ?? '') !== 'RUNNING',
        );

        if (! empty($failed)) {
            return Result::make()->failed('Not running: '.implode(', ', $failed));
        }

        return Result::make()->ok(count($this->programs).' process(es) running');
    }
}
