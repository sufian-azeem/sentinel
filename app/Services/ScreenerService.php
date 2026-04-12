<?php

namespace App\Services;

use App\Models\ScreenerResult;
use App\Models\ScreenerRun;
use Illuminate\Support\Facades\DB;

class ScreenerService
{
    private const float MIN_VOLA_BULLISH = 0.03;

    private const float MIN_CHANGE_PCT = 0.2;

    private const float MAX_BTC_CORR = 0.97;

    private const array SKIP_BASES = ['USDC', 'BUSD', 'DAI', 'TUSD', 'FDUSD'];

    private const array SKIP_CONTAINS = ['UP', 'DOWN', 'BULL', 'BEAR', '3L', '3S', '2L', '2S'];

    /** @var array<array{api_key: string, label: string, weight: float}> */
    private const array TIMEFRAMES = [
        ['api_key' => 'tf5m',  'label' => '5M',  'weight' => 0.05],
        ['api_key' => 'tf15m', 'label' => '15M', 'weight' => 0.10],
        ['api_key' => 'tf1h',  'label' => '1H',  'weight' => 0.20],
        ['api_key' => 'tf4h',  'label' => '4H',  'weight' => 0.25],
        ['api_key' => 'tf8h',  'label' => '8H',  'weight' => 0.15],
        ['api_key' => 'tf12h', 'label' => '12H', 'weight' => 0.10],
        ['api_key' => 'tf1d',  'label' => '1D',  'weight' => 0.15],
    ];

    /** @var array<array{tf: string, required: string[]}> */
    private const array ALLIGATOR_TF_RULES = [
        ['tf' => '15M', 'required' => ['15M', '1H', '4H']],
        ['tf' => '1H',  'required' => ['1H',  '4H', '1D']],
        ['tf' => '4H',  'required' => ['4H',  '1D']],
        ['tf' => '1D',  'required' => ['1D']],
    ];

    public function run(
        array $tickers,
        string $dataSource,
        string $exchange,
        float $minVolume,
        float $minRvol,
        int $minBullishTfs,
        int $topN,
        float $minChangePct = self::MIN_CHANGE_PCT,
        float $maxBtcCorr = self::MAX_BTC_CORR,
    ): ScreenerRun {
        return DB::transaction(function () use ($tickers, $dataSource, $exchange, $minVolume, $minRvol, $minBullishTfs, $topN, $minChangePct, $maxBtcCorr) {
            $run = ScreenerRun::create([
                'data_source' => $dataSource,
                'filters_json' => [
                    'exchange'        => $exchange,
                    'min_change'      => $minChangePct,
                    'min_volume'      => $minVolume,
                    'min_rvol'        => $minRvol,
                    'max_corr'        => $maxBtcCorr,
                    'min_bullish_tfs' => $minBullishTfs,
                    'top_n'           => $topN,
                ],
                'status'     => 'running',
                'started_at' => now(),
            ]);

            $results = [];

            foreach ($tickers as $ticker) {
                $processed = $this->processTicker($ticker, $minChangePct, $minVolume, $minRvol, $maxBtcCorr, $minBullishTfs);
                if ($processed !== null) {
                    $results[] = $processed;
                }
            }

            usort($results, function (array $a, array $b): int {
                if ($a['qualified'] !== $b['qualified']) {
                    return $b['qualified'] <=> $a['qualified'];
                }

                return $b['score'] <=> $a['score'];
            });

            foreach ($results as $row) {
                ScreenerResult::create(['screener_run_id' => $run->id, ...$row]);
            }

            $totalMatched = count(array_filter($results, fn (array $r) => $r['qualified']));

            $run->update([
                'status'        => 'completed',
                'total_scanned' => count($results),
                'total_matched' => $totalMatched,
                'finished_at'   => now(),
            ]);

            return $run->fresh();
        });
    }

    /** @param array<string, mixed> $t */
    private function processTicker(
        array $t,
        float $minChangePct,
        float $minVolume,
        float $minRvol,
        float $maxBtcCorr,
        int $minBullishTfs,
    ): ?array {
        $rawSym = (string) ($t['symbol'] ?? '');
        $quoteCurrency = (string) ($t['quoteCurrency'] ?? '');

        if ($quoteCurrency !== '') {
            $base = $rawSym;
            $sym = $rawSym.$quoteCurrency;
        } else {
            $sym = $rawSym;
            $base = str_ends_with($sym, 'USDT') || str_ends_with($sym, 'USDC')
                ? substr($sym, 0, -4)
                : $sym;
        }

        if (in_array($base, self::SKIP_BASES, true)) {
            return null;
        }
        if (str_contains($rawSym, ':')) {
            return null;
        }
        foreach (self::SKIP_CONTAINS as $skip) {
            if (str_contains($base, $skip)) {
                return null;
            }
        }
        if (! str_ends_with($sym, 'USDT') && ! str_ends_with($sym, 'USDC')) {
            return null;
        }

        $price = (float) ($t['price'] ?? 0);
        if ($price <= 0) {
            return null;
        }

        $rvol = (float) ($t['rvol15m'] ?? 0);

        $snapshots = [];
        foreach (self::TIMEFRAMES as $tf) {
            $raw = is_array($t[$tf['api_key']] ?? null) ? $t[$tf['api_key']] : [];
            $chg = $this->safe($raw, 'changePercent');
            $vol = $this->safe($raw, 'volume');
            $vola = $this->safe($raw, 'volatility');
            $vd = $this->safe($raw, 'vdelta');
            $corr = $this->safe($raw, 'btcCorrelation', 1.0);
            $bullish = $chg >= $minChangePct && $vola >= self::MIN_VOLA_BULLISH;

            $snapshots[$tf['label']] = [
                'change_pct' => $chg,
                'volume_usd' => $vol,
                'volatility' => $vola,
                'vdelta'     => $vd,
                'btc_corr'   => $corr,
                'bullish'    => $bullish,
            ];
        }

        $vol1h = $snapshots['1H']['volume_usd'] ?? 0.0;
        $corr1h = $snapshots['1H']['btc_corr'] ?? 1.0;
        $bullishLabels = array_keys(array_filter($snapshots, fn (array $s) => $s['bullish']));
        $bullishCount = count($bullishLabels);

        $filters = [
            'volume_1h'   => ['value' => round($vol1h, 2),  'threshold' => $minVolume,     'pass' => $vol1h >= $minVolume],
            'rvol_15m'    => ['value' => round($rvol, 4),   'threshold' => $minRvol,        'pass' => $rvol >= $minRvol],
            'btc_corr'    => ['value' => round($corr1h, 4), 'threshold' => $maxBtcCorr,     'pass' => $corr1h <= $maxBtcCorr],
            'bullish_tfs' => ['value' => $bullishCount,     'threshold' => $minBullishTfs,  'pass' => $bullishCount >= $minBullishTfs],
        ];

        $disqualifyReason = '';
        if (! $filters['volume_1h']['pass']) {
            $disqualifyReason = 'low_volume';
        } elseif (! $filters['rvol_15m']['pass']) {
            $disqualifyReason = 'low_rvol';
        } elseif (! $filters['btc_corr']['pass']) {
            $disqualifyReason = 'high_btc_corr';
        } elseif (! $filters['bullish_tfs']['pass']) {
            $disqualifyReason = 'low_bullish_tfs';
        }

        $qualified = $disqualifyReason === '';
        $alligatorTf = $this->recommendAlligatorTf($bullishLabels);

        $tfLabels = array_column(self::TIMEFRAMES, 'label');
        $confluence = implode(' ', array_filter($tfLabels, fn (string $label) => in_array($label, $bullishLabels, true)));

        $score = 0.0;
        if ($qualified) {
            foreach (self::TIMEFRAMES as $tf) {
                $snap = $snapshots[$tf['label']] ?? null;
                if ($snap === null) {
                    continue;
                }
                $momentum = min(abs($snap['change_pct']) / 10.0, 1.0) * ($snap['change_pct'] > 0 ? 1.0 : -0.3);
                $volaScore = min($snap['volatility'] / 1.0, 1.0);
                $score += $tf['weight'] * (0.7 * $momentum + 0.3 * $volaScore);
            }

            $rvolBonus = min($rvol / 3.0, 1.0) * 0.05;
            $indepBonus = (1.0 - abs($corr1h)) * 0.03;
            $vd15m = $snapshots['15M']['vdelta'] ?? 0.0;
            $vol15m = max($snapshots['15M']['volume_usd'] ?? 1.0, 1.0);
            $vdRatio = max(-1.0, min(1.0, $vd15m / $vol15m));
            $vdBonus = ($vdRatio * 0.5 + 0.5) * 0.02;
            $confluenceBonus = ($bullishCount / count(self::TIMEFRAMES)) * 0.05;
            $score += $rvolBonus + $indepBonus + $vdBonus + $confluenceBonus;
        }

        $pair = str_ends_with($sym, 'USDT')
            ? substr($sym, 0, -4).'/USDT'
            : substr($sym, 0, -4).'/USDC';

        return [
            'symbol'            => $sym,
            'pair'              => $pair,
            'price'             => $price,
            'rvol'              => $rvol,
            'score'             => $score,
            'alligator_tf'      => $alligatorTf,
            'bullish_count'     => $bullishCount,
            'confluence'        => $confluence,
            'qualified'         => $qualified,
            'disqualify_reason' => $disqualifyReason ?: null,
            'tf_data_json'      => $snapshots,
            'filters_json'      => $filters,
        ];
    }

    /** @param string[] $bullishLabels */
    private function recommendAlligatorTf(array $bullishLabels): ?string
    {
        foreach (self::ALLIGATOR_TF_RULES as $rule) {
            if (count(array_diff($rule['required'], $bullishLabels)) === 0) {
                return $rule['tf'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function safe(array $data, string $key, float $default = 0.0): float
    {
        $value = $data[$key] ?? null;

        return $value !== null ? (float) $value : $default;
    }
}
