# Trading Dashboard — Project Documentation

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture](#2-architecture)
3. [System Flow](#3-system-flow)
4. [Database Schema](#4-database-schema)
5. [Laravel Application](#5-laravel-application)
6. [Python Scanner](#6-python-scanner)
7. [Exchange Integrations](#7-exchange-integrations)
8. [Discord Notifications](#8-discord-notifications)
9. [Scheduled Jobs](#9-scheduled-jobs)
10. [Configuration Reference](#10-configuration-reference)
11. [Deployment & Setup](#11-deployment--setup)

---

## 1. Project Overview

A full-stack trading signal dashboard that automates the process of finding, tracking, and notifying on cryptocurrency trading signals using the **Williams Alligator** indicator strategy.

**What it does:**
- Accepts Orion Terminal screener data (uploaded as a JSON file)
- Filters and ranks pairs by momentum, volume, and BTC correlation
- Scans qualified pairs across three timeframes (15M, 1H, 4H) for Alligator BUY signals
- Tracks active signals every 5 minutes against live candle data
- Notifies Discord when signals are found or closed (TP/SL hit)
- Progressively rescans active pairs every 15 minutes without re-fetching full candle history

**Tech stack:**
- **Laravel 13** (PHP 8.4) — web UI, job queue, scheduling, DB orchestration
- **Python 3.10+** — indicator computation, signal detection, candle fetching
- **MySQL** — persistent storage
- **CCXT** — unified exchange data layer (Binance, HyperLiquid, MEXC)
- **Redis** — queue driver
- **Discord Webhooks** — real-time notifications

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
│    results     │        │  indicators/                  │
│  signal_scans  │        │  strategies/                  │
│  signals       │        │  data/fetcher.py (CCXT)       │
│  signal_       │        └──────────────────────────────┘
│    outcomes    │                    │
│  executed_     │                    │ CCXT
│    trades      │         ┌──────────▼──────────┐
└────────────────┘         │  EXCHANGES           │
                           │  Binance / HyperLiquid│
┌─────────────────┐        │  MEXC (fallback)     │
│  DISCORD        │        └──────────────────────┘
│  WEBHOOK        │
│  (Notifications)│
└─────────────────┘
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
   - Weighted scoring: momentum + volatility across 7 TFs
   - Stores: screener_runs + screener_results rows
        │
        ▼
3. RunPipelineJob dispatched (async)
   - Dispatches one SignalScanPairJob per qualified pair
        │
        ▼
4. SignalScanPairJob (queued, per pair)
   - Executes: python3 run_scanner.py --screener-result-id {id} --exchange {ex}
        │
        ▼
5. Python scanner: _scan_pair_with_candle_reuse()
   - Fetches 15M, 1H, 4H candles once (reuses across TF scans)
   - Falls back to MEXC if primary exchange fails
        │
        ▼
6. For each TF (15M → 1H → 4H):
   a. Compute Alligator + Heikin-Ashi on LTF candles
   b. Compute Alligator on HTF candles (HTF of 15M is 1H, etc.)
   c. Check last N closed candles for BUY signal:
      - "Pullback"  — HTF bullish + price touches zone + LTF expansion
      - "Awakening" — HTF bullish + alignment streak + 2 green HA bars
   d. If signal found → insert signal row, fire Discord "Signal Found"
   e. Store alligator seed (SMMA state) in screener_result.tf_data_json
        │
        ▼
7. Signal visible on dashboard / signals page
```

### Flow B — Progressive Rescan (Every 15 Minutes)

```
ScanSignalsCommand fires
        │
        ▼
Find all active screener runs (completed, started_at < 24h ago)
        │
        ▼
For each qualified pair in each active run:
  - Skip if pair already has an active signal
  - Dispatch SignalScanPairJob with --progressive flag
        │
        ▼
Python: _scan_pair_incremental()
  - Reads stored SMMA seeds from tf_data_json
  - Fetches only 20 candles per TF (vs 300 for full warmup)
  - Seeds Alligator computation from stored state
  - Checks last candle for signal
  - Updates tf_data_json seeds for next run
  - 3–4× cheaper than full scan
```

### Flow C — Signal Tracking (Every 5 Minutes)

```
TrackSignalsCommand fires
        │
        ▼
Load all signals with status IN ('active', 'tp1_hit')
        │
        ▼
Group by exchange → fetch last closed 5m candle (concurrent, Http::pool)
        │
        ▼
For each signal, compare candle high/low to TP/SL levels:

  status = active:
    candle.high >= tp1_price  →  mark tp1_hit  (still watch for TP2/SL)
    candle.low  <= sl_price   →  mark sl_hit   (final)

  status = tp1_hit:
    candle.high >= tp2_price  →  mark tp2_hit  (final)
    candle.low  <= sl_price   →  mark sl_hit   (final)
        │
        ▼
On final close:
  - Create SignalOutcome (exit_price, pnl_pct, pnl_r)
  - Fire Discord "Signal Closed" embed
```

### Flow D — Manual Signal Close

```
User clicks "Manual Close" on signal detail page
  → Selects TP1 / TP2 / SL + confirms price
  → POST /signals/{signal}/close
  → SignalTrackerService.close()
  → SignalOutcome created, Discord notified
```

---

## 4. Database Schema

### `screener_runs`
Represents one full screener execution.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| data_source | varchar(100) | `orion_file` or `orion_live` |
| total_scanned | int | Total tickers processed |
| total_matched | int | Tickers that passed all filters |
| filters_json | json | Filter thresholds used for this run |
| status | enum | `running` / `completed` / `failed` |
| error_message | text | Set if status = failed |
| started_at | datetime | |
| finished_at | datetime | |

### `screener_results`
One row per ticker per screener run.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| screener_run_id | FK | → screener_runs (cascade delete) |
| symbol | varchar(30) | e.g. `BTCUSDT` |
| pair | varchar(20) | e.g. `BTC/USDT` |
| price | decimal | Last price at screener time |
| rvol | decimal | Relative volume (15M vs baseline) |
| score | decimal | Weighted screener score |
| alligator_tf | varchar(5) | Recommended entry TF: `15M`, `1H`, `4H` |
| bullish_count | tinyint | Number of bullish timeframes |
| confluence | varchar(50) | Space-separated bullish TF labels |
| qualified | boolean | Passed all hard filters |
| disqualify_reason | varchar(100) | `low_volume`, `low_rvol`, `high_btc_corr`, `low_bullish_tfs` |
| tf_data_json | json | Per-TF snapshots + Alligator state + exchange source |
| filters_json | json | Per-filter pass/fail results |

**`tf_data_json` structure:**
```json
{
  "15M": {
    "alligator": {
      "jaw": 0.952,
      "teeth": 0.961,
      "lips": 0.975,
      "bullish": true,
      "spread_pct": 0.24,
      "seed": {
        "jaw_smma": 0.952,
        "teeth_smma": 0.961,
        "lips_smma": 0.975,
        "last_ha_open": 0.958,
        "last_ha_close": 0.971
      }
    },
    "exchange": "mexc"
  },
  "1H": { ... },
  "4H": { ... }
}
```

### `signal_scans`
One scanning attempt per pair per timeframe.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| screener_run_id | FK | → screener_runs |
| screener_result_id | FK | → screener_results (nullable) |
| pair | varchar(20) | |
| timeframe | varchar(5) | `15M`, `1H`, `4H` |
| exchange | varchar(30) | `binance`, `hyperliquid`, `mexc` |
| strategy | varchar(30) | `cwt` |
| candles_fetched | int | |
| status | enum | `scanned` / `skipped` / `error` |
| conditions_json | json | Per-candle diagnostic data |
| error_message | text | Set on error |

**`conditions_json` structure:**
```json
[
  {
    "candle_time": "2025-01-15T10:00:00",
    "ltf": {
      "jaw_off": -0.012, "teeth_off": -0.005, "lips_off": 0.003,
      "spread_pct": 0.24
    },
    "htf": {
      "bullish": true, "spread_pct": 0.51
    },
    "ha_prev": { "open": 0.958, "close": 0.965, "bullish": true },
    "ha_curr": { "open": 0.961, "close": 0.970, "bullish": true },
    "signal": "Pullback",
    "alignment_streak": 5,
    "conditions": {
      "htf_bullish": true,
      "ltf_bullish": true,
      "candle_in_zone": true,
      "two_green_ha": true
    }
  }
]
```

### `signals`
A detected trading opportunity.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| signal_scan_id | FK | → signal_scans (cascade delete) |
| pair | varchar(20) | |
| timeframe | varchar(5) | |
| strategy | varchar(30) | |
| entry_type | varchar(20) | `Pullback` or `Awakening` |
| entry_price | decimal(20,8) | |
| sl_price | decimal(20,8) | Stop-loss level |
| tp1_price | decimal(20,8) | First take-profit |
| tp2_price | decimal(20,8) | Second take-profit |
| risk_pct | decimal(8,4) | `(entry - sl) / entry × 100` |
| candle_time | datetime | UTC timestamp of signal candle |
| candles_ago | tinyint | 1 = last closed candle |
| screener_score | decimal | Score from screener at scan time |
| confluence | varchar(50) | Bullish TF confluence string |
| conditions_json | json | Full diagnostic from signal_scan |
| status | enum | `active` / `tp1_hit` / `tp2_hit` / `sl_hit` / `expired` |

### `signal_outcomes`
Tracks the final result of a signal.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| signal_id | FK | → signals (cascade delete) |
| status | enum | `tp1_hit` / `tp2_hit` / `sl_hit` / `breakeven` / `expired` / `manual_close` |
| exit_price | decimal | Final exit price (null while tp1_hit pending) |
| exit_time | datetime | |
| tp1_hit_price | decimal | Price when TP1 was hit |
| tp1_hit_at | datetime | |
| tp2_hit_price | decimal | |
| tp2_hit_at | datetime | |
| sl_hit_price | decimal | |
| sl_hit_at | datetime | |
| breakeven_moved_at | datetime | |
| pnl_pct | decimal | `(exit - entry) / entry × 100` |
| pnl_usd | decimal | USD P&L (future: requires position size) |
| pnl_r | decimal | `(exit - entry) / (entry - sl)` |
| notes | text | Manual notes |

### `executed_trades`
Actual orders placed on exchange (future use — not yet populated automatically).

Tracks full order lifecycle: entry fill, SL/TP order IDs, exit fill, fees, PnL.

---

## 5. Laravel Application

### Controllers

| Controller | Routes | Purpose |
|-----------|--------|---------|
| `DashboardController` | `GET /` | Key metrics, recent signals, recent runs |
| `RunController` | `GET /run`, `POST /run` | File upload + screener execution |
| `ScreenerController` | `GET /screener`, `/screener/history`, `/screener/{run}` | Browse screener runs and results |
| `SignalController` | `GET /signals`, `/signals/{signal}`, `POST /signals/{signal}/close` | Signal list, detail, manual close |
| `ScanController` | `GET /scans` | Signal scan results with active run monitoring |

### Services

**`ScreenerService`** — Runs entirely in PHP/Laravel memory.
- Accepts an array of TickerScore-like objects from the uploaded JSON
- Applies 4 hard filters per ticker
- Scores each ticker across 7 timeframes using weighted formula
- Recommends entry TF based on which bullish TFs are present
- Stores results to DB, returns `ScreenerRun` model

Screener scoring formula:
```
score = Σ(TF_weight × (change_pct + volatility)) 
      + bonus_rvol (if rvol > 2.0)
      + bonus_btc_independence (if btc_corr < 0.5)
      + bonus_volume_delta
      + bonus_confluence (more bullish TFs = higher bonus)
```

**`SignalTrackerService`** — Single source of truth for signal closes.
- Called by `TrackSignalsCommand` (automated) and `SignalController` (manual)
- `close(Signal $signal, string $newStatus, float $exitPrice)`:
  - Upserts `SignalOutcome` (partial for tp1_hit, full for tp2_hit/sl_hit)
  - Calculates pnl_pct and pnl_r when status is final
  - Updates `signal.status`
  - Fires Discord notification

**`DiscordNotifier`** — Sends formatted embeds to Discord.
- `signalFound(Signal $signal)` — green embed with entry/SL/TP1/TP2/risk/score/exchange
- `signalClosed(Signal $signal)` — gold (TP) or red (SL) embed with exit price, PnL%, R-multiple
- Silently skips if `DISCORD_WEBHOOK_URL` is not configured

### Jobs

**`RunPipelineJob`**
- Triggered after screener completes
- Clears stale `signal_scans` from prior runs for the same result IDs
- Dispatches `SignalScanPairJob` for each qualified result, ordered by score descending

**`SignalScanPairJob`**
- Executes the Python scanner as a subprocess for a single pair
- `$tries = 3`, `$backoff = 60` — retries up to 3 times with 60s delay
- `$timeout = 300` — 5 minute timeout per pair
- Parses new signals created during execution and fires Discord "Signal Found" per signal
- Throws `RuntimeException` on non-zero Python exit code (triggers retry)

### Commands

| Command | Schedule | Description |
|---------|----------|-------------|
| `trading:scan-signals` | Every 15 min | Progressive rescan of active pairs |
| `trading:track-signals` | Every 5 min | TP/SL tracking against candle high/low |
| `trading:run-scanner` | Manual only | CLI wrapper to invoke Python scanner |
| `trading:run-screener` | Manual only | CLI wrapper to invoke Python screener |

---

## 6. Python Scanner

### Directory Structure

```
python/
├── run_scanner.py          # CLI entry point
├── run_screener.py         # CLI entry point (screener only)
├── config.py               # Pydantic settings (paths, exchange defaults, risk params)
├── models.py               # Core data models (Candle, Signal, Position, Trade, etc.)
├── db/
│   ├── connection.py       # MySQL connection (auto-detects Docker vs host)
│   └── repository.py       # All DB INSERT/UPDATE functions
├── data/
│   └── fetcher.py          # CCXT candle fetcher with retry + gap detection
├── scanner/
│   ├── runner.py           # Main orchestrator (full + incremental scan modes)
│   ├── checker.py          # Per-pair signal detection logic
│   ├── config.py           # TF_CONFIG, TF_WARMUP_CANDLES
│   ├── fetcher.py          # Pair normalisation, date helpers
│   └── display.py          # Console output formatting
├── screener/
│   ├── runner.py           # Screener CLI orchestrator
│   ├── loader.py           # JSON file / Orion API data loading
│   ├── models.py           # TickerScore, TFSnapshot, ALLIGATOR_TF_RULES
│   ├── scoring.py          # filter_and_score() main screener logic
│   └── display.py          # Console output
├── indicators/
│   ├── registry.py         # Maps indicator name → compute function
│   ├── service.py          # IndicatorService.compute(df, requests)
│   └── library/
│       ├── alligator.py    # Williams Alligator + AlligatorValues dataclass
│       ├── heikin_ashi.py  # Heikin-Ashi candle transformation
│       ├── ema_htf.py      # Higher-timeframe EMA injection
│       ├── moving_average.py
│       ├── rsi.py, bbands.py, donchian.py
│       ├── swing.py, macd.py, ema_atr.py
├── strategies/
│   ├── base.py             # BaseStrategy abstract class
│   ├── cwt.py              # Primary Alligator strategy (Pullback + Awakening)
│   ├── alligator_v4.py     # Alternate Alligator strategy
│   └── loader.py           # load_strategy(id, params)
└── engine/                 # Backtesting engine (future use)
    ├── backtester.py
    ├── portfolio.py
    ├── metrics.py
    ├── trade_ledger.py
    ├── order.py
    └── clock.py
```

### Signal Detection Logic

The primary strategy (`cwt`) detects two signal patterns:

**Pattern 1 — Pullback**
```
Conditions (all must pass):
  1. HTF Alligator is bullish (lips > teeth > jaw, spread > 0.15%)
  2. LTF Alligator is bullish
  3. Current LTF candle is touching or inside the Alligator zone
     (between jaw and lips)
  4. 2 consecutive bullish Heikin-Ashi bars
```

**Pattern 2 — Awakening**
```
Conditions (all must pass):
  1. HTF Alligator is bullish
  2. LTF bullish alignment streak of 2–8 candles
     (lips > teeth > jaw for N consecutive candles)
  3. Lips have crossed above teeth (lips_smma > teeth_smma, displaced)
  4. 2 consecutive green Heikin-Ashi bars
```

**Exit levels (computed on signal detection):**
```
entry_price = current candle close
sl_price    = candle low − small buffer (or Alligator jaw)
tp1_price   = entry + 1.5 × (entry − sl)  [1.5R]
tp2_price   = entry + 3.0 × (entry − sl)  [3.0R]
risk_pct    = (entry − sl) / entry × 100
```

### Williams Alligator Indicator

The Alligator uses three Smoothed Moving Averages (SMMA) on Heikin-Ashi bars with time displacements:

| Line | Period | Displacement | Description |
|------|--------|-------------|-------------|
| Jaw (blue) | 13 | 8 forward | Slowest — "sleeping jaw" |
| Teeth (red) | 8 | 5 forward | Medium — "teeth" |
| Lips (green) | 5 | 3 forward | Fastest — "lips" |

**Bullish** = lips > teeth > jaw (lines fanned out upward)  
**Sleeping** = lines tangled together (no clear trend)  
**Zone** = price area between jaw and lips

### Progressive Scanning (Incremental Mode)

Full warmup fetches 300+ candles per TF. Progressive mode reduces this to 20 candles by seeding the SMMA computation from stored values:

```
First scan (full):
  - Fetch 300+ candles
  - Compute SMMA from scratch
  - Store final SMMA state: {jaw_smma, teeth_smma, lips_smma, last_ha_open, last_ha_close}

Subsequent scans (progressive):
  - Fetch only 20 candles
  - Seed SMMA from stored state
  - Continue computation from that point
  - Update stored state with new final values
```

This is 3–4× cheaper per rescan.

### Exchange Fallback (MEXC)

When Binance or HyperLiquid fails to return candle data for a pair:

1. The primary exchange fetch raises an exception
2. Python immediately retries with MEXC (`exchange="mexc"`) using standard `BTC/USDT` pair format
3. The exchange that actually succeeded is stored per-TF in `tf_data_json[tf]["exchange"]`
4. Progressive scans read this stored exchange and use it directly, avoiding hitting Binance again for pairs where it's known to fail

---

## 7. Exchange Integrations

### Binance
- **Candle data**: `GET /api/v3/klines` via CCXT
- **Tracking**: `GET /api/v3/klines?symbol=BTCUSDT&interval=5m&limit=2`
  - Returns `[last_closed_candle, current_forming_candle]`
  - Uses index `[0]`: `high = kline[2]`, `low = kline[3]`
- **Pair format**: `BTCUSDT` (no slash)
- **Config**: `BINANCE_API_URL` env var (default: `https://api.binance.com`)

### HyperLiquid
- **Candle data**: `POST /info {"type": "candleSnapshot", ...}` via CCXT
  - CCXT requires `walletAddress = "0x000..."` for public endpoints
  - Pair format: `BTC/USDC:USDC` (perpetuals)
- **Tracking**: Same POST endpoint with `interval=5m`, takes second-to-last candle
  - `high = candle['h']`, `low = candle['l']`
- **Config**: `HYPERLIQUID_API_URL` env var (default: `https://api.hyperliquid.xyz`)

### MEXC (Fallback)
- **Purpose**: Fallback when Binance/HyperLiquid returns no data for a pair (e.g., geo-restricted or delisted)
- **API**: Binance-compatible REST (`GET /api/v3/klines`)
- **Pair format**: Standard `BTC/USDT` (same as Binance)
- **TradingView link**: Pairs using MEXC fallback display as `MEXC:AKEUSDT` on TradingView

### TradingView Links
The screener UI links each pair to TradingView:
- HyperLiquid pairs → `https://app.hyperliquid.xyz/trade/{BASE}`
- MEXC-fallback pairs → `https://www.tradingview.com/chart/?symbol=MEXC:{PAIRNOSLASH}`
- Binance pairs → `https://www.tradingview.com/chart/?symbol=BINANCE:{PAIRNOSLASH}`

---

## 8. Discord Notifications

Configure by setting `DISCORD_WEBHOOK_URL` in `.env`. If not set, all notifications are silently skipped.

### Signal Found (green embed)
Sent when Python scanner detects a new signal.

```
🟢 Signal Found — BTC/USDT
Exchange: binance  |  Timeframe: 1H  |  Type: Pullback

Entry:  70,500.00  |  Risk: 1.23%
SL:     69,635.00
TP1:    71,797.50  (+1.84%)
TP2:    73,095.00  (+3.68%)

Score: 82.450  |  Confluence: 15M 1H 4H
```

### Signal Closed — TP (gold embed)
```
🏆 TP2 Hit — BTC/USDT
Exit Price: 73,095.00
PnL: +3.68%  |  R: +3.0R
```

### Signal Closed — SL (red embed)
```
🔴 SL Hit — BTC/USDT
Exit Price: 69,635.00
PnL: -1.23%  |  R: -1.0R
```

---

## 9. Scheduled Jobs

Defined in `routes/console.php`.

| Command | Frequency | Log File | Purpose |
|---------|-----------|----------|---------|
| `trading:scan-signals` | Every 15 min | `storage/logs/scanner.log` | Re-scan active pairs for new signals |
| `trading:track-signals` | Every 5 min | `storage/logs/tracker.log` | Check TP/SL hit on active signals |

### `trading:scan-signals` detail

1. Finds all `screener_runs` with `status=completed` and `started_at >= 24 hours ago`
2. For each run, iterates qualified results
3. Skips pairs that already have an `active` or `tp1_hit` signal
4. Deletes stale `signal_scans` (from prior 15-min cycles) that have no signals attached
5. Dispatches `SignalScanPairJob` with `progressive=true` for each pair

The 24-hour window means pairs stop being rescanned one day after the screener run that found them.

### `trading:track-signals` detail

1. Loads all signals in `['active', 'tp1_hit']` status
2. Groups by exchange, fetches last closed 5m candle for all pairs (concurrent via `Http::pool`)
3. Applies TP/SL logic (TP wins if both breach same candle — price reached TP before reversing to SL)
4. Calls `SignalTrackerService::close()` for any hits

---

## 10. Configuration Reference

### Environment Variables (`.env`)

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | Trading Dashboard | Laravel app name |
| `APP_ENV` | local | `local` or `production` |
| `DB_CONNECTION` | mysql | |
| `DB_HOST` | mysql | Docker service name |
| `DB_DATABASE` | crypto_signals | |
| `DB_USERNAME` | root | |
| `DB_PASSWORD` | | |
| `QUEUE_CONNECTION` | database | Queue driver |
| `BINANCE_API_URL` | https://api.binance.com | Binance REST base URL |
| `HYPERLIQUID_API_URL` | https://api.hyperliquid.xyz | HyperLiquid REST base URL |
| `DISCORD_WEBHOOK_URL` | _(empty)_ | Discord webhook — notifications disabled if not set |

### Trading Config (`config/trading.php`)

```php
exchanges:
  binance:
    min_volume:      1_000_000   (1M USD 1H volume)
    min_rvol:        0.4         (relative volume threshold)
    min_bullish_tfs: 3           (minimum bullish timeframes)

  hyperliquid:
    min_volume:      100_000     (100K USD 1H volume)
    min_rvol:        0.4
    min_bullish_tfs: 3

scanner:
  top:              20           (top N pairs to scan)
  lookback:         1            (candles back to check)
```

### Screener Scoring Weights

| Timeframe | Weight |
|-----------|--------|
| 5M | 0.05 |
| 15M | 0.10 |
| 1H | 0.20 |
| 4H | 0.25 |
| 8H | 0.15 |
| 12H | 0.10 |
| 1D | 0.15 |

4H has the highest weight — the screener favours pairs with strong 4H momentum.

---

## 11. Deployment & Setup

### Prerequisites
- Docker + Docker Compose
- PHP 8.4, Composer
- Python 3.10+, pip
- Node.js + npm (for frontend assets)

### Docker Services
| Service | Description |
|---------|-------------|
| `trading-dashboard-laravel.test-1` | Laravel app container |
| `trading-dashboard-mysql-1` | MySQL 8 database |
| `app` | Python scanner container |
| `redis` | Queue + cache |

### Initial Setup

```bash
# Install PHP dependencies
composer install

# Install frontend assets
npm install && npm run build

# Copy environment file
cp .env.example .env
php artisan key:generate

# Run database migrations
php artisan migrate

# Start queue worker (processes SignalScanPairJob)
php artisan queue:work

# Start scheduler (runs every minute, fires commands on schedule)
php artisan schedule:work
```

### Python Setup

```bash
cd python
pip install -r requirements.txt
```

Python reads `../.env` directly for database credentials (via `db/connection.py`).

### Running Manually

```bash
# Check a specific pair directly (no screener needed)
docker exec app python3 run_scanner.py --pair BTC/USDT --tf 1H --exchange binance

# Scan all qualified pairs from a screener run
docker exec app python3 run_scanner.py --screener-run-id 5 --exchange binance

# Run screener from a local JSON file
docker exec app python3 run_scanner.py --file data.json --exchange binance --top 20

# Check screener output only (no signal scanning)
docker exec app python3 run_screener.py --file data.json

# Force-run signal tracking immediately
docker exec trading-dashboard-laravel.test-1 php artisan trading:track-signals

# Force-run progressive rescan immediately
docker exec trading-dashboard-laravel.test-1 php artisan trading:scan-signals
```

### Log Files

| File | Contents |
|------|----------|
| `storage/logs/laravel.log` | Laravel application errors |
| `storage/logs/scanner.log` | Output from every trading:scan-signals run |
| `storage/logs/tracker.log` | Output from every trading:track-signals run |

### Typical Workflow

1. Download ticker data from Orion Terminal as JSON
2. Go to `/run`, upload the JSON, configure thresholds, submit
3. Laravel runs screener (instant), dispatches scanner jobs (queued)
4. Monitor `/screener` to see qualified pairs and scores
5. Monitor `/signals` to see detected signals (typically ready in 2–5 minutes)
6. Signals are auto-tracked every 5 minutes; Discord notified on TP/SL hit
7. New pairs rescanned every 15 minutes for the next 24 hours
8. After 24 hours, pairs expire from rescanning (upload fresh data to restart)
