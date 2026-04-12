# Trading Dashboard — Modular Python Pipeline + MySQL + Laravel UI

## Overview

Restructure the monolithic Python trading scripts into a clean modular package, log all activity to MySQL, and build a Laravel UI that displays everything. Laravel manages the Python pipeline via Artisan commands and the scheduler.

| | |
|---|---|
| **Python scripts** | `/home/sufian/Projects/trading-dashboard/python/` |
| **Laravel project** | `/home/sufian/Projects/trading-dashboard/` |
| **Database** | MySQL — `trading_dashboard` |

---

## Phase 1 — Python Modularisation

**Goal:** Split three monolithic scripts into focused packages. Zero behaviour change — same CLI flags, same output, same signal logic.

### Problem

| File | Lines | Mixed concerns |
|---|---|---|
| `screener.py` | 487 | models + HTTP I/O + scoring logic + display + CLI |
| `signal_scanner.py` | 513 | config + candle fetching + signal checking + display + CLI |
| `main.py` | 319 | argparse + all 8 cmd_* command functions inline |

### Final Directory Layout

```
python/
│
├── screener/                        ← replaces screener.py (deleted)
│   ├── __init__.py                  # re-exports all public names (backward compat)
│   ├── models.py                    # TFSnapshot, TickerScore, TIMEFRAMES, ALLIGATOR_TF_RULES
│   ├── loader.py                    # SCREENER_URL, HEADERS, fetch_screener_data(), load_screener_data()
│   ├── scoring.py                   # filter_and_score(), _recommend_alligator_tf(), _safe()
│   ├── display.py                   # print_results(), _fmt_vol(), _chg_str(), _bull_marker()
│   └── runner.py                    # main() — argparse CLI + JSON export
│
├── scanner/                         ← replaces signal_scanner.py internals
│   ├── __init__.py                  # re-exports check_signal, check_signal_direct, print_signals
│   ├── config.py                    # TF_CONFIG dict, TF_WARMUP_CANDLES dict
│   ├── fetcher.py                   # _start_date_for_tf(), _pair_to_ccxt()
│   ├── checker.py                   # check_signal(), check_signal_direct(), _print_diagnostic()
│   ├── display.py                   # print_signals()
│   └── runner.py                    # main() — argparse CLI, orchestrates screener → scanner
│
├── cli/                             ← extracted from main.py
│   ├── __init__.py
│   ├── parser.py                    # build_parser() — all argparse subcommand definitions
│   └── commands/
│       ├── __init__.py
│       ├── fetch.py                 # cmd_fetch()
│       ├── data.py                  # cmd_list_data(), cmd_list_strategies()
│       ├── backtest.py              # cmd_backtest(), cmd_backtest_all()
│       ├── results.py               # cmd_show(), cmd_compare(), cmd_tag()
│       └── sweep.py                 # cmd_sweep()
│
├── run_screener.py                  # 3-line entrypoint: from screener.runner import main; main()
├── run_scanner.py                   # 3-line entrypoint: from scanner.runner import main; main()
├── main.py                          # MODIFIED → pure 30-line argparse router
└── signal_scanner.py                # MODIFIED → 4-line backward-compat shim
```

### Module Responsibility Map

| Module | Single responsibility |
|---|---|
| `screener/models.py` | Data structures only — TickerScore, TFSnapshot, TIMEFRAMES, ALLIGATOR_TF_RULES |
| `screener/loader.py` | HTTP fetch + file load — all I/O for screener data |
| `screener/scoring.py` | Score, filter, rank tickers — pure business logic, no I/O |
| `screener/display.py` | Render screener results table to stdout |
| `screener/runner.py` | argparse CLI + JSON export orchestration |
| `scanner/config.py` | TF → ccxt mapping constants — no logic |
| `scanner/fetcher.py` | Pair normalisation + date utilities — no business logic |
| `scanner/checker.py` | Signal evaluation: fetch candles → compute indicators → call strategy.signal() |
| `scanner/display.py` | Render signals table to stdout |
| `scanner/runner.py` | argparse CLI + screener→scanner pipeline orchestration |
| `cli/parser.py` | Build the argparse parser — all subcommand definitions |
| `cli/commands/*.py` | One file per command group — thin orchestrators calling engine modules |

### Precise Source Mapping

#### `screener.py` split

| New file | Source lines | Content |
|---|---|---|
| `screener/models.py` | 41–102 | `TIMEFRAMES`, `ALLIGATOR_TF_RULES`, `TFSnapshot`, `TickerScore` |
| `screener/loader.py` | 108–162 | `SCREENER_URL`, `HEADERS`, `fetch_screener_data()`, `load_screener_data()` |
| `screener/scoring.py` | 169–313 | `_MIN_VOLA_BULLISH`, `_safe()`, `_recommend_alligator_tf()`, `filter_and_score()` |
| `screener/display.py` | 320–388 | `_fmt_vol()`, `_chg_str()`, `_bull_marker()`, `print_results()` |
| `screener/runner.py` | 395–486 | `main()` with argparse + JSON export |

#### `signal_scanner.py` split

| New file | Source lines | Content |
|---|---|---|
| `scanner/config.py` | 53–84 | `TF_CONFIG`, `TF_WARMUP_CANDLES` |
| `scanner/fetcher.py` | 91–105 | `_start_date_for_tf()`, `_pair_to_ccxt()` |
| `scanner/checker.py` | 112–366 | `_check()`, `_print_diagnostic()`, `check_signal()`, `check_signal_direct()` |
| `scanner/display.py` | 373–402 | `print_signals()` |
| `scanner/runner.py` | 409–513 | `main()` with full argparse CLI |

#### `main.py` split

| New file | Source lines | Content |
|---|---|---|
| `cli/commands/fetch.py` | 14–30 | `cmd_fetch()` |
| `cli/commands/data.py` | 33–58 | `cmd_list_data()`, `cmd_list_strategies()` |
| `cli/commands/backtest.py` | 61–116 | `cmd_backtest()`, `cmd_backtest_all()` |
| `cli/commands/results.py` | 118–159, 235–237 | `cmd_show()`, `cmd_compare()`, `cmd_tag()` |
| `cli/commands/sweep.py` | 161–232 | `cmd_sweep()` |
| `cli/parser.py` | 240–288 | `build_parser()` — all argparse setup |

### Key Contracts

#### `screener/__init__.py` — backward compat for all existing imports
```python
from screener.models import TickerScore, TFSnapshot, TIMEFRAMES, ALLIGATOR_TF_RULES
from screener.loader import SCREENER_URL, fetch_screener_data, load_screener_data
from screener.scoring import filter_and_score
from screener.display import print_results

__all__ = [
    "TickerScore", "TFSnapshot", "TIMEFRAMES", "ALLIGATOR_TF_RULES",
    "SCREENER_URL", "fetch_screener_data", "load_screener_data",
    "filter_and_score", "print_results",
]
```

#### `scanner/__init__.py`
```python
from scanner.checker import check_signal, check_signal_direct
from scanner.display import print_signals

__all__ = ["check_signal", "check_signal_direct", "print_signals"]
```

#### `signal_scanner.py` → backward-compat shim
```python
# signal_scanner.py — legacy entrypoint, kept for backward compatibility
from scanner import check_signal, check_signal_direct, print_signals  # noqa
from scanner.runner import main

if __name__ == "__main__":
    main()
```

#### `main.py` after refactor
```python
#!/usr/bin/env python3
from cli.parser import build_parser
from cli.commands import fetch, data, backtest, results, sweep
from db import init_db

COMMAND_MAP = {
    "fetch":           fetch.cmd_fetch,
    "list-data":       data.cmd_list_data,
    "list-strategies": data.cmd_list_strategies,
    "backtest":        backtest.cmd_backtest,
    "backtest-all":    backtest.cmd_backtest_all,
    "show":            results.cmd_show,
    "compare":         results.cmd_compare,
    "sweep":           sweep.cmd_sweep,
    "tag":             results.cmd_tag,
}

def main():
    parser = build_parser()
    args = parser.parse_args()
    if not args.command:
        parser.print_help()
        return
    init_db()
    COMMAND_MAP[args.command](args)

if __name__ == "__main__":
    main()
```

### Files Deleted
- `python/screener.py` — **must be deleted before creating `screener/` package**.
  Python resolves `import screener` to `.py` before a directory — the package would be silently shadowed.

### Unchanged
`strategies/`, `indicators/`, `engine/`, `data/`, `agents/`, `models.py`, `config.py`, `db.py`

### CLI Interfaces (unchanged)

```bash
# Screener
python run_screener.py --file data.json --top 20 --min-bullish-tfs 3
python run_screener.py --file data.json --output ranked.json

# Scanner
python run_scanner.py --file data.json --top 20 --exchange mexc --lookback 2 --verbose
python run_scanner.py --pair BTC/USDT --tf 1H --exchange mexc    # direct mode
python signal_scanner.py --file data.json --top 10               # legacy shim still works

# Backtesting
python main.py fetch BTC/USDT 1h --days 365
python main.py backtest alligator_v4 BTC/USDT 1h
python main.py sweep alligator_v4 BTC/USDT 1h --param adx_threshold=15,30,5
python main.py show <run_id>
python main.py compare <run_id_1> <run_id_2>
```

### Verification

```bash
cd /home/sufian/Projects/trading-dashboard/python

python run_screener.py --file data.json --top 10
# Must produce identical output to: python screener.py --file data.json --top 10

python run_scanner.py --file data.json --top 5 --exchange mexc
# Must produce identical output to: python signal_scanner.py --file data.json --top 5 --exchange mexc

python signal_scanner.py --file data.json --top 5 --exchange mexc
# Legacy shim must still work

python main.py list-data
python main.py list-strategies
python main.py backtest alligator_v4 BTC/USDT 1h
```

---

## Phase 2 — Database Integration

**Goal:** Every screener run, every pair evaluated, every signal scan, every signal found is logged to MySQL. No signal logic changes.

### New Module

```
python/
└── db/
    ├── __init__.py          # re-exports all repository functions
    ├── connection.py        # reads DB creds from ../../.env, returns pymysql connection
    └── repository.py        # all INSERT/UPDATE/SELECT functions for all 4 tables
```

`connection.py` reads `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from Laravel's `.env` (shared).

### Database Tables (MySQL — `trading_dashboard`)

#### `screener_runs`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK auto | |
| data_source | varchar(100) | `orion_file`, `orion_live` |
| total_scanned | int | all tickers in input |
| total_matched | int | tickers passing all filters |
| filters_json | json | min_change, min_vol, min_rvol, min_bullish_tfs, top_n |
| status | enum | `running`, `completed`, `failed` |
| error_message | text | nullable |
| started_at | datetime | |
| finished_at | datetime | nullable |
| created_at | timestamp | |

#### `screener_results`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK auto | |
| screener_run_id | bigint FK | |
| symbol | varchar(30) | `BTCUSDT` |
| pair | varchar(20) | `BTC/USDT` |
| price | decimal(20,8) | |
| rvol | decimal(10,4) | |
| score | decimal(10,6) | |
| alligator_tf | varchar(5) | `15M`, `1H`, `4H`, `1D`, or null |
| bullish_count | tinyint | |
| confluence | varchar(50) | e.g. `15M 1H 4H` |
| qualified | tinyint(1) | 1 = passed all filters |
| disqualify_reason | varchar(100) | `low_volume`, `low_rvol`, `btc_corr`, `bullish_tfs` |
| tf_data_json | json | full TFSnapshot per TF |
| created_at | timestamp | |

#### `signal_scans`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK auto | |
| screener_run_id | bigint FK | |
| screener_result_id | bigint FK | nullable (direct scan mode) |
| pair | varchar(20) | |
| timeframe | varchar(5) | |
| exchange | varchar(30) | |
| strategy | varchar(30) | `cwt`, `alligator_v4` |
| candles_fetched | int | |
| status | enum | `scanned`, `skipped`, `error` |
| error_message | text | nullable |
| created_at | timestamp | |

#### `signals`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK auto | |
| signal_scan_id | bigint FK | |
| pair | varchar(20) | |
| timeframe | varchar(5) | |
| strategy | varchar(30) | |
| entry_type | varchar(20) | `Pullback`, `Awakening` |
| entry_price | decimal(20,8) | |
| sl_price | decimal(20,8) | |
| tp1_price | decimal(20,8) | |
| tp2_price | decimal(20,8) | |
| risk_pct | decimal(8,4) | (entry - sl) / entry × 100 |
| candle_time | datetime | signal candle close time |
| candles_ago | tinyint | 1 = just closed |
| screener_score | decimal(10,6) | |
| confluence | varchar(50) | |
| conditions_json | json | all PASS/FAIL + raw indicator values |
| status | enum | `active`, `tp1_hit`, `tp2_hit`, `sl_hit`, `expired` |
| created_at | timestamp | |

### DB Flow in `run_scanner.py`

```
1. repo.create_screener_run(params)            → run_id
2. for each ticker scanned:
     repo.create_screener_result(run_id, ...)  → result_id
3. for each top-N pair:
   a. repo.create_signal_scan(run_id, result_id, pair, tf)  → scan_id
   b. check_signal(ticker, ...)                → result dict
   c. repo.update_signal_scan(scan_id, status, candles_fetched)
   d. if signal found:
        repo.create_signal(scan_id, signal_data)
4. repo.complete_screener_run(run_id, totals)
```

### New Dependency
```bash
pip install pymysql python-dotenv
```

### Verification

```bash
python run_scanner.py --file data.json --top 10
mysql -u root trading_dashboard -e "SELECT * FROM screener_runs ORDER BY id DESC LIMIT 1\G"
mysql -u root trading_dashboard -e "SELECT pair, status, entry_price FROM signals ORDER BY id DESC LIMIT 5;"
```

---

## Phase 3 — Laravel Foundation

**Goal:** Laravel owns the DB schema via migrations, has Eloquent models, and triggers Python scripts via Artisan commands. Scheduler runs the scanner every 15 minutes.

### Migrations

```
database/migrations/
├── ..._create_screener_runs_table.php
├── ..._create_screener_results_table.php
├── ..._create_signal_scans_table.php
└── ..._create_signals_table.php
```

Schema matches Phase 2 tables exactly.

### Eloquent Models

```
app/Models/
├── ScreenerRun.php       # hasMany: ScreenerResult, SignalScan
├── ScreenerResult.php    # belongsTo: ScreenerRun
├── SignalScan.php        # belongsTo: ScreenerRun; hasMany: Signal
└── Signal.php            # belongsTo: SignalScan
```

### Artisan Commands

```
app/Console/Commands/
├── RunScreener.php    # php artisan trading:run-screener --file=data.json
└── RunScanner.php     # php artisan trading:run-scanner --top=20 --exchange=mexc
```

Each command:
1. Resolves Python path via `base_path('python')`
2. Spawns subprocess: `python3 run_screener.py` or `python3 run_scanner.py` with forwarded args
3. Streams stdout line-by-line to console
4. Exits with Python's exit code

### Scheduler (`routes/console.php`)

```php
// Scanner runs every 15 minutes automatically
Schedule::command('trading:run-scanner --top=20 --exchange=mexc')
    ->everyFifteenMinutes()
    ->appendOutputTo(storage_path('logs/scanner.log'));
```

Screener runs manually only (user uploads `data.json` via UI).

### Verification

```bash
php artisan migrate
php artisan trading:run-scanner --top=5 --file=python/data.json
php artisan schedule:list    # must show run-scanner every 15 min
```

---

## Phase 4 — Laravel UI

**Goal:** Clean read-only dashboard over the MySQL tables.

### Routes & Pages

| Route | Description |
|---|---|
| `GET /` | Dashboard — latest run summary, active signal count, recent signals |
| `GET /screener` | Latest screener run — ranked pairs with TF confluence badges |
| `GET /screener/history` | All screener runs — timestamp, counts, status |
| `GET /signals` | All signals — filterable by pair, TF, strategy, date |
| `GET /signals/{id}` | Signal detail — entry/SL/TP + full PASS/FAIL conditions breakdown |
| `GET /scans` | All signal scans — per-pair scan history, status |

### Key UI Elements

- **Screener table:** pair, price, alligator TF, confluence badges (green if bullish), score, rvol
- **Signals table:** pair, TF, strategy, entry type, entry/SL/TP prices, risk%, candle time, age
- **Signal detail:** `conditions_json` rendered as PASS (green) / FAIL (red) badge table + raw values
- **Status badges:** `active`=blue, `tp1_hit`=yellow, `tp2_hit`=green, `sl_hit`=red, `expired`=gray
- **Auto-refresh:** `<meta http-equiv="refresh" content="60">` on dashboard

### Stack

- Blade + Tailwind CSS (already configured)
- No Livewire (simple read-only views don't need it)
- Alpine.js for lightweight interactivity if needed

### Verification

```bash
php artisan serve
# Open http://localhost:8000
# Run: php artisan trading:run-scanner --top=5 --file=python/data.json
# Verify all pages load with real data
```

---

## Phase 5 — HyperLiquid Execution (Deferred)

When ready:
- `python/executor/hyperliquid.py` — order placement via `hyperliquid-python-sdk`
- `live_trades` table added to DB schema
- `--execute` flag on `run_scanner.py` enables live orders
- Laravel `LiveTrade` model + trades UI page

---

## Critical Files Summary

| Phase | Action | File(s) |
|---|---|---|
| 1 | Create | `python/screener/__init__.py`, `models.py`, `loader.py`, `scoring.py`, `display.py`, `runner.py` |
| 1 | Create | `python/scanner/__init__.py`, `config.py`, `fetcher.py`, `checker.py`, `display.py`, `runner.py` |
| 1 | Create | `python/cli/__init__.py`, `parser.py`, `commands/__init__.py`, `commands/fetch.py`, `commands/data.py`, `commands/backtest.py`, `commands/results.py`, `commands/sweep.py` |
| 1 | Create | `python/run_screener.py`, `python/run_scanner.py` |
| 1 | Modify | `python/signal_scanner.py` → 4-line shim |
| 1 | Modify | `python/main.py` → 30-line router |
| 1 | **Delete** | `python/screener.py` |
| 2 | Create | `python/db/__init__.py`, `db/connection.py`, `db/repository.py` |
| 2 | Modify | `python/run_screener.py`, `python/run_scanner.py` → add DB logging calls |
| 3 | Create | 4 migration files in `database/migrations/` |
| 3 | Create | `app/Models/` — 4 Eloquent models |
| 3 | Create | `app/Console/Commands/RunScreener.php`, `RunScanner.php` |
| 3 | Modify | `routes/console.php` — add scheduler |
| 4 | Create | `resources/views/` — dashboard, screener, signals, signal detail, scans |
| 4 | Create | `app/Http/Controllers/` — dashboard, screener, signals controllers |
| 4 | Modify | `routes/web.php` — add all routes |

## Never Touch

`strategies/`, `indicators/`, `engine/`, `data/`, `agents/`, `models.py`, `config.py`, `db.py` (SQLite layer — separate from new MySQL `db/` package)
