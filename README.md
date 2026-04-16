# Trading Dashboard — Project Documentation

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture](#2-architecture)
3. [System Flow](#3-system-flow)
4. [Database Schema](#4-database-schema)
5. [Routes Reference](#5-routes-reference)
6. [Laravel Application](#6-laravel-application)
7. [Python Scanner](#7-python-scanner)
8. [Exchange Integrations](#8-exchange-integrations)
9. [Discord Notifications](#9-discord-notifications)
10. [Scheduled Commands](#10-scheduled-commands)
11. [Configuration Reference](#11-configuration-reference)
12. [Deployment & Setup](#12-deployment--setup)
13. [Frontend Patterns](#13-frontend-patterns)

---

## 1. Project Overview

A full-stack trading signal dashboard that automates finding, tracking, and notifying on cryptocurrency trading signals using the **Williams Alligator** indicator strategy.

**What it does:**
- Accepts Orion Terminal screener data (uploaded as a JSON file)
- Filters and ranks pairs by momentum, volume, and BTC correlation
- Scans qualified pairs across three timeframes (15M, 1H, 4H) for Alligator BUY signals
- Tracks active signals every 5 minutes against live closed-candle high/low data
- Notifies Discord when signals are found or closed (TP/SL hit)
- Progressively rescans active pairs every 15 minutes without re-fetching full candle history
- Allows manual signal closure (TP1/TP2/SL) from the UI

**Tech stack:**
- **Laravel 13** (PHP 8.4) — web UI, job queue, scheduling, DB orchestration
- **Python 3.10+** — indicator computation, signal detection, candle fetching via CCXT
- **MySQL** — persistent storage for all runs, results, signals, outcomes
- **CCXT** — unified exchange data layer (Binance, HyperLiquid, MEXC fallback)
- **Database queue** — Laravel queue driver (no Redis required)
- **Discord Webhooks** — real-time signal notifications

---

## 2. Architecture

```
┌─────────────────────────────────────────────────────────┐
│                     USER / BROWSER                       │
└──────────────────────────┬──────────────────────────────┘
                           │ HTTP
┌──────────────────────────▼──────────────────────────────┐
│                  LARAVEL APPLICATION                     │
│                                                          │
│  Controllers  →  Services  →  Jobs  →  Commands          │
│  (Web UI)        (Screener)   (Queue)   (Scheduler)      │
└────────┬───────────────────────────┬────────────────────┘
         │ Eloquent ORM              │ Process::run()
         ▼                           ▼
┌────────────────┐        ┌──────────────────────────────┐
│     MySQL      │        │       PYTHON SCANNER          │
│                │        │                               │
│  screener_runs │◄───────│  scanner/runner.py            │
│  screener_     │        │  scanner/checker.py           │
│    results     │        │  indicators/alligator.py      │
│  signal_scans  │        │  strategies/cwt.py            │
│  signals       │        │  data/fetcher.py (CCXT)       │
│  signal_       │        └──────────────────────────────┘
│    outcomes    │                    │
│  executed_     │                    │ CCXT REST
│    trades      │         ┌──────────▼──────────────────┐
│  favorite_     │         │  EXCHANGES                   │
│    pairs       │         │  Binance / HyperLiquid / MEXC│
└────────────────┘         └──────────────────────────────┘
                           ┌──────────────────────────────┐
                           │  DISCORD WEBHOOK              │
                           │  (signal found / closed)      │
                           └──────────────────────────────┘
```

---

## 3. System Flow

### Flow A — Data Upload → Screener → Scanner → Signals

```
1. User uploads Orion Terminal JSON  (POST /run)
        │
        ▼
2. Laravel ScreenerService filters tickers
   - Hard filters: min volume, min rvol, max BTC corr, min bullish TFs
   - Weighted scoring: momentum + volatility across 7 TFs (5M–1D)
   - Stores: screener_runs + screener_results rows
        │
        ▼
3. RunPipelineJob dispatched (async, queued)
   - Dispatches one SignalScanPairJob per qualified pair (ordered by score)
        │
        ▼
4. SignalScanPairJob (queued, per pair, 3 retries, 60s backoff)
   - Executes: python3 run_scanner.py --screener-result-id {id} --exchange {ex}
   - Throws RuntimeException on non-zero exit code so retries trigger
        │
        ▼
5. Python scanner: _scan_pair_with_candle_reuse()
   - Fetches 15M, 1H, 4H candles once (reuses across TF scans)
   - Falls back to MEXC if primary exchange fails for a pair
        │
        ▼
6. For each TF (15M → 1H → 4H):
   a. Compute Alligator + Heikin-Ashi on LTF candles
   b. Compute Alligator on HTF candles (HTF of 15M is 1H, etc.)
   c. Check last N closed candles for BUY signal:
      - "Pullback"  — HTF bullish + price touches zone + LTF expansion
      - "Awakening" — HTF bullish + alignment streak + 2 green HA bars
   d. If signal found → insert signal row → fire Discord "Signal Found"
   e. Store Alligator SMMA seed in screener_result.tf_data_json
        │
        ▼
7. Signal visible in /signals and /scans
```

### Flow B — Progressive Rescan (Every 15 Minutes)

```
trading:scan-signals fires
        │
        ▼
Find all active screener_runs (completed, started_at within 24 hours)
        │
        ▼
For each qualified pair in each active run:
  - Skip if pair already has an active/tp1_hit signal
  - Delete stale signal_scans (no signals attached, from prior cycles)
  - Dispatch SignalScanPairJob with progressive=true
        │
        ▼
Python: _scan_pair_incremental()
  - Reads stored SMMA seeds from tf_data_json
  - Fetches only 20 candles per TF (vs 300+ for full warmup)
  - Seeds Alligator computation from stored state → continues from there
  - Checks last candle for signal, updates stored seeds for next cycle
  - 3–4× cheaper than full scan
```

### Flow C — Signal Tracking (Every 5 Minutes)

```
trading:track-signals fires
        │
        ▼
Load all signals WHERE status IN ('active', 'tp1_hit')
        │
        ▼
Group by exchange → fetch last closed 5m candle for all pairs concurrently
(Http::pool — one request per pair in parallel)
        │
        ▼
For each signal, compare candle high/low to TP/SL levels:

  status = active:
    candle.high >= tp1_price  →  tp1_hit  (partial — continues watching for TP2/SL)
    candle.low  <= sl_price   →  sl_hit   (final)
    (TP wins if both breach same candle — price crossed TP before reversing)

  status = tp1_hit:
    candle.high >= tp2_price  →  tp2_hit  (final)
    candle.low  <= sl_price   →  sl_hit   (final)
        │
        ▼
On final close → SignalTrackerService::close()
  - Creates/upserts SignalOutcome with exit_price, pnl_pct, pnl_r
  - Updates signal.status
  - Fires Discord "Signal Closed" embed
```

### Flow D — Manual Signal Close

```
User opens /signals/{signal} (must be active or tp1_hit)
  → Clicks "Manual Close" panel
  → Selects TP1 / TP2 / SL + confirms exit price
  → POST /signals/{signal}/close
  → SignalController::closeManually()
  → SignalTrackerService::close()
  → SignalOutcome created, Discord notified, redirected back
```

---

## 4. Database Schema

### `screener_runs`

One record per screener execution.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| data_source | varchar(100) | `orion_file` or `orion_live` |
| total_scanned | int | Total tickers processed |
| total_matched | int | Tickers that passed all filters |
| filters_json | json | Filter thresholds used (exchange, min_volume, min_rvol, etc.) |
| status | enum | `running` / `completed` / `failed` / `expired` |
| error_message | text | Set if status = failed |
| started_at | datetime | |
| finished_at | datetime | |

**Scope:** `ScreenerRun::completed()` — filters `status = 'completed'`  
**Constant:** `ScreenerRun::EXPIRY_HOURS = 24` — runs expire after 24h, stops progressive rescanning

---

### `screener_results`

One row per ticker per screener run.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| screener_run_id | FK | → screener_runs (cascade delete) |
| symbol | varchar(30) | e.g. `BTCUSDT` |
| pair | varchar(20) | e.g. `BTC/USDT` |
| price | decimal(20,8) | Last price at screener time |
| rvol | decimal | Relative volume (15M vs baseline) |
| score | decimal | Weighted screener score (higher = better) |
| alligator_tf | varchar(5) | Recommended entry TF: `15M`, `1H`, or `4H` |
| bullish_count | tinyint | Number of bullish timeframes (0–7) |
| confluence | varchar(50) | Space-separated bullish TF labels e.g. `15M 1H 4H` |
| qualified | boolean | True = passed all hard filters |
| disqualify_reason | varchar(100) | `low_volume`, `low_rvol`, `high_btc_corr`, `low_bullish_tfs` |
| tf_data_json | json | Per-TF alligator state + exchange used (see structure below) |
| filters_json | json | Per-filter pass/fail results |

**`tf_data_json` structure** — used for progressive (incremental) rescanning:
```json
{
  "15M": {
    "alligator": {
      "jaw": 0.952, "teeth": 0.961, "lips": 0.975,
      "bullish": true, "spread_pct": 0.24,
      "seed": {
        "jaw_smma": 0.952, "teeth_smma": 0.961, "lips_smma": 0.975,
        "last_ha_open": 0.958, "last_ha_close": 0.971
      }
    },
    "exchange": "mexc"
  },
  "1H": { "..." },
  "4H": { "..." }
}
```

---

### `signal_scans`

One record per scanning attempt per pair per timeframe.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| screener_run_id | FK | → screener_runs |
| screener_result_id | FK nullable | → screener_results (null-on-delete) |
| pair | varchar(20) | |
| timeframe | varchar(5) | `15M`, `1H`, or `4H` |
| exchange | varchar(30) | `binance`, `hyperliquid`, or `mexc` |
| strategy | varchar(30) | e.g. `cwt` |
| candles_fetched | int | |
| status | enum | `scanned` / `skipped` / `error` |
| conditions_json | json | Per-candle diagnostic data (see structure below) |
| error_message | text | Set on error status |

**`conditions_json` structure** — array of per-candle diagnostics:
```json
[
  {
    "candle_time": "2025-01-15T10:00:00",
    "ltf": { "jaw_off": -0.012, "teeth_off": -0.005, "lips_off": 0.003, "spread_pct": 0.24 },
    "htf": { "bullish": true, "spread_pct": 0.51 },
    "ha_prev": { "open": 0.958, "close": 0.965, "bullish": true },
    "ha_curr": { "open": 0.961, "close": 0.970, "bullish": true },
    "signal": "Pullback",
    "alignment_streak": 5,
    "pullback": true,
    "awakening": false
  }
]
```

---

### `signals`

A detected trading opportunity.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| signal_scan_id | FK | → signal_scans (cascade delete) |
| pair | varchar(20) | |
| timeframe | varchar(5) | |
| strategy | varchar(30) | |
| entry_type | varchar(20) | `Pullback` or `Awakening` |
| entry_price | decimal(20,8) | |
| sl_price | decimal(20,8) | Stop-loss level |
| tp1_price | decimal(20,8) | First take-profit (1.5R) |
| tp2_price | decimal(20,8) | Second take-profit (3.0R) |
| risk_pct | decimal(8,4) | `(entry - sl) / entry × 100` |
| candle_time | datetime | UTC timestamp of signal candle |
| candles_ago | tinyint | 1 = last closed candle |
| screener_score | decimal | Score from screener at scan time |
| confluence | varchar(50) | Bullish TF confluence string |
| conditions_json | json | Full diagnostic from signal_scan |
| status | enum | `active` / `tp1_hit` / `tp2_hit` / `sl_hit` / `expired` |

---

### `signal_outcomes`

Final result record for a closed signal. Created on first close (tp1_hit), fully populated on final close.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| signal_id | FK | → signals (cascade delete) |
| status | enum | `tp1_hit` / `tp2_hit` / `sl_hit` / `breakeven` / `expired` / `manual_close` |
| exit_price | decimal(20,8) | Final exit price (null while tp1_hit in progress) |
| exit_time | datetime | |
| tp1_hit_price | decimal(20,8) | Price when TP1 was hit |
| tp1_hit_at | datetime | |
| tp2_hit_price | decimal(20,8) | |
| tp2_hit_at | datetime | |
| sl_hit_price | decimal(20,8) | |
| sl_hit_at | datetime | |
| breakeven_moved_at | datetime | |
| pnl_pct | decimal(8,4) | `(exit - entry) / entry × 100` |
| pnl_usd | decimal | USD P&L (requires position size — future use) |
| pnl_r | decimal | `(exit - entry) / (entry - sl)` |
| notes | text | Manual close notes |

---

### `executed_trades`

Actual exchange orders — infrastructure present, not yet auto-populated.

Tracks full order lifecycle: entry fill, SL/TP order IDs, exit fill, fees, PnL, status (`pending` / `open` / `closed` / `cancelled`).

---

### `favorite_pairs`

User-bookmarked pairs shown prominently in the screener view.

| Column | Type | Notes |
|--------|------|-------|
| pair | varchar(20) PK | Non-incrementing primary key (e.g. `BTC/USDT`) |

No timestamps except `created_at`.

---

## 5. Routes Reference

### Web Routes (`routes/web.php`)

| Method | Path | Controller@Method | Description |
|--------|------|-------------------|-------------|
| GET | `/` | `DashboardController@index` | Dashboard with active signals, recent runs |
| GET | `/run` | `RunController@index` | Upload form with exchange defaults |
| POST | `/run` | `RunController@store` | Process uploaded JSON, start screener pipeline |
| GET | `/screener` | `ScreenerController@index` | Latest completed screener run |
| GET | `/screener/history` | `ScreenerController@history` | Paginated list of all runs |
| GET | `/screener/{screenerRun}` | `ScreenerController@show` | Specific run results |
| POST | `/screener/favorites/{pair}` | `FavoritePairController@toggle` | Toggle pair favorite (returns JSON) |
| GET | `/signals` | `SignalController@index` | Paginated signals with filters |
| GET | `/signals/{signal}` | `SignalController@show` | Signal detail + outcome |
| POST | `/signals/{signal}/close` | `SignalController@closeManually` | Manually close signal |
| GET | `/scans` | `ScanController@index` | Active monitoring grid + scan history |
| DELETE | `/scans/pairs/{screenerResult}` | `ScanController@removePair` | Remove pair from monitoring |

### Scheduled Commands (`routes/console.php`)

| Frequency | Command | Log File |
|-----------|---------|----------|
| Every 15 min | `trading:scan-signals` | `storage/logs/scanner.log` |
| Every 5 min | `trading:track-signals` | `storage/logs/tracker.log` |

---

## 6. Laravel Application

### Models & Relationships

```
ScreenerRun
  hasMany → ScreenerResult
  hasMany → SignalScan

ScreenerResult
  belongsTo → ScreenerRun
  hasMany   → SignalScan (with signals relation)

SignalScan
  belongsTo → ScreenerRun
  belongsTo → ScreenerResult
  hasMany   → Signal

Signal
  belongsTo → SignalScan
  hasOne    → SignalOutcome
  hasMany   → ExecutedTrade

SignalOutcome
  belongsTo → Signal

FavoritePair
  (standalone, pair is the primary key)
```

### Controllers

**`DashboardController`**
- `index()` — active signal count, latest run, last 10 signals, last 5 runs

**`RunController`**
- `index()` — exchange defaults from `config/trading.php`, recent runs list
- `store(Request)` — validates uploaded JSON, calls `ScreenerService::run()`, dispatches `RunPipelineJob`

**`ScreenerController`**
- `index()` — latest `completed` run via `ScreenerRun::completed()->latest('id')->first()`
- `show(ScreenerRun)` — specific run with qualified + disqualified results
- `history()` — paginated (20/page)
- Private `loadResults(int)` — eager-loads signalScans, orders by qualified desc, score desc
- Private `favoritesMap()` — returns `['BTC/USDT' => 0, ...]` map for O(1) lookup in view

**`SignalController`**
- `index(Request)` — paginated (25/page), filterable by pair/timeframe/status
- `show(Signal)` — loads signalScan → screenerResult → screenerRun chain, outcome
- `closeManually(Request, Signal, SignalTrackerService)` — validates status + price, calls tracker, redirects

**`ScanController`**
- `index(Request)` — active monitoring grid (all runs + TF breakdown per pair) + paginated scan list filterable by pair/run/status
- `removePair(ScreenerResult)` — cascades delete to signal_scans and screener_result

**`FavoritePairController`**
- `toggle(string $pair)` — creates or deletes FavoritePair, returns `{ favorited: bool }`

### Services

**`ScreenerService`** — Pure PHP screener, runs synchronously.

Scoring formula:
```
score = Σ(TF_weight × (change_pct + volatility))
      + bonus_rvol          (if rvol > 2.0)
      + bonus_btc_independence (if btc_corr < 0.5)
      + bonus_volume_delta
      + bonus_confluence    (more bullish TFs = higher bonus)
```

Timeframe weights: `5M=0.05, 15M=0.10, 1H=0.20, 4H=0.25, 8H=0.15, 12H=0.10, 1D=0.15`
(4H has highest weight — favours strong 4H momentum.)

Hard filters applied before scoring:
- `min_volume` — 1H volume in USD (1M for Binance, 100K for HyperLiquid by default)
- `min_rvol` — relative volume threshold (default 0.4)
- `max_btc_corr` — BTC correlation ceiling (default 0.97)
- `min_bullish_tfs` — minimum number of bullish timeframes (default 3)

**`SignalTrackerService`** — Single source of truth for signal close logic.

`close(Signal $signal, string $newStatus, float $exitPrice): void`
- Allowed statuses: `tp1_hit`, `tp2_hit`, `sl_hit`
- Upserts `SignalOutcome` — partial record for tp1_hit, full record for final exits
- Calculates `pnl_pct = (exit - entry) / entry × 100`
- Calculates `pnl_r = (exit - entry) / (entry - sl)` when SL exists
- Updates `signal.status`
- Fires Discord notification (wrapped in `rescue()` — never breaks the close)

Used by both `TrackSignalsCommand` (automated) and `SignalController::closeManually` (manual).

**`DiscordNotifier`** — Sends formatted embeds to Discord webhook.

- `signalFound(Signal)` — green (#00C853) embed: entry/SL/TP1/TP2/risk/score/exchange
- `signalClosed(Signal)` — gold (#FFD600) for TP hit, red (#F44336) for SL hit
- `send(array)` — POSTs to `config('services.discord.webhook_url')`, 5s timeout, silently skips if not configured

### Jobs

**`RunPipelineJob`**
- `$tries = 1`, `$timeout = 60`
- Clears stale `signal_scans` for the run's results
- Dispatches `SignalScanPairJob` per qualified pair, ordered by score descending

**`SignalScanPairJob`**
- `$tries = 3`, `$backoff = 60`, `$timeout = 300`
- Executes `python3 run_scanner.py --screener-result-id {id} --exchange {ex}`
- Appends timestamped output to `storage/logs/scanner.log`
- Queries for new signals created during execution (by `created_at >= $startedAt`)
- Fires `DiscordNotifier::signalFound()` per new signal (wrapped in `rescue()`)
- Throws `RuntimeException` on non-zero Python exit code (enables retry)

### Artisan Commands

**`trading:scan-signals`** — Progressive rescan driver
- Signature: `trading:scan-signals {--run= : Scan a specific run ID (ignores expiry)}`
- Expires runs older than `EXPIRY_HOURS` (24h) first
- Skips pairs with existing active/tp1_hit signals
- Deletes stale signal_scans (no signals attached) before fresh dispatch

**`trading:track-signals`** — TP/SL tracker
- No options
- Groups active signals by exchange, fetches last closed 5m candle per pair concurrently
- Calls `SignalTrackerService::close()` on hits

**`trading:run-scanner`** — Manual CLI wrapper
- Options: `--screener-run-id=`, `--file=`, `--top=20`, `--exchange=hyperliquid`, `--lookback=1`
- Streams Python output to console

**`trading:run-screener`** — Manual CLI wrapper
- Options: `--file=`, `--top=10`, `--min-volume=`, `--min-rvol=`, `--min-bullish-tfs=`
- Streams Python output to console

---

## 7. Python Scanner

### Directory Structure

```
python/
├── run_scanner.py          # CLI entry point for signal scanning
├── run_screener.py         # CLI entry point (screener display only)
├── config.py               # Pydantic settings (paths, exchange defaults)
├── models.py               # Core data models (Candle, Signal, Position, etc.)
├── db/
│   ├── connection.py       # MySQL connection (reads ../.env directly)
│   └── repository.py       # All DB INSERT/UPDATE functions
├── data/
│   └── fetcher.py          # CCXT candle fetcher with retry + gap detection
├── scanner/
│   ├── runner.py           # Main orchestrator (full + incremental modes)
│   ├── checker.py          # Per-pair signal detection logic
│   ├── config.py           # TF_CONFIG, TF_WARMUP_CANDLES
│   ├── fetcher.py          # Pair normalisation, date helpers
│   └── display.py          # Console output formatting
├── screener/
│   ├── runner.py           # Screener CLI orchestrator
│   ├── loader.py           # JSON file / Orion API data loading
│   ├── models.py           # TickerScore, TFSnapshot, ALLIGATOR_TF_RULES
│   ├── scoring.py          # filter_and_score() — mirrors PHP ScreenerService logic
│   └── display.py          # Console output
├── indicators/
│   ├── registry.py         # Maps indicator name → compute function
│   ├── service.py          # IndicatorService.compute(df, requests)
│   └── library/
│       ├── alligator.py    # Williams Alligator + AlligatorValues dataclass
│       ├── heikin_ashi.py  # Heikin-Ashi candle transformation
│       ├── ema_htf.py      # Higher-timeframe EMA injection
│       ├── moving_average.py, rsi.py, bbands.py, donchian.py
│       ├── swing.py, macd.py, ema_atr.py, adx.py
├── strategies/
│   ├── base.py             # BaseStrategy abstract class
│   ├── cwt.py              # Primary strategy: Alligator Pullback + Awakening
│   ├── alligator_v4.py     # Alternate Alligator variant
│   └── loader.py           # load_strategy(id, params)
└── engine/                 # Backtesting engine (not used in live dashboard)
    ├── backtester.py, portfolio.py, metrics.py
    ├── trade_ledger.py, order.py, clock.py
```

### Signal Detection Logic

The primary strategy (`cwt`) detects two patterns:

**Pattern 1 — Pullback**
```
All conditions must pass on the same candle:
  1. HTF Alligator is bullish (lips > teeth > jaw, spread > 0.15%)
  2. LTF Alligator is bullish
  3. Current LTF close is touching or inside the Alligator zone (between jaw and lips)
  4. Two consecutive bullish Heikin-Ashi bars
```

**Pattern 2 — Awakening**
```
All conditions must pass on the same candle:
  1. HTF Alligator is bullish
  2. LTF bullish alignment streak of 2–8 candles
     (lips > teeth > jaw for N consecutive candles without interruption)
  3. Lips have crossed above teeth recently (lips_smma > teeth_smma)
  4. Two consecutive green Heikin-Ashi bars
```

**Exit levels computed at signal time:**
```
entry_price = current candle close
sl_price    = candle low − small buffer  (or Alligator jaw level)
tp1_price   = entry + 1.5 × (entry − sl)   [1.5R target]
tp2_price   = entry + 3.0 × (entry − sl)   [3.0R target]
risk_pct    = (entry − sl) / entry × 100
```

### Williams Alligator Indicator

Three Smoothed Moving Averages (SMMA) applied to Heikin-Ashi bars with time displacement:

| Line | Period | Displacement | Role |
|------|--------|-------------|------|
| Jaw (blue) | 13 | 8 forward | Slowest — "sleeping jaw" |
| Teeth (red) | 8 | 5 forward | Medium |
| Lips (green) | 5 | 3 forward | Fastest — reacts first |

- **Bullish** = lips > teeth > jaw (lines fanned upward)
- **Sleeping** = lines tangled (no clear trend)
- **Zone** = price range between jaw and lips (pullback entry area)

### Progressive Scanning (Incremental Mode)

Full warmup requires 300+ candles per TF. Progressive mode reduces this to 20 by seeding SMMA state:

```
First scan (full warmup):
  - Fetch 300+ historical candles
  - Compute SMMA from scratch
  - Store final state in tf_data_json:
    { jaw_smma, teeth_smma, lips_smma, last_ha_open, last_ha_close }

Every subsequent scan (progressive):
  - Fetch only 20 candles
  - Initialise SMMA from stored seed values
  - Continue computation forward from that state
  - Update stored seeds with latest values
  → 3–4× fewer API calls per cycle
```

### Exchange Fallback (MEXC)

When Binance or HyperLiquid fails for a pair (geo-restriction, delisting, etc.):

1. Primary exchange fetch raises an exception
2. Python immediately retries with MEXC using `BTC/USDT` pair format
3. Exchange that succeeded is stored per-TF in `tf_data_json[tf]["exchange"]`
4. Progressive scans read the stored exchange and use it directly — no repeated Binance attempts

TradingView links reflect the actual exchange used:
- MEXC-fallback pairs → `MEXC:AKEUSDT`
- HyperLiquid pairs → `app.hyperliquid.xyz/trade/{BASE}`
- Binance pairs → `BINANCE:BTCUSDT`

---

## 8. Exchange Integrations

### Binance (signal scanning)
- **Candle data**: `GET /api/v3/klines` via CCXT
- **Tracking candles**: `GET /api/v3/klines?symbol=BTCUSDT&interval=5m&limit=2`
  - `klines[0]` = last closed candle: `high = kline[2]`, `low = kline[3]`
- **Pair format**: `BTCUSDT` (no slash, tracking); `BTC/USDT` (CCXT, scanning)
- **Config**: `BINANCE_API_URL` env var (default: `https://api.binance.com`)
- **Note**: Binance is geo-restricted in some countries (Pakistan → HTTP 451). Use HyperLiquid or a VPN.

### HyperLiquid
- **Candle data**: `POST /info {"type": "candleSnapshot", ...}` via CCXT
  - CCXT requires `walletAddress = "0x000..."` for public endpoints
  - Pair format for perpetuals: `BTC/USDC:USDC`
- **Tracking candles**: Same POST endpoint, `interval=5m`, takes second-to-last element (last fully closed)
  - `high = candle['h']`, `low = candle['l']`
- **Config**: `HYPERLIQUID_API_URL` env var (default: `https://api.hyperliquid.xyz`)

### MEXC (fallback only)
- Used only when primary exchange fails for a pair
- Binance-compatible REST API
- Pair format: standard `BTC/USDT`

---

## 9. Discord Notifications

Set `DISCORD_WEBHOOK_URL` in `.env`. If not set, all notifications are silently skipped (no error thrown).

### Signal Found (green embed)
Fires from `SignalScanPairJob` after Python process completes.
```
🔔 Signal Found — BTC/USDT
Exchange: binance  |  Timeframe: 1H  |  Type: Pullback

Entry:  70,500.000000  |  Risk: 1.23%
SL:     69,635.000000
TP1:    71,797.500000
TP2:    73,095.000000

Score: 82.4500  |  Confluence: 15M 1H 4H
```

### Signal Closed — TP hit (gold embed)
```
✅ TP2 Hit — BTC/USDT
Exit: 73,095.000000
PnL: +3.68%  |  R: +3.0R
```

### Signal Closed — SL hit (red embed)
```
❌ SL Hit — BTC/USDT
Exit: 69,635.000000
PnL: -1.23%  |  R: -1.0R
```

Manual closes also fire a Discord notification (gray embed).

---

## 10. Scheduled Commands

Defined in `routes/console.php`. Requires `php artisan schedule:work` running (or OS cron pointing to `artisan schedule:run`).

### `trading:scan-signals` (every 15 min)

1. Marks runs older than 24h as `expired` (stops rescanning them)
2. Finds all `completed` runs
3. For each qualified pair in each run:
   - Checks `signals` table for existing `active` signal → skips if found
   - Deletes stale `signal_scans` from prior cycles that have no signals
   - Dispatches `SignalScanPairJob` with `progressive=true`
4. Reports dispatched/skipped counts per run

### `trading:track-signals` (every 5 min)

1. Loads all `['active', 'tp1_hit']` signals with their `signalScan`
2. Groups by exchange
3. `Http::pool()` fetches last closed 5m candle for all pairs concurrently (10s timeout each)
4. Warns if fetch fails for an exchange, continues with others
5. Applies TP/SL logic per signal, calls `SignalTrackerService::close()` on hits
6. Reports total checked / updated counts

---

## 11. Configuration Reference

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | Trading Dashboard | Laravel app name |
| `APP_ENV` | local | `local` or `production` |
| `APP_URL` | http://localhost | Used for route generation |
| `DB_HOST` | 127.0.0.1 | MySQL host |
| `DB_PORT` | 3306 | MySQL port |
| `DB_DATABASE` | trading_dashboard | Database name |
| `DB_USERNAME` | root | |
| `DB_PASSWORD` | | |
| `QUEUE_CONNECTION` | database | Queue driver — uses `jobs` table |
| `CACHE_STORE` | database | Cache driver |
| `SESSION_DRIVER` | database | Session driver |
| `BINANCE_API_URL` | https://api.binance.com | Binance REST base URL |
| `HYPERLIQUID_API_URL` | https://api.hyperliquid.xyz | HyperLiquid REST base URL |
| `DISCORD_WEBHOOK_URL` | _(empty)_ | Discord webhook — notifications disabled if not set |

### Trading Config (`config/trading.php`)

Overridable via env vars (`TRADING_HYPERLIQUID_MIN_VOLUME`, `TRADING_BINANCE_MIN_VOLUME`, `TRADING_SCANNER_TOP`, `TRADING_SCANNER_LOOKBACK`).

```php
exchanges:
  binance:
    min_volume:      1_000_000    (1M USD 1H volume)
    min_rvol:        0.4
    min_bullish_tfs: 3

  hyperliquid:
    min_volume:      100_000      (100K USD 1H volume)
    min_rvol:        0.4
    min_bullish_tfs: 3

scanner:
  top:      20   (top N qualified pairs to scan per run)
  lookback: 1    (recent closed candles to check per pair)
```

### Services Config (`config/services.php`)

```php
'discord' => [
    'webhook_url' => env('DISCORD_WEBHOOK_URL'),
],
```

---

## 12. Deployment & Setup

### Prerequisites
- PHP 8.4, Composer
- MySQL 8
- Python 3.10+, pip
- Node.js + npm (for Vite frontend assets)

### Initial Setup

```bash
# Install PHP dependencies
composer install

# Install and build frontend assets
npm install && npm run build

# Configure environment
cp .env.example .env
php artisan key:generate

# Edit .env — set DB credentials, DISCORD_WEBHOOK_URL, exchange API URLs

# Run database migrations
php artisan migrate

# Start the full dev stack (server + queue + scheduler + vite)
composer run dev
```

### Python Setup

```bash
cd python
pip install -r requirements.txt
```

Python reads `../.env` directly for database credentials via `db/connection.py`. No separate Python config needed.

### Running in Production (separate processes)

```bash
php artisan serve                   # or configure nginx/apache
php artisan queue:work              # processes SignalScanPairJob, RunPipelineJob
php artisan schedule:work           # fires trading:scan-signals and trading:track-signals
```

### Manual Operations

```bash
# Force-run signal tracking immediately (check TP/SL)
php artisan trading:track-signals

# Force-run progressive rescan immediately
php artisan trading:scan-signals

# Rescan a specific run (ignores 24h expiry)
php artisan trading:scan-signals --run=5

# Run scanner from CLI for a specific run
php artisan trading:run-scanner --screener-run-id=5 --exchange=hyperliquid

# Scan a specific pair manually
php artisan trading:run-scanner --pair=BTC/USDT --tf=1H --exchange=binance
```

### Log Files

| File | Contents |
|------|----------|
| `storage/logs/laravel.log` | Laravel application errors |
| `storage/logs/scanner.log` | Output from every SignalScanPairJob run |
| `storage/logs/tracker.log` | Output from every trading:track-signals run |

### Typical Workflow

1. Download ticker data from Orion Terminal as JSON
2. Go to `/run`, upload the JSON, configure thresholds (exchange, min volume in K USD, min rVol, etc.), submit
3. Laravel runs screener instantly, dispatches scanner jobs to queue
4. Monitor `/screener` — see qualified pairs ranked by score
5. Monitor `/scans` — see per-pair TF scan status in real time
6. Monitor `/signals` — see detected signals (typically 2–5 minutes after upload)
7. Signals auto-tracked every 5 min; Discord notified on TP/SL hit
8. Active pairs rescanned every 15 min for the next 24 hours
9. After 24 hours, run expires — upload fresh Orion data to restart

---

## 13. Frontend Patterns

### Technology
- **Tailwind CSS v4** — dark theme (gray-900 base)
- **Alpine.js** — loaded from CDN with `defer`
- **No React/Vue** — all interactivity via Alpine.js + Blade

### Alpine.js Rule (important for AI agents)

**Never put JavaScript with `=>` arrow functions or any `>` characters directly inside an HTML `x-data="..."` attribute.** The HTML parser treats `>` as closing the tag even inside double-quoted attributes.

Always use this pattern for non-trivial Alpine components:

```html
<!-- ✅ Correct: JS in <script> block, x-data as a plain call -->
<script>
window._componentData = {
    csrf: '{{ csrf_token() }}',
    initial: @json($someData),
};
document.addEventListener('alpine:init', () => {
    Alpine.data('myComponent', () => ({
        items: window._componentData.initial,
        async doSomething() {
            // arrow functions are fine inside the script block
            this.items = this.items.filter(x => x !== 'removed');
        },
    }));
});
</script>

<div x-data="myComponent()">
    ...
</div>

<!-- ❌ Wrong: arrow function breaks HTML parsing at the > character -->
<div x-data="{ items: [], fn: (x) => x > 0 }">
```

Blade-interpolated values (`csrf_token()`, `url()`, `@json(...)`) should always be placed in a `window._xxx` object inside the `<script>` block, never inside the `x-data` attribute.

### `x-cloak`

The layout includes `<style>[x-cloak]{display:none!important}</style>`. Use `x-cloak` on Alpine-controlled elements to prevent flash of uninitialized content — but only after confirming the component is registered, otherwise the element stays permanently hidden.

### Min Volume Display

The `/run` form displays min volume in thousands (K units) for UX:
- Alpine getter divides stored value by 1000 for display: `min_volume: r.min_volume / 1000`
- Controller multiplies back on submit: `(float) $validated['min_volume'] * 1000`
- Input label says "Min Volume USD 1H (K)" with a "K" suffix span
