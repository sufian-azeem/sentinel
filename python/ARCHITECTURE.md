# Crypto Backtesting Engine — Full System Architecture

## Context

The user is a crypto trader who needs to test strategies before deploying them with a live AI trading agent. The core problem is: **no strategy should touch real money until it has proven itself against historical data.**

This architecture covers two layers:
1. **Backtesting Engine (Parts 1–5)** — already fully specified in `BACKTESTER_SPEC_UPDATED.md`. Implements data fetching, storage, strategy execution, performance metrics, results persistence, and a CLI.
2. **Agent Layer (Part 6)** — NOT in the spec but required by the user. Claude-powered agents that autonomously run backtests, analyze results, and iteratively refine strategies using the engine as their oracle.

The implementing AI agent should build Part 1–5 first (fully testable in isolation), then Part 6 on top.

---

## System Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        AGENT LAYER (Part 6)                         │
│                                                                     │
│  ┌──────────────────┐   ┌──────────────────┐   ┌────────────────┐  │
│  │  StrategyAgent   │   │  RefinementAgent │   │  Orchestrator  │  │
│  │  (proposes new   │   │  (tunes params   │   │  (coordinates  │  │
│  │   strategies)    │   │   via sweep)     │   │   all agents)  │  │
│  └────────┬─────────┘   └────────┬─────────┘   └───────┬────────┘  │
│           │                      │                      │           │
│           └──────────────────────┴──────────────────────┘           │
│                                  │                                  │
│                     Claude API (anthropic SDK)                      │
│                   Tool calls → BacktestRunner API                   │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ Python API calls
┌───────────────────────────────▼─────────────────────────────────────┐
│                    BACKTESTING ENGINE (Parts 1–5)                   │
│                                                                     │
│  main.py (CLI)                                                      │
│     ├── data/fetcher.py ──────────────── ccxt → Exchange APIs       │
│     ├── data/storage.py ─────────────── Parquet files               │
│     ├── strategies/                                                 │
│     │   ├── base.py (BaseStrategy ABC)                              │
│     │   ├── loader.py (dynamic import)                              │
│     │   ├── rsi_reversion.py                                        │
│     │   ├── ma_crossover.py                                         │
│     │   └── configs/*.json                                          │
│     ├── engine/                                                     │
│     │   ├── clock.py (MarketClock)                                  │
│     │   ├── order.py (OrderManager)                                 │
│     │   ├── portfolio.py (Portfolio)                                │
│     │   ├── trade_ledger.py (TradeLedger)                           │
│     │   ├── backtester.py (Backtester.run())                        │
│     │   └── metrics.py (calculate_all())                            │
│     ├── db.py ─────────────────────────── SQLite                    │
│     └── results/ ──────────────────────── JSON + CSV               │
└─────────────────────────────────────────────────────────────────────┘
```

**Data flow (single backtest):**
```
Exchange API → ccxt → DataFrame → Parquet
                                     ↓
                              load_candles()
                                     ↓
                    strategy.indicators(df) → enriched df
                                     ↓
                    MarketClock iterates row-by-row
                                     ↓
              strategy.signal(row) → Signal → OrderManager
                                     ↓
                     Portfolio tracks positions + equity
                                     ↓
                     TradeLedger records closed trades
                                     ↓
                    metrics.calculate_all() → BacktestResult
                                     ↓
                    results/*.json + equity*.csv + SQLite
```

---

## Part 1–5: Backtesting Engine

> Full implementation details are in `BACKTESTER_SPEC_UPDATED.md`. This section summarises the key architectural decisions and highlights what the spec adds beyond a naive implementation.

### Complete File Manifest

```
backtesting-engine/
├── requirements.txt
├── config.py
├── models.py
├── db.py
├── main.py
│
├── data/
│   ├── __init__.py
│   ├── fetcher.py
│   ├── storage.py
│   └── candles/               ← auto-created at runtime
│
├── strategies/
│   ├── __init__.py
│   ├── base.py
│   ├── loader.py
│   ├── rsi_reversion.py
│   ├── ma_crossover.py
│   └── configs/
│       ├── rsi_reversion.json
│       └── ma_crossover.json
│
├── engine/
│   ├── __init__.py
│   ├── clock.py               ← MarketClock (spec § Architecture Improvements)
│   ├── order.py               ← OrderManager
│   ├── portfolio.py           ← Portfolio
│   ├── trade_ledger.py        ← TradeLedger (spec § Architecture Improvements)
│   ├── backtester.py          ← Backtester.run()
│   └── metrics.py             ← calculate_all() + 20 individual functions
│
├── results/                   ← auto-created at runtime
│
└── tests/
    ├── __init__.py
    ├── test_fetcher.py
    ├── test_storage.py
    ├── test_strategy.py
    ├── test_engine.py
    └── test_metrics.py
```

### Dependency / Import Rules (NO circular imports)

```
config.py          ← no internal imports
    ↓
models.py          ← imports config
    ↓
data/              ← imports config, models
    ↓
strategies/        ← imports models only (NEVER engine)
    ↓
engine/            ← imports config, models, strategies.base
    ↓
db.py              ← imports config, models
    ↓
main.py            ← imports everything
```

### Key Implementation Details

#### `config.py`
- `Settings(BaseSettings)` with `env_prefix = "BT_"`.
- One module-level singleton: `settings = Settings()`.
- All path defaults relative to `__file__`.
- Full field list in spec §3.

#### `models.py`
All Pydantic models — the contracts between every module. Eight classes:
- `ParamDef` — one tunable parameter with bounds (default/min/max/step)
- `RiskRules` — stop_loss_pct, take_profit_pct, max_position_pct, max_leverage, trailing_stop_pct
- `StrategyConfig` — loaded from JSON, defines identity + parameter space
- `Signal` — returned by `strategy.signal()`: action, strength (0–1), reason, order_type, limit_price
- `Order` — a trade order with fill details and fee
- `Position` — open position with unrealized PnL and trailing stop tracking fields
- `Trade` — closed round-trip with full PnL, fees, exit reason
- `BacktestResult` — complete output: trades, equity curve, all metrics
- Full field definitions in spec §4.

#### `data/fetcher.py`
- `fetch_candles(pair, timeframe, start_date, end_date, exchange)` → `pd.DataFrame`
  - Loops `exchange.fetch_ohlcv(limit=1000)` until end_date reached
  - Output columns: `timestamp, open, high, low, close, volume, quote_volume, trades_count`
  - Retry 3× on network errors (1s, 2s, 4s backoff)
- `detect_gaps(df, timeframe)` → `list[tuple[int,int]]`
- `get_exchange(name)` → `ccxt.Exchange`
- Internal `TIMEFRAME_MS` dict for all standard timeframes (see spec §5)

#### `data/storage.py`
- One Parquet file per pair/timeframe: `{BASE}_{QUOTE}_{TIMEFRAME}.parquet`
- `save_candles` merges with existing file (dedup by timestamp, sort asc)
- `load_candles` filters by start/end date
- `list_available()` returns metadata for all stored files
- Schema: 8 columns, `timestamp` is `int64` Unix ms

#### `strategies/base.py`
Four abstract methods every strategy must implement:
1. `name() -> str`
2. `default_params() -> dict[str, ParamDef]`
3. `indicators(df: pd.DataFrame) -> pd.DataFrame` — adds columns, does NOT drop/rename existing
4. `signal(row: pd.Series, position: Position | None) -> Signal` — ALWAYS returns Signal, never None

**Critical contract:** `signal()` only sees the current row. It never receives the full DataFrame.

#### `strategies/loader.py`
- `load_strategy(name, params)` — dynamic import via `importlib`, finds the one `BaseStrategy` subclass, merges params with JSON config
- `list_strategies()` — scans strategies dir, excludes `__init__.py`, `base.py`, `loader.py`
- `load_config(name)` → `StrategyConfig`

#### `engine/clock.py` — MarketClock
```python
class MarketClock:
    def __init__(self, candles: pd.DataFrame)
    def has_next(self) -> bool
    def next(self) -> pd.Series
    def current_index(self) -> int
```
The backtest loop **MUST use MarketClock**, not `df.iterrows()` directly. This enables future migration to event-driven / live trading.

#### `engine/trade_ledger.py` — TradeLedger
```python
class TradeLedger:
    def __init__(self)
    def record_trade(self, trade: Trade) -> None
    def all_trades(self) -> list[Trade]
    def trades_for_pair(self, pair: str) -> list[Trade]
```
- `portfolio.py` manages balances and positions.
- `trade_ledger.py` records trade history.
- `Backtester` coordinates both.

#### `engine/order.py` — OrderManager
- Market orders fill immediately at close price ± slippage
- Limit/Stop orders go into `pending_orders`, checked each candle via `process_pending_orders(candle)`
- Slippage: random uniform in `[0, slippage_pct]`; BUY pays more, SELL receives less
- Fee: `quantity × price × fee_rate`
- Full method list in spec §7.

#### `engine/portfolio.py` — Portfolio
- `open_position(order)` — deducts margin from balance, sets liquidation price for futures
- `close_position(position, exit_price, exit_time, reason)` — returns margin + PnL to balance, creates Trade
- `check_stops(candle, position)` — priority order: LIQUIDATION > STOP_LOSS > TRAILING_STOP > TAKE_PROFIT
- `calculate_position_size(signal, price)` — scales by `signal.strength` and `max_position_pct`
- `update_equity(price, timestamp)` — appends to equity_curve

#### `engine/backtester.py` — Backtester.run()
Six-step execution (full pseudocode in spec §7):
1. **Prepare data** — copy df, call `strategy.indicators()`, drop NaN rows, reset index
2. **Initialize portfolio** and TradeLedger
3. **Main loop via MarketClock:**
   - Check stops on open positions → close if triggered
   - Process pending limit orders
   - Get strategy signal for current row
   - Execute signal (open/close/hold)
   - Update equity snapshot
4. **Force-close** all remaining positions (`END_OF_DATA`)
5. **Calculate all metrics** via `metrics.calculate_all()`
6. **Build and return** `BacktestResult`

**Anti-lookahead rules (CRITICAL):**
- Strategy receives only current row, never full DataFrame
- Indicators computed once before loop (precomputation on full df is correct — strategies read the precomputed value on each row)
- NaN rows dropped before loop starts (no warmup NaN leakage)
- Market orders fill at current candle's close (same-candle fill is the engine's simplification of "next available price")

#### `engine/metrics.py`
All pure functions, no side effects. Complete list:
`total_return_pct`, `sharpe_ratio`, `sortino_ratio`, `max_drawdown`, `max_drawdown_duration`, `win_rate`, `profit_factor`, `expectancy`, `avg_trade_duration`, `calmar_ratio`, `recovery_factor`, `total_fees_paid`, `best_trade`, `worst_trade`, `avg_winner`, `avg_loser`, `max_consecutive_wins`, `max_consecutive_losses`, `long_short_ratio`

Master function: `calculate_all(trades, equity_curve, starting_capital, timeframe) -> dict[str, float]`

Annualization uses `PERIODS_PER_YEAR` dict — full mapping in spec §8.

#### `db.py`
Three SQLite tables: `strategies`, `backtest_runs`, `run_tags`
Key functions: `init_db()`, `save_run(result)`, `get_run(run_id)`, `list_runs(...)`, `compare_runs(run_ids)`, `tag_run(run_id, tag)`
Full schema in spec §9.

#### `main.py` CLI Commands

| Command | Usage |
|---------|-------|
| `fetch` | `python main.py fetch <pair> <timeframe> --days N [--exchange name]` |
| `list-data` | `python main.py list-data` |
| `list-strategies` | `python main.py list-strategies` |
| `backtest` | `python main.py backtest <strategy> <pair> <tf> [--capital N] [--param k=v ...]` |
| `backtest-all` | `python main.py backtest-all <strategy> [--capital N]` |
| `show` | `python main.py show <run_id>` |
| `compare` | `python main.py compare <run_id1> <run_id2> ...` |
| `sweep` | `python main.py sweep <strategy> <pair> <tf> --param name min max step ...` |
| `tag` | `python main.py tag <run_id> <tag>` |

Full behavior for each command in spec §10.

### Build Order (strict — each step testable before next)

| Step | Files | Gate |
|------|-------|------|
| 1 | `config.py`, `models.py` | Models serialize/deserialize round-trip |
| 2 | `data/fetcher.py`, `data/storage.py` | Fetch 30d BTC/USDT 1h, Parquet file on disk |
| 3 | `strategies/base.py`, `strategies/rsi_reversion.py`, JSON config | `indicators()` adds rsi col, `signal()` returns correct actions |
| 4 | `engine/clock.py`, `engine/trade_ledger.py`, `engine/order.py`, `engine/portfolio.py` | Synthetic BUY→SELL round-trip PnL matches hand calc |
| 5 | `engine/metrics.py` | All metrics correct on known inputs |
| 6 | `engine/backtester.py` | Full backtest on RSI strategy, non-NaN results |
| 7 | `db.py`, results file output | SQLite rows created, JSON + CSV saved and reloadable |
| 8 | `main.py` | Every CLI command works end-to-end |
| 9 | `strategies/ma_crossover.py`, `strategies/loader.py` | Second strategy dynamic-loads and backtests |
| 10 | `tests/` | `pytest tests/` passes with 0 failures |
| **11** | **Part 6: Agent Layer** | **Part 1–10 complete and all tests passing** |

---

## Part 6: Agent Layer

> Build ONLY after Parts 1–10 are fully working and tested.

### New Files

```
backtesting-engine/
├── agents/
│   ├── __init__.py
│   ├── runner.py              ← BacktestRunner (Python API wrapper)
│   ├── tools.py               ← Claude tool definitions
│   ├── strategy_agent.py      ← Proposes and writes new strategies
│   ├── refinement_agent.py    ← Parameter optimization via sweep
│   └── orchestrator.py        ← Coordinates multi-agent workflow
│
└── agent_main.py              ← CLI entry point for agent commands
```

### `agents/runner.py` — BacktestRunner

A thin Python API that wraps the engine so agents can call it programmatically (no subprocess, no CLI parsing). **This is the only interface agents use to interact with the engine.**

```python
class BacktestRunner:
    def run_backtest(
        self,
        strategy_name: str,
        pair: str,
        timeframe: str,
        params: dict | None = None,
        capital: float = 10_000.0
    ) -> BacktestResult

    def run_sweep(
        self,
        strategy_name: str,
        pair: str,
        timeframe: str,
        param_ranges: dict[str, tuple[float, float, float]]  # name: (min, max, step)
    ) -> list[BacktestResult]  # sorted by Sharpe ratio descending

    def get_run(self, run_id: str) -> BacktestResult | None

    def compare_runs(self, run_ids: list[str]) -> pd.DataFrame

    def list_strategies(self) -> list[dict]

    def list_data(self) -> list[dict]
```

### `agents/tools.py` — Claude Tool Definitions

Defines tools for Claude to call via the Anthropic SDK tool-use API. All tool handlers call `BacktestRunner` methods.

```python
AGENT_TOOLS = [
    {
        "name": "run_backtest",
        "description": "Run a single backtest for a strategy on a pair/timeframe.",
        "input_schema": {
            "type": "object",
            "properties": {
                "strategy_name": {"type": "string"},
                "pair": {"type": "string"},
                "timeframe": {"type": "string"},
                "params": {"type": "object", "description": "Optional parameter overrides"},
                "capital": {"type": "number", "default": 10000.0}
            },
            "required": ["strategy_name", "pair", "timeframe"]
        }
    },
    {
        "name": "run_sweep",
        "description": "Grid search over parameter ranges. Returns top results sorted by Sharpe.",
        "input_schema": {
            "type": "object",
            "properties": {
                "strategy_name": {"type": "string"},
                "pair": {"type": "string"},
                "timeframe": {"type": "string"},
                "param_ranges": {
                    "type": "object",
                    "description": "Dict of param_name -> {min, max, step}"
                }
            },
            "required": ["strategy_name", "pair", "timeframe", "param_ranges"]
        }
    },
    {
        "name": "compare_runs",
        "description": "Compare multiple backtest runs side-by-side by run_id.",
        "input_schema": {
            "type": "object",
            "properties": {
                "run_ids": {"type": "array", "items": {"type": "string"}}
            },
            "required": ["run_ids"]
        }
    },
    {
        "name": "get_run_details",
        "description": "Get full metrics and trade summary for a specific run_id.",
        "input_schema": {
            "type": "object",
            "properties": {
                "run_id": {"type": "string"}
            },
            "required": ["run_id"]
        }
    },
    {
        "name": "list_strategies",
        "description": "List all available strategy files and their config status.",
        "input_schema": {"type": "object", "properties": {}}
    },
    {
        "name": "write_strategy",
        "description": "Write a new strategy Python file and its JSON config to the strategies/ directory.",
        "input_schema": {
            "type": "object",
            "properties": {
                "strategy_name": {
                    "type": "string",
                    "description": "Snake_case filename without .py, e.g. 'bollinger_reversion'"
                },
                "python_code": {"type": "string"},
                "config_json": {"type": "object"}
            },
            "required": ["strategy_name", "python_code", "config_json"]
        }
    }
]
```

**`write_strategy` security constraint:** Only writes to `strategies/` directory. Validates filename matches `^[a-z][a-z0-9_]+$`. Raises error if name matches `base`, `loader`, `__init__`.

### `agents/refinement_agent.py` — Parameter Refinement Agent

**Purpose:** Given a strategy and target pair/timeframe, find the best parameters autonomously.

**Method signature:**
```python
def run(
    strategy_name: str,
    pair: str,
    timeframe: str,
    iterations: int = 3
) -> dict:
    """
    Returns:
    {
        "best_params": dict,
        "baseline_sharpe": float,
        "final_sharpe": float,
        "improvement_pct": float,
        "run_ids": list[str],
        "summary": str         # Claude's explanation
    }
    """
```

**Workflow:**
1. Run baseline backtest at default params
2. Ask Claude to identify the weakest metric and suggest parameter sweep ranges
3. Run sweep on suggested ranges
4. Ask Claude to analyze sweep results and select best config
5. Run final validation backtest on selected params
6. Return improvement summary

**System prompt:**
```
You are a quantitative trading strategy optimizer. Your goal is to improve
risk-adjusted returns (Sharpe ratio) while maintaining:
- Max drawdown below 25%
- At least 50 trades (statistical validity)
- Profit factor > 1.0

Rules:
1. Start with baseline backtest at default params
2. Identify the single weakest metric first
3. Run focused sweeps (1-2 parameters at a time, not everything)
4. Validate final params before reporting
5. Flag overfit if: Sharpe > 3.0 on periods < 6 months, or < 30 trades

Available tools: run_backtest, run_sweep, compare_runs, get_run_details
```

**Overfit detection:** After refinement, if best Sharpe is more than `settings.OVERFIT_THRESHOLD` (40%) above baseline, flag as potentially overfit.

### `agents/strategy_agent.py` — Strategy Creation Agent

**Purpose:** Generate new trading strategies from a high-level description.

**Method signature:**
```python
def run(
    strategy_type: str,         # "mean_reversion" | "momentum" | "breakout" | "custom: <desc>"
    pairs: list[str],
    timeframe: str
) -> dict:
    """
    Returns:
    {
        "strategy_name": str,
        "validation_result": BacktestResult,
        "file_path": str,
        "notes": str
    }
    """
```

**Workflow:**
1. Ask Claude to design the strategy (indicators, entry/exit logic, risk rules)
2. Write the Python file + JSON config using `write_strategy` tool
3. Run validation backtest — catch syntax errors, zero-trade results, NaN metrics
4. If validation fails: show Claude the error, ask it to fix, retry up to 3 times
5. Return result (does NOT auto-refine — caller decides whether to run RefinementAgent)

**System prompt:**
```
You are a quantitative trading strategy developer for crypto markets.
You write Python strategy classes that inherit from BaseStrategy.

The BaseStrategy interface (strategies/base.py):
    def name(self) -> str
    def default_params(self) -> dict[str, ParamDef]
    def indicators(self, df: pd.DataFrame) -> pd.DataFrame
    def signal(self, row: pd.Series, position: Position | None) -> Signal

Rules you MUST follow:
- Import only: from models import Signal, SignalAction, ParamDef, Position
- Import ta for indicators: import ta
- signal() must ALWAYS return a Signal object — never None, never raise
- Use self.params["param_name"] for ALL configurable values
- Define all params in default_params() with realistic min/max bounds
- Never access future data — only use values from the current `row`
- indicators() adds new columns to df and returns it — never drops existing columns

Signal actions: SignalAction.BUY, SELL, CLOSE, HOLD
Signal strength: 0.0 to 1.0 (used for position sizing)

Available ta indicators: RSI, MACD, Bollinger Bands, EMA, SMA, ADX,
Stochastic, ATR, Williams %R, OBV, and more. See ta-lib docs.
```

### `agents/orchestrator.py` — Orchestrator Agent

**Purpose:** High-level coordination across multiple strategies, pairs, and timeframes.

**Method signature:**
```python
def run_full_cycle(
    pairs: list[str],
    timeframes: list[str],
    strategy_names: list[str] | None = None,  # None = discover existing + generate new
    generate_new: int = 0,                     # number of new strategies to generate
    cycles: int = 1
) -> dict:
    """
    Returns full report with ranking and recommendations.
    Saves results/agent_report_{timestamp}.json
    """
```

**Workflow per cycle:**
1. **Discovery** — `list_strategies()`. If `generate_new > 0`, call StrategyAgent for each.
2. **Evaluation** — Run baseline backtest for each strategy × pair × timeframe.
3. **Refinement** — Run RefinementAgent on each strategy.
4. **Ranking** — Ask Claude to rank all strategies using composite score (below).
5. **Report** — Save `results/agent_report_{timestamp}.json` with full ranking + explanation.

**Composite ranking score:**
```python
score = (
    sharpe_ratio          * 0.35 +
    (1 - max_drawdown_pct / 100) * 0.25 +
    win_rate_pct / 100    * 0.20 +
    min(profit_factor / 5, 1.0) * 0.10 +    # capped at 5
    min(total_trades / 200, 1.0) * 0.10     # rewards up to 200 trades
)
```

### `agent_main.py` CLI

```bash
# Refine an existing strategy's parameters
python agent_main.py refine <strategy_name> <pair> <timeframe> [--iterations N]

# Generate and validate a new strategy
python agent_main.py create --type <momentum|mean_reversion|breakout> \
    --pairs BTC/USDT ETH/USDT --timeframe 1h

# Run full multi-strategy optimization cycle
python agent_main.py optimize \
    --pairs BTC/USDT ETH/USDT \
    --timeframe 1h \
    --cycles 2 \
    --generate 1        # generate 1 new strategy per cycle

# Show latest agent report
python agent_main.py report [--latest]
```

### Agentic Loop Pattern (standard for all agents)

```python
import anthropic

client = anthropic.Anthropic()  # reads ANTHROPIC_API_KEY from env

def run_agent(system_prompt: str, initial_message: str, tools: list, runner: BacktestRunner):
    messages = [{"role": "user", "content": initial_message}]

    while True:
        response = client.messages.create(
            model="claude-opus-4-6",
            max_tokens=4096,
            system=system_prompt,
            tools=tools,
            messages=messages
        )

        if response.stop_reason == "end_turn":
            # Extract final text response
            return next(b.text for b in response.content if hasattr(b, "text"))

        if response.stop_reason == "tool_use":
            messages.append({"role": "assistant", "content": response.content})
            tool_results = []
            for block in response.content:
                if block.type == "tool_use":
                    result = execute_tool(block.name, block.input, runner)
                    tool_results.append({
                        "type": "tool_result",
                        "tool_use_id": block.id,
                        "content": json.dumps(result)
                    })
            messages.append({"role": "user", "content": tool_results})
```

**Model selection:**
- `claude-opus-4-6` — strategy creation, complex analysis, orchestrator decisions
- `claude-haiku-4-5-20251001` — lightweight metric summaries, quick parameter suggestions

**Context management:** Each agent run is a fresh conversation. The agent's persistent memory is the SQLite database and results directory — query these at session start via `list_runs()` and `compare_runs()`.

---

## requirements.txt

```
anthropic>=0.40.0
pandas>=2.1.0
numpy>=1.25.0
pyarrow>=14.0.0
ccxt>=4.0.0
ta>=0.11.0
pydantic>=2.5.0
pydantic-settings>=2.1.0
apscheduler>=3.10.0
pytest>=7.4.0
```

---

## Design Rules (apply to every file)

1. **No circular imports.** Chain: `config → models → data → strategies → engine → db → agents → main`
2. **Strategies never import engine.** Strategy only imports from `models.py` and `ta`.
3. **Agents never import engine internals.** All engine access goes through `BacktestRunner`.
4. **All data passes through Pydantic models.** No raw dicts where a model exists.
5. **No global mutable state** except `settings` in `config.py`.
6. **All metric functions return `float`.** Never NaN, never raise — return `0.0` on edge cases.
7. **Python 3.11+ type hints on all function signatures.**
8. **Docstrings on every class and public function.**
9. **Use `pathlib.Path` everywhere** — never string concatenation for paths.
10. **Directories auto-created** with `Path.mkdir(parents=True, exist_ok=True)`.
11. **All JSON output** uses `json.dump(..., indent=2)`.
12. **Specific exceptions only** — no bare `except`. Raise `ValueError` for bad inputs, `FileNotFoundError` for missing files.

---

## Verification (End-to-End Test Sequence)

Run in this exact order after implementation:

```bash
# Setup
python -m venv venv && source venv/bin/activate
pip install -r requirements.txt

# 1. Fetch historical data
python main.py fetch BTC/USDT 1h --days 365
python main.py fetch ETH/USDT 1h --days 365

# 2. Verify storage
python main.py list-data

# 3. Run starter strategy backtests
python main.py backtest rsi_reversion BTC/USDT 1h
python main.py backtest ma_crossover BTC/USDT 1h

# 4. Compare runs (use run_ids from output above)
python main.py compare <run_id_1> <run_id_2>

# 5. Parameter sweep
python main.py sweep rsi_reversion BTC/USDT 1h \
    --param rsi_period 10 20 2 \
    --param oversold 25 35 5

# 6. Run all unit tests — must be 0 failures
pytest tests/ -v

# 7. Agent: refine RSI strategy parameters
export ANTHROPIC_API_KEY=<your-key>
python agent_main.py refine rsi_reversion BTC/USDT 1h --iterations 3

# 8. Agent: generate a new momentum strategy
python agent_main.py create --type momentum --pairs BTC/USDT --timeframe 4h

# 9. Agent: full optimization cycle
python agent_main.py optimize \
    --pairs BTC/USDT ETH/USDT \
    --timeframe 1h \
    --cycles 2 \
    --generate 1
```

**Expected outcomes:**
- `pytest tests/ -v` → 0 failures
- `backtest` → real Sharpe ratio, max drawdown, win rate printed
- `sweep` → ranked table of parameter combinations
- `refine` → improved Sharpe vs baseline (or explanation why not)
- `create` → new `.py` file in `strategies/`, backtest runs without errors
- `optimize` → `results/agent_report_*.json` with ranked strategies
