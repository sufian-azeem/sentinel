<?php

namespace App\Console\Commands;

use App\Enums\Exchange;
use App\Models\Signal;
use App\Services\SignalTrackerService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TrackSignalsCommand extends Command
{
    protected $signature = 'trading:track-signals';

    protected $description = 'Check active signals against last closed candle high/low and update TP/SL status';

    public function handle(): int
    {
        $signals = Signal::active()->with('pairScan')->get();

        if ($signals->isEmpty()) {
            $this->info('No active signals to track.');

            return self::SUCCESS;
        }

        $byExchange = $signals->groupBy(fn (Signal $s) => $s->pairScan->exchange ?? Exchange::Binance->value);

        $updated = 0;

        foreach ($byExchange as $exchange => $exchangeSignals) {
            $pairs = $exchangeSignals->pluck('pair')->unique()->values()->all();

            try {
                $candles = $this->fetchCandles($exchange, $pairs);
            } catch (\Throwable $e) {
                $this->warn("Failed to fetch candles for {$exchange}: {$e->getMessage()}");

                continue;
            }

            foreach ($exchangeSignals as $signal) {
                $candle = $candles[$signal->pair] ?? null;

                if ($candle === null) {
                    $this->warn("No candle data for {$signal->pair} on {$exchange}");

                    continue;
                }

                if ($this->checkAndUpdate($signal, $candle)) {
                    $updated++;
                }
            }
        }

        $this->info("Checked {$signals->count()} signal(s). Updated: {$updated}.");

        return self::SUCCESS;
    }

    /**
     * Fetch the last closed 1m candle high/low for each pair concurrently.
     *
     * @param  string[]  $pairs
     * @return array<string, array{high: float, low: float}>
     */
    private function fetchCandles(string $exchange, array $pairs): array
    {
        return match (Exchange::from($exchange)) {
            Exchange::Binance => $this->fetchBinanceCandles($pairs),
            Exchange::Hyperliquid => $this->fetchHyperliquidCandles($pairs),
        };
    }

    /** @return array<string, array{high: float, low: float}> */
    private function fetchBinanceCandles(array $pairs): array
    {
        $base = rtrim(env('BINANCE_API_URL', 'https://api.binance.com'), '/');

        // symbol → DB pair map
        $symbolToPair = [];
        foreach ($pairs as $pair) {
            $symbolToPair[str_replace('/', '', $pair)] = $pair;
        }

        // Fetch all pairs concurrently — limit=2 gives [last_closed, current_forming]
        $responses = Http::pool(function (Pool $pool) use ($base, $symbolToPair) {
            foreach (array_keys($symbolToPair) as $symbol) {
                $pool->as($symbol)->timeout(10)
                    ->get("{$base}/api/v3/klines", [
                        'symbol' => $symbol,
                        'interval' => '5m',
                        'limit' => 2,
                    ]);
            }
        });

        $result = [];
        foreach ($responses as $symbol => $response) {
            if (! ($response instanceof Response) || ! $response->successful()) {
                continue;
            }

            $klines = $response->json();
            if (empty($klines)) {
                continue;
            }

            // klines[0] = last closed candle, klines[1] = currently forming
            $closed = $klines[0];
            $dbPair = $symbolToPair[$symbol] ?? null;
            if ($dbPair !== null) {
                $result[$dbPair] = ['high' => (float) $closed[2], 'low' => (float) $closed[3]];
            }
        }

        return $result;
    }

    /** @return array<string, array{high: float, low: float}> */
    private function fetchHyperliquidCandles(array $pairs): array
    {
        $base = rtrim(env('HYPERLIQUID_API_URL', 'https://api.hyperliquid.xyz'), '/');

        // coin → DB pair map
        $coinToPair = [];
        foreach ($pairs as $pair) {
            $coin = explode('/', $pair)[0];
            $coinToPair[$coin] = $pair;
        }

        $now = (int) (microtime(true) * 1000);
        $startTime = $now - (15 * 60 * 1000); // 15 minutes ago (covers last 2–3 5m candles)

        // Fetch all pairs concurrently
        $responses = Http::pool(function (Pool $pool) use ($base, $coinToPair, $startTime, $now) {
            foreach (array_keys($coinToPair) as $coin) {
                $pool->as($coin)->timeout(10)
                    ->post("{$base}/info", [
                        'type' => 'candleSnapshot',
                        'req' => [
                            'coin' => $coin,
                            'interval' => '5m',
                            'startTime' => $startTime,
                            'endTime' => $now,
                        ],
                    ]);
            }
        });

        $result = [];
        foreach ($responses as $coin => $response) {
            if (! ($response instanceof Response) || ! $response->successful()) {
                continue;
            }

            $klines = $response->json();
            if (empty($klines)) {
                continue;
            }

            // Take second-to-last candle (last is currently forming)
            $closed = count($klines) >= 2 ? $klines[count($klines) - 2] : $klines[0];
            $dbPair = $coinToPair[$coin] ?? null;
            if ($dbPair !== null) {
                $result[$dbPair] = ['high' => (float) $closed['h'], 'low' => (float) $closed['l']];
            }
        }

        return $result;
    }

    /** @param array{high: float, low: float} $candle */
    private function checkAndUpdate(Signal $signal, array $candle): bool
    {
        $tp1 = $signal->tp1_price !== null ? (float) $signal->tp1_price : null;
        $tp2 = $signal->tp2_price !== null ? (float) $signal->tp2_price : null;
        $sl = $signal->sl_price !== null ? (float) $signal->sl_price : null;

        $high = $candle['high'];
        $low = $candle['low'];

        $tracker = new SignalTrackerService;

        if ($signal->status === 'active') {
            // TP wins if both breached in the same candle (long position crosses TP before SL)
            if ($tp1 !== null && $high >= $tp1) {
                $tracker->close($signal, 'tp1_hit', $tp1);
                $this->line("  TP1 hit: {$signal->pair} candle high={$high} (tp1={$tp1})");

                return true;
            }

            if ($sl !== null && $low <= $sl) {
                $tracker->close($signal, 'sl_hit', $sl);
                $this->line("  SL hit:  {$signal->pair} candle low={$low} (sl={$sl})");

                return true;
            }
        } elseif ($signal->status === 'tp1_hit') {
            if ($tp2 !== null && $high >= $tp2) {
                $tracker->close($signal, 'tp2_hit', $tp2);
                $this->line("  TP2 hit: {$signal->pair} candle high={$high} (tp2={$tp2})");

                return true;
            }

            if ($sl !== null && $low <= $sl) {
                $tracker->close($signal, 'sl_hit', $sl);
                $this->line("  SL hit (after TP1): {$signal->pair} candle low={$low} (sl={$sl})");

                return true;
            }
        }

        return false;
    }
}
