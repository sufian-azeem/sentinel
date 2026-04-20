# Trading Dashboard — Architecture Reference

This document is the canonical reference for any AI agent or developer working on this codebase. Read it fully before making changes.

---

## Stack

| Layer | Tech |
|---|---|
| Backend | Laravel 13, PHP 8.4 |
| Scanner | Python 3 (CCXT for exchange APIs) |
| Database | MySQL 8.4 |
| Queue | Laravel queues (database driver), 4 workers via Supervisor |
| Scheduler | `schedule:work` daemon (Supervisor), ticks every minute |
| Frontend | Blade + Alpine.js + Tailwind CSS |
| Containers | Docker / Laravel Sail |

---

## High-Level Flow

```
User uploads Orion Terminal JSON
        ↓
RunController → ScreenerService.run()
        ↓  scores & filters pairs
screener_runs + screener_pairs rows created
        ↓
RunPipelineJob dispatched
        ↓  dispatches one SignalScanPairJob per qualified pair
SignalScanPairJob (queue worker)
        ↓  runs python3 run_scanner.py --screener-pair-id N
Python scanner writes pair_scans + signals rows to DB
        ↓
trading:scan-signals (every 15 min, scheduler)
        ↓  re-dispatches SignalScanPairJob progressively for all active runs
trading:track-signals (every 1 min, scheduler)
        ↓  fetches last closed 5m candle high/low, checks TP/SL hits
Signal closed → signal_outcomes row created, Discord notified
```

---

## Scheduled Commands

| Command | Frequency | Purpose |
|---|---|---|
| `trading:run-scanner` | every 15 min | Runs full screener from live Orion data (legacy mode, not used for uploaded batches) |
| `trading:scan-signals` | every 15 min | Progressively re-scans all active (non-expired) runs for new signals |
| `trading:track-signals` | every 1 min | Checks active signals against last closed 5m candle; updates TP/SL status |

Logs:
- `storage/logs/scanner.log` — scanner + signal scan output
- `storage/logs/tracker.log` — track-signals output

---

## Database Schema

### `screener_runs`
One row per upload batch.

| Column | Notes |
|---|---|
| `status` | `running → completed → expired` |
| `filters_json` | includes `exchange` key: `"hyperliquid"` or `"binance"` |
| `started_at` | used for 24h expiry window |

**Expiry:** `ScanSignalsCommand` sets runs older than 24h to `expired` before querying active ones. `TrackSignalsCommand` intentionally ignores run expiry — it tracks open signals regardless.

---

### `screener_pairs`
One row per pair per screener run.

| Column | Notes |
|---|---|
| `qualified` | `1` = passed all filters, enters signal scanning |
| `tf_data_json` | JSON storing per-TF alligator snapshots + seeds (see Progressive Scanning) |
| `score` | composite screener score, used to order scan priority |

---

### `pair_scans`
One row per pair × timeframe × scan attempt.

| Column | Notes |
|---|---|
| `screener_pair_id` | FK → `screener_pairs.id` |
| `screener_run_id` | FK → `screener_runs.id` |
| `timeframe` | `15M`, `1H`, or `4H` |
| `status` | `scanned` (no setup or signal found), `skipped`, `error` |
| `conditions_json` | per-candle alligator condition breakdown (used for UI display) |

Old stale `pair_scans` rows (without associated signals) are deleted before each new scan dispatch. Rows with signals are preserved.

---

### `signals`
One row per detected signal.

| Column | Notes |
|---|---|
| `pair_scan_id` | FK → `pair_scans.id` |
| `status` | `active → tp1_hit → tp2_hit` or `sl_hit` or `expired` |
| `entry_type` | `Pullback` or `Awakening` (from CWT strategy) |
| `entry_price`, `sl_price`, `tp1_price`, `tp2_price` | levels in decimal |
| `conditions_json` | same structure as `pair_scans.conditions_json` at signal candle |

**Active scope:** `Signal::active()` = status in `['active', 'tp1_hit']` — both still need tracking.

---

### `signal_outcomes`
One row per closed signal (upserted via `updateOrCreate`).

| Column | Notes |
|---|---|
| `status` | `tp1_hit`, `tp2_hit`, `sl_hit`, `breakeven`, `expired`, `manual_close` |
| `exit_price` / `exit_time` | only set when signal fully exits |
| `pnl_pct` | `(exit - entry) / entry * 100` |
| `pnl_r` | `(exit - entry) / (entry - sl)` — risk multiples |
| `tp1_hit_price/at` | filled when TP1 touched, even if signal stays open for TP2 |

---

## Laravel Models & Relationships

```
ScreenerRun
  ↳ hasMany ScreenerPair
      ↳ hasMany PairScan
          ↳ hasMany Signal
              ↳ hasOne SignalOutcome
              ↳ hasMany ExecutedTrade
```

**Important relationship names** (use these exactly, not old names):
- `Signal::pairScan()` → BelongsTo PairScan
- `PairScan::screenerPair()` → BelongsTo ScreenerPair
- `PairScan::screenerRun()` → BelongsTo ScreenerRun
- `ScreenerRun::screenerPairs()` → HasMany ScreenerPair

---

## Queue Jobs

### `RunPipelineJob`
Dispatched after screener completes. Reads top-N qualified pairs from DB and dispatches one `SignalScanPairJob` per pair.

### `SignalScanPairJob`
- **Constructor params:** `screenerPairId`, `pair`, `exchange`, `lookback`, `progressive`
- **Retries:** `tries=3`, `backoff=60s` — must throw on failure to trigger retry
- **Python call:** `python3 run_scanner.py --screener-pair-id N --exchange hyperliquid --lookback 1 [--progressive]`
- **After success:** queries `signals` for rows with `created_at >= job start time` on this `screener_pair_id` and fires Discord `signalFound()` for each new one

---

## Python Scanner

### Entry Point
`python/run_scanner.py` → calls `scanner.runner.main()`

### Key CLI Arguments
| Arg | Used by |
|---|---|
| `--screener-pair-id N` | `SignalScanPairJob` — single pair mode |
| `--progressive` | `SignalScanPairJob` — use stored seeds instead of full warmup |
| `--exchange hyperliquid\|binance` | passed through from job |
| `--lookback N` | how many closed candles to check (default 1) |

### Scan Functions
- `_scan_pair_with_candle_reuse()` — **full scan**: fetches 200+ candles per TF for SMMA warmup
- `_scan_pair_incremental()` — **progressive scan**: fetches only 20 candles, seeds SMMA+HA from stored values

### Progressive Scanning — How It Works

After each full scan, alligator SMMA values + Heikin-Ashi values are stored in `screener_pairs.tf_data_json`:

```json
{
  "15M": {
    "alligator": {
      "jaw": 1.234, "teeth": 1.235, "lips": 1.236,
      "bullish": true, "spread_pct": 0.18,
      "seed": {
        "jaw_smma": 1.234, "teeth_smma": 1.235, "lips_smma": 1.236,
        "last_ha_open": 1.230, "last_ha_close": 1.238
      }
    },
    "exchange": "hyperliquid"
  },
  "1H": { ... },
  "4H": { ... }
}
```

On the next scan:
1. `ScanSignalsCommand` sees `pair_scans` rows already exist → sets `progressive=true`
2. `_scan_pair_incremental()` reads `tf_data_json`, extracts seeds per TF
3. Fetches only 20 recent candles per TF (vs 200+)
4. Seeds SMMA using stored values via `_off_seeded()` in `alligator.py`
5. Seeds HA using `prev_ha_open/prev_ha_close` in `heikin_ashi.py`
6. HTF alligator values (e.g. 1H for a 15M scan) come from stored TF data, NOT a new fetch
7. After scan, updates stored seed with new values

**Fallback:** If any TF is missing a seed, falls back to full scan automatically.

### TF Relationships
| LTF | HTF | HTF Source in Progressive Mode |
|---|---|---|
| 15M | 1H | stored `tf_data_json["1H"]["alligator"]` |
| 1H | 4H | stored `tf_data_json["4H"]["alligator"]` |
| 4H | 1D | fetched fresh (4H has no stored HTF) |

---

## Signal Tracking (`trading:track-signals`)

Runs every minute. Uses **candle high/low** (not live price) to detect TP/SL hits:
- `candle.high >= tp1_price` → TP1 hit
- `candle.low <= sl_price` → SL hit
- Uses last closed **5m candle** per pair

Exit price is recorded as the **level itself** (tp1_price / sl_price), not the candle extreme — reflects expected limit/stop fill price.

**Exchange APIs:**
- Binance: `GET {BINANCE_API_URL}/api/v3/klines?symbol=MONUSDC&interval=5m&limit=2` — index `[0]` = last closed
- HyperLiquid: `POST {HYPERLIQUID_API_URL}/info {"type":"candleSnapshot","req":{"coin":"MON","interval":"5m",...}}` — second-to-last = last closed

Requests are concurrent via `Http::pool()`.

---

## Services

### `SignalTrackerService::close(Signal, string $newStatus, float $exitPrice)`
Single entry point for all signal closes — auto-tracker AND manual UI. Does:
1. Upserts `signal_outcomes` with hit prices, timestamps, PnL%, R-multiple
2. Updates `signals.status`
3. Fires `DiscordNotifier::signalClosed()`

### `DiscordNotifier`
Sends embeds to `DISCORD_WEBHOOK_URL`. Silently skips if not configured.
- `signalFound(Signal)` — green embed, called from `SignalScanPairJob` after Python succeeds
- `signalClosed(Signal)` — gold (TP) or red (SL), called from `SignalTrackerService::close()`

### `ScreenerService`
Scores and filters uploaded tickers. Excludes non-crypto pairs containing `:` in symbol (e.g. `CASH:WTI/USDC`).

---

## Key Config & Environment Variables

```env
# Exchange API base URLs (with fallback defaults)
BINANCE_API_URL=https://api.binance.com
HYPERLIQUID_API_URL=https://api.hyperliquid.xyz

# Discord webhook for signal notifications
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/...

# Exchange screener defaults (values in full USD, not K)
TRADING_HYPERLIQUID_MIN_VOLUME=100000
TRADING_BINANCE_MIN_VOLUME=1000000
```

**Note:** The Run UI displays min volume in K units (÷1000). `RunController::store()` multiplies submitted value ×1000 before use. The Alpine.js getter `d.min_volume` also divides by 1000 for display.

---

## Known Exchange Issues

**Binance — HTTP 451 (Geo-restriction)**
Binance blocks API access from Pakistan. All Binance pair scans will fail with:
```
Service unavailable from a restricted location according to 'b. Eligibility'
```
Use HyperLiquid only, or run Docker through a VPN. This is not a code bug.

---

## Important Gotchas

### 1. Python inserts bypass Eloquent
Python writes directly to MySQL via pymysql. Laravel model observers/events **do not fire** for Python inserts. Discord `signalFound()` is triggered from `SignalScanPairJob` by querying `signals` with `created_at >= job start time` after the process completes.

### 2. ScanController pair filter must be table-qualified
`signal_scans`/`pair_scans` joins `screener_pairs`, and both tables have a `pair` column. Always use `pair_scans.pair` in where clauses, not bare `pair`.

### 3. Alpine x-data arrow functions in HTML attributes
Never put JavaScript using `=>` inside `x-data` HTML attribute strings. Extract to `x-init` or use a component ref. Arrow functions inside HTML attribute strings break Alpine.js parsing.

### 4. Run expiry ownership
`ScanSignalsCommand` owns run expiry — it marks runs `expired` and stops scanning them. `TrackSignalsCommand` deliberately does NOT check run expiry; it tracks open signals regardless of parent run age.

### 5. conditions_json structure
The `conditions_json` array contains candle objects. Access alligator values as `$candle['ltf']` and `$candle['htf']` (not nested under `$candle['conditions']`).

### 6. `sail restart` vs `sail build`
Use `sail restart` for config changes. Only use `sail build` when Dockerfile or composer dependencies actually change.

### 7. Signal active scope
`Signal::active()` scope matches `status IN ('active', 'tp1_hit')` — both statuses still need price tracking. A `tp1_hit` signal is still open waiting for TP2 or SL.

---

## Directory Structure (Key Files)

```
app/
  Console/Commands/
    ScanSignalsCommand.php     — dispatches per-pair scan jobs every 15min
    TrackSignalsCommand.php    — checks TP/SL every minute via candle high/low
  Jobs/
    RunPipelineJob.php         — orchestrator: dispatches SignalScanPairJob per pair
    SignalScanPairJob.php      — runs Python scanner, notifies Discord on new signals
  Models/
    ScreenerRun.php
    ScreenerPair.php
    PairScan.php
    Signal.php                 — has active() scope, pairScan() relationship
    SignalOutcome.php
  Services/
    ScreenerService.php        — scores/filters tickers
    SignalTrackerService.php   — shared close() logic for TP/SL + Discord
    DiscordNotifier.php        — sends embeds to webhook

python/
  run_scanner.py               — CLI entry point
  scanner/
    runner.py                  — main(), _scan_pair_with_candle_reuse(), _scan_pair_incremental()
    checker.py                 — check_signal() — indicator compute + strategy evaluation
    config.py                  — TF_CONFIG, TF_WARMUP_CANDLES
    fetcher.py                 — pair/exchange normalization helpers
  db/
    repository.py              — all DB reads/writes (pymysql direct)
  data/
    fetcher.py                 — fetch_candles() with retry + 429 handling
  indicators/
    library/
      alligator.py             — compute_alligator(), _off_seeded() for incremental mode
      heikin_ashi.py           — compute_heikin_ashi() with prev_ha_open/close seeds
  strategies/
    cwt.py                     — CWT strategy: Pullback + Awakening entry types

routes/
  console.php                  — all Schedule::command() definitions
  web.php                      — all HTTP routes

resources/views/
  run/index.blade.php          — upload + exchange selection UI
  screener/                    — screener results
  scans/index.blade.php        — monitoring dashboard + scan list
  signals/
    index.blade.php            — signals table with filters
    show.blade.php             — signal detail + manual close UI
```
