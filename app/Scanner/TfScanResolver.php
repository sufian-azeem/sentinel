<?php

namespace App\Scanner;

class TfScanResolver
{
    /** @var array<string, int> TF label → candle duration in milliseconds */
    private const array DURATIONS_MS = [
        '15M' => 900_000,
        '1H' => 3_600_000,
        '4H' => 14_400_000,
    ];

    /**
     * Return the subset of TFs that have a new closed candle since the last scan.
     *
     * @param  array<string, mixed>  $tfData  Contents of screener_pairs.tf_data_json
     * @return string[]
     */
    public function resolve(array $tfData): array
    {
        $nowMs = now()->timestamp * 1000;
        $tfs = [];

        foreach (self::DURATIONS_MS as $tf => $tfMs) {
            $lastTs = $tfData[$tf]['alligator']['seed']['last_timestamp'] ?? 0;
            $currentCandleOpen = intdiv($nowMs, $tfMs) * $tfMs;

            if ($lastTs < $currentCandleOpen) {
                $tfs[] = $tf;
            }
        }

        return $tfs;
    }
}
