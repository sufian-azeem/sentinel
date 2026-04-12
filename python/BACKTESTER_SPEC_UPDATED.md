# Crypto Backtesting Engine — Complete Build Specification

> **Purpose:** This document is a complete, unambiguous specification for building a crypto backtesting engine in Python. An AI coding agent should be able to read this document and produce every file without asking questions.
>
> **Scope:** Parts 1–5 only. Data fetching, storage, strategy framework, backtesting engine, metrics, results output, and CLI. No AI agents, no dashboard, no live trading.
>
> **Language:** Python 3.11+ only. No JavaScript, no frontend, no Docker.
>
> **Deployment target:** Single VPS or local machine.

---

## Table of Contents

1. [Project Structure](#1-project-structure)
2. [Dependencies](#2-dependencies)
3. [Config & Settings](#3-config--settings)
4. [Data Models](#4-data-models)
5. [Data Layer](#5-data-layer)
6. [Strategy Framework](#6-strategy-framework)
7. [Backtesting Engine](#7-backtesting-engine)
8. [Performance Metrics](#8-performance-metrics)
9. [Results & Persistence](#9-results--persistence)
10. [CLI Entry Point](#10-cli-entry-point)
11. [Starter Strategies](#11-starter-strategies)
12. [Testing Requirements](#12-testing-requirements)
13. [Build Order](#13-build-order)
14. [Design Rules](#14-design-rules)

---

## 1. Project Structure

```
crypto-backtester/
├── config.py                        # Global settings (Pydantic BaseSettings)
├── models.py                        # All data models (Pydantic)
├── main.py                          # CLI entry point (argparse)
│
├── data/
│   ├── __init__.py
│   ├── fetcher.py                   # Download candles from exchanges via ccxt
│   ├── storage.py                   # Read/write Parquet files
│   └── candles/                     # Directory for .parquet files (auto-created)
│
├── strategies/
│   ├── __init__.py
│   ├── base.py                      # Abstract base class for all strategies
│   ├── loader.py                    # Dynamic strategy loading + config pairing
│   ├── rsi_reversion.py             # Starter strategy: RSI mean reversion
│   ├── ma_crossover.py              # Starter strategy: Moving average crossover
│   └── configs/                     # JSON config files per strategy
│       ├── rsi_reversion.json
│       └── ma_crossover.json
│
├── engine/
│   ├── __init__.py
│   ├── backtester.py                # Core backtest loop
│   ├── portfolio.py                 # Position tracking, balance, equity
│   ├── order.py                     # Order creation, fill simulation, fees
│   └── metrics.py                   # All performance metric calculations
│
├── results/                         # Output directory (auto-created)
│   ├── backtest_*.json              # Full result per run
│   └── equity_*.csv                 # Equity curve per run
│
├── db.py                            # SQLite helper (create tables, insert, query)
├── db.sqlite                        # SQLite database (auto-created at runtime)
├── requirements.txt
│
└── tests/
    ├── __init__.py
    ├── test_fetcher.py
    ├── test_storage.py
    ├── test_strategy.py
    ├── test_engine.py
    └── test_metrics.py
```

Every directory with Python files must have an `__init__.py` (can be empty). The `data/candles/` and `results/` directories are created automatically at runtime if they don't exist.

---

## 2. Dependencies

### requirements.txt

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

### Installation

```bash
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

### What each package does

| Package | Purpose | Used in |
|---------|---------|---------|
| `pandas` | DataFrame manipulation, candle data handling | Everywhere |
| `numpy` | Numerical calculations (Sharpe, returns, etc.) | `engine/metrics.py` |
| `pyarrow` | Parquet file read/write | `data/storage.py` |
| `ccxt` | Unified exchange API (Binance, Bybit, OKX, etc.) | `data/fetcher.py` |
| `ta` | Technical indicators library (RSI, MACD, BB, etc.) | `strategies/*.py` |
| `pydantic` | Data validation and serialization for all models | `models.py`, `config.py` |
| `pydantic-settings` | Settings management with env var support | `config.py` |
| `apscheduler` | Scheduled data refresh jobs | `main.py` (optional) |
| `pytest` | Test framework | `tests/` |
| `anthropic` | Claude API (reserved for future agent layer) | Not used in Parts 1–5 |

---

## 3. Config & Settings

### File: `config.py`

Uses `pydantic-settings.BaseSettings` so values can be overridden via environment variables.

```python
from pydantic_settings import BaseSettings
from pathlib import Path

class Settings(BaseSettings):
    # Paths
    BASE_DIR: Path = Path(__file__).parent
    DATA_DIR: Path = Path(__file__).parent / "data" / "candles"
    RESULTS_DIR: Path = Path(__file__).parent / "results"
    STRATEGIES_DIR: Path = Path(__file__).parent / "strategies"
    CONFIGS_DIR: Path = Path(__file__).parent / "strategies" / "configs"
    DB_PATH: Path = Path(__file__).parent / "db.sqlite"

    # Exchange defaults
    DEFAULT_EXCHANGE: str = "binance"
    RATE_LIMIT_MS: int = 100            # ms between API calls

    # Backtest defaults
    STARTING_CAPITAL: float = 10_000.0  # USDT
    DEFAULT_FEE_RATE: float = 0.001     # 0.1% per trade
    DEFAULT_SLIPPAGE_PCT: float = 0.0005  # 0.05% slippage
    MAX_LEVERAGE: float = 5.0           # Hard cap for futures

    # Risk defaults
    DEFAULT_MAX_POSITION_PCT: float = 0.10  # 10% of capital per trade
    DEFAULT_STOP_LOSS_PCT: float = 0.03     # 3% stop loss
    DEFAULT_TAKE_PROFIT_PCT: float = 0.06   # 6% take profit
    MAX_DRAWDOWN_LIMIT: float = 0.25        # 25% — auto-fail threshold

    # Refinement
    MIN_TRADE_COUNT: int = 50           # Minimum trades for valid backtest
    OVERFIT_THRESHOLD: float = 0.40     # 40% Sharpe drop = overfit flag

    class Config:
        env_prefix = "BT_"             # e.g., BT_STARTING_CAPITAL=50000

settings = Settings()
```

**Rules:**
- Import as `from config import settings` everywhere.
- Never hardcode paths or magic numbers — always reference `settings`.
- All directories are auto-created at first use (see `db.py` and `storage.py`).

---

## 4. Data Models

### File: `models.py`

All data structures as Pydantic models. These are the contracts between every module.

```python
from pydantic import BaseModel, Field
from typing import Optional
from enum import Enum
import uuid
from datetime import datetime


# ─── Enums ───

class Side(str, Enum):
    BUY = "buy"
    SELL = "sell"

class SignalAction(str, Enum):
    BUY = "buy"
    SELL = "sell"
    CLOSE = "close"
    HOLD = "hold"

class OrderType(str, Enum):
    MARKET = "market"
    LIMIT = "limit"
    STOP = "stop"

class OrderStatus(str, Enum):
    PENDING = "pending"
    FILLED = "filled"
    CANCELLED = "cancelled"

class ExitReason(str, Enum):
    SIGNAL = "signal"
    STOP_LOSS = "stop_loss"
    TAKE_PROFIT = "take_profit"
    TRAILING_STOP = "trailing_stop"
    LIQUIDATION = "liquidation"
    END_OF_DATA = "end_of_data"

class StrategyType(str, Enum):
    SPOT = "spot"
    FUTURES = "futures"
    BOTH = "both"


# ─── Parameter Definition ───

class ParamDef(BaseModel):
    """One tunable strategy parameter with bounds for optimization."""
    default: float
    min: float
    max: float
    step: float
    description: str = ""


# ─── Risk Rules ───

class RiskRules(BaseModel):
    """Risk constraints applied by the engine during backtesting."""
    stop_loss_pct: Optional[float] = 0.03
    take_profit_pct: Optional[float] = 0.06
    max_position_pct: float = 0.10
    max_leverage: float = 1.0           # 1.0 = spot, >1.0 = futures
    trailing_stop_pct: Optional[float] = None


# ─── Strategy Config ───

class StrategyConfig(BaseModel):
    """Loaded from JSON. Defines a strategy's identity and parameter space."""
    strategy_id: str
    name: str
    version: str = "1.0.0"
    type: StrategyType = StrategyType.BOTH
    pairs: list[str] = ["BTC/USDT"]
    timeframes: list[str] = ["1h"]
    parameters: dict[str, ParamDef] = {}
    risk_rules: RiskRules = RiskRules()


# ─── Signal ───

class Signal(BaseModel):
    """Returned by strategy.signal() on every candle."""
    action: SignalAction = SignalAction.HOLD
    strength: float = 0.0               # 0.0 to 1.0 — used for position sizing
    reason: str = ""
    order_type: OrderType = OrderType.MARKET
    limit_price: Optional[float] = None


# ─── Order ───

class Order(BaseModel):
    """A trade order managed by the engine."""
    order_id: str = Field(default_factory=lambda: str(uuid.uuid4())[:8])
    timestamp: int                      # Unix ms
    side: Side
    order_type: OrderType = OrderType.MARKET
    pair: str
    quantity: float
    price: float                        # Requested price
    filled_price: Optional[float] = None
    fee: float = 0.0
    slippage: float = 0.0
    status: OrderStatus = OrderStatus.PENDING
    leverage: float = 1.0


# ─── Position ───

class Position(BaseModel):
    """An open position tracked by the portfolio."""
    position_id: str = Field(default_factory=lambda: str(uuid.uuid4())[:8])
    pair: str
    side: Side                          # BUY = long, SELL = short
    entry_price: float
    quantity: float
    entry_time: int                     # Unix ms
    leverage: float = 1.0
    unrealized_pnl: float = 0.0
    highest_price_since_entry: float = 0.0   # For trailing stop (longs)
    lowest_price_since_entry: float = 999999999.0  # For trailing stop (shorts)
    liquidation_price: Optional[float] = None


# ─── Trade (completed round-trip) ───

class Trade(BaseModel):
    """A closed trade — entry + exit."""
    trade_id: str = Field(default_factory=lambda: str(uuid.uuid4())[:8])
    pair: str
    side: Side
    entry_price: float
    exit_price: float
    quantity: float
    entry_time: int
    exit_time: int
    pnl: float                          # Absolute P&L in quote currency
    pnl_pct: float                      # P&L as percentage of entry
    fee_total: float                    # Total fees (entry + exit)
    leverage: float = 1.0
    exit_reason: ExitReason = ExitReason.SIGNAL


# ─── Backtest Result ───

class BacktestResult(BaseModel):
    """Complete output of one backtest run."""
    run_id: str = Field(default_factory=lambda: str(uuid.uuid4())[:8])
    strategy_id: str
    strategy_name: str
    params_used: dict
    pair: str
    timeframe: str
    start_date: str
    end_date: str
    starting_capital: float
    final_equity: float
    total_trades: int
    trades: list[Trade]
    equity_curve: list[float]           # Equity at each candle
    equity_timestamps: list[int]        # Corresponding timestamps
    metrics: dict[str, float]           # All computed metrics
    metadata: dict = {}                 # Extra info (duration, engine version, etc.)
    created_at: str = Field(default_factory=lambda: datetime.utcnow().isoformat())
```

**Rules:**
- Every module imports models from `models.py` — never define data structures inline.
- All models must be JSON-serializable via `.model_dump()`.
- Use enums for all categorical fields — never bare strings.

---

## 5. Data Layer

### File: `data/fetcher.py`

Downloads historical OHLCV candles from exchanges using `ccxt`.

#### Functions

```
fetch_candles(
    pair: str,                    # e.g., "BTC/USDT"
    timeframe: str,               # e.g., "1h", "4h", "15m"
    start_date: str,              # ISO format: "2024-01-01"
    end_date: str | None = None,  # None = up to now
    exchange: str = "binance"     # Any ccxt-supported exchange
) -> pd.DataFrame
```

**Behavior:**
1. Create ccxt exchange instance with `enableRateLimit=True`.
2. Convert `start_date` and `end_date` to Unix ms timestamps.
3. Loop: call `exchange.fetch_ohlcv(pair, timeframe, since=since, limit=1000)`.
4. Each call returns up to 1000 candles. After each batch, set `since` = last candle timestamp + 1.
5. Continue until `since` > `end_date` timestamp or API returns empty.
6. Concatenate all batches into a single DataFrame.
7. Respect rate limiting: sleep `settings.RATE_LIMIT_MS` between calls.
8. Return DataFrame with columns: `timestamp, open, high, low, close, volume, quote_volume, trades_count`.
9. If exchange doesn't provide `quote_volume` or `trades_count`, fill with 0.

**Error handling:**
- Retry up to 3 times on network errors with exponential backoff (1s, 2s, 4s).
- Raise `ValueError` if pair or timeframe is not supported by the exchange.
- Log warnings for gaps in data (missing candles).

```
detect_gaps(
    df: pd.DataFrame,
    timeframe: str
) -> list[tuple[int, int]]
```

**Behavior:**
1. Calculate expected interval in ms from timeframe string (e.g., "1h" = 3_600_000 ms).
2. Compute differences between consecutive timestamps.
3. Return list of (gap_start_timestamp, gap_end_timestamp) for any interval > 1.5x expected.

```
get_exchange(name: str) -> ccxt.Exchange
```

**Behavior:**
1. Instantiate `getattr(ccxt, name)({"enableRateLimit": True})`.
2. Return the instance.
3. Raise `ValueError` if exchange name is not in `ccxt.exchanges`.

#### Timeframe to milliseconds mapping

Use this mapping internally:

```python
TIMEFRAME_MS = {
    "1m": 60_000,
    "3m": 180_000,
    "5m": 300_000,
    "15m": 900_000,
    "30m": 1_800_000,
    "1h": 3_600_000,
    "2h": 7_200_000,
    "4h": 14_400_000,
    "6h": 21_600_000,
    "8h": 28_800_000,
    "12h": 43_200_000,
    "1d": 86_400_000,
    "3d": 259_200_000,
    "1w": 604_800_000,
    "1M": 2_592_000_000,
}
```

---

### File: `data/storage.py`

Manages Parquet files — one file per pair per timeframe.

#### Functions

```
save_candles(df: pd.DataFrame, pair: str, timeframe: str) -> Path
```

**Behavior:**
1. Compute file path via `get_parquet_path(pair, timeframe)`.
2. Create `settings.DATA_DIR` if it doesn't exist.
3. If file exists: load existing, call `merge_candles(existing, df)`, overwrite file.
4. If file doesn't exist: write `df` directly.
5. Return the file path.

```
load_candles(
    pair: str,
    timeframe: str,
    start_date: str | None = None,
    end_date: str | None = None
) -> pd.DataFrame
```

**Behavior:**
1. Load Parquet file into DataFrame.
2. If `start_date` provided: filter rows where timestamp >= start_date as Unix ms.
3. If `end_date` provided: filter rows where timestamp <= end_date as Unix ms.
4. Return filtered DataFrame sorted by timestamp ascending.
5. Raise `FileNotFoundError` if Parquet file doesn't exist.

```
list_available() -> list[dict]
```

**Behavior:**
1. Scan `settings.DATA_DIR` for all `.parquet` files.
2. For each file, parse the filename to extract pair and timeframe.
3. Read the file to get row count, min timestamp, max timestamp.
4. Return list of dicts: `{"pair": "BTC/USDT", "timeframe": "1h", "rows": 17520, "start": "2024-01-01", "end": "2025-12-31", "file_size_mb": 1.5}`.

```
get_parquet_path(pair: str, timeframe: str) -> Path
```

**Behavior:**
1. Replace `/` with `_` in pair (e.g., `BTC/USDT` → `BTC_USDT`).
2. Return `settings.DATA_DIR / f"{pair_clean}_{timeframe}.parquet"`.

```
merge_candles(existing_df: pd.DataFrame, new_df: pd.DataFrame) -> pd.DataFrame
```

**Behavior:**
1. Concatenate both DataFrames.
2. Drop duplicate rows by `timestamp` column (keep last).
3. Sort by `timestamp` ascending.
4. Reset index.
5. Return merged DataFrame.

#### Parquet schema

Every Parquet file has exactly these columns with these dtypes:

| Column | Dtype | Description |
|--------|-------|-------------|
| `timestamp` | `int64` | Unix milliseconds, UTC |
| `open` | `float64` | Opening price |
| `high` | `float64` | Highest price in candle |
| `low` | `float64` | Lowest price in candle |
| `close` | `float64` | Closing price |
| `volume` | `float64` | Base asset volume |
| `quote_volume` | `float64` | Quote asset volume (USDT) |
| `trades_count` | `int32` | Number of trades in candle |

#### File naming convention

```
{BASE}_{QUOTE}_{TIMEFRAME}.parquet
```

Examples:
- `BTC_USDT_1h.parquet`
- `ETH_USDT_4h.parquet`
- `SOL_USDT_15m.parquet`

---

## 6. Strategy Framework

### File: `strategies/base.py`

Abstract base class that every strategy must inherit from.

```python
from abc import ABC, abstractmethod
import pandas as pd
from models import Signal, ParamDef, Position, StrategyConfig
from typing import Optional


class BaseStrategy(ABC):
    """
    Every strategy inherits from this class and implements 4 methods.
    The engine interacts with strategies ONLY through this interface.
    """

    def __init__(self, params: dict | None = None):
        """
        Initialize with optional parameter overrides.
        Merges provided params with defaults, validates bounds.
        """
        defaults = self.default_params()
        self.params = {}
        for key, param_def in defaults.items():
            if params and key in params:
                value = params[key]
                # Clamp to bounds
                value = max(param_def.min, min(param_def.max, value))
                self.params[key] = value
            else:
                self.params[key] = param_def.default

    @abstractmethod
    def name(self) -> str:
        """Return strategy name string. Example: 'RSI Mean Reversion'"""
        pass

    @abstractmethod
    def default_params(self) -> dict[str, ParamDef]:
        """
        Return parameter definitions with bounds.
        Example:
            {
                "rsi_period": ParamDef(default=14, min=5, max=50, step=1, description="RSI lookback"),
                "oversold": ParamDef(default=30, min=15, max=40, step=1, description="Buy threshold"),
            }
        """
        pass

    @abstractmethod
    def indicators(self, df: pd.DataFrame) -> pd.DataFrame:
        """
        Add indicator columns to the candle DataFrame.
        MUST return the same DataFrame with new columns added.
        MUST NOT modify existing columns.
        Example: df["rsi"] = ta.momentum.RSIIndicator(df["close"], window=14).rsi()
        """
        pass

    @abstractmethod
    def signal(self, row: pd.Series, position: Optional[Position] = None) -> Signal:
        """
        Given the current candle row (with indicator columns) and current position,
        return a Signal with action = buy/sell/close/hold.

        Rules:
        - row contains ALL columns: OHLCV + indicators added in indicators()
        - position is None if no open position, or a Position object if one exists
        - MUST return a Signal object, never None
        - MUST NOT access future data (only current row)
        - Use self.params for all configurable values
        """
        pass

    def validate_params(self, params: dict) -> dict:
        """Validate params against bounds, fill missing with defaults."""
        defaults = self.default_params()
        validated = {}
        for key, param_def in defaults.items():
            if key in params:
                value = params[key]
                value = max(param_def.min, min(param_def.max, value))
                validated[key] = value
            else:
                validated[key] = param_def.default
        return validated
```

**Contract rules (CRITICAL — the engine depends on these):**
1. `indicators(df)` receives a DataFrame with OHLCV columns. It adds new columns and returns the same DataFrame. It must NOT drop or rename existing columns.
2. `signal(row, position)` receives a single row (pd.Series) with OHLCV + indicator columns, plus the current Position or None. It returns a `Signal`.
3. `signal()` must ALWAYS return a Signal. It must never return None, raise exceptions, or access data outside the current row.
4. Strategy must never import or reference the engine, portfolio, or order modules. It only knows about `models.py`.

---

### File: `strategies/loader.py`

Dynamically loads strategy classes and pairs them with JSON configs.

#### Functions

```
load_strategy(name: str, params: dict | None = None) -> BaseStrategy
```

**Behavior:**
1. Import the module from `strategies/{name}.py` using `importlib.import_module`.
2. Find the class in the module that inherits from `BaseStrategy` (there should be exactly one).
3. Load the JSON config from `strategies/configs/{name}.json` if it exists.
4. If `params` is provided, merge with config defaults (params override config).
5. Instantiate the strategy class with merged params.
6. Return the instance.

```
list_strategies() -> list[dict]
```

**Behavior:**
1. Scan `settings.STRATEGIES_DIR` for `.py` files (exclude `__init__.py`, `base.py`, `loader.py`).
2. For each file, extract the strategy name (filename without `.py`).
3. Check if a matching JSON config exists in `configs/`.
4. Return list of dicts: `{"name": "rsi_reversion", "has_config": True}`.

```
load_config(name: str) -> StrategyConfig
```

**Behavior:**
1. Read `strategies/configs/{name}.json`.
2. Parse into `StrategyConfig` model.
3. Return it.
4. Raise `FileNotFoundError` if config doesn't exist.

---

### Strategy JSON config schema

File location: `strategies/configs/{strategy_name}.json`

```json
{
    "strategy_id": "rsi_reversion_v1",
    "name": "RSI Mean Reversion",
    "version": "1.0.0",
    "type": "both",
    "pairs": ["BTC/USDT", "ETH/USDT", "SOL/USDT"],
    "timeframes": ["1h", "4h"],
    "parameters": {
        "rsi_period": {
            "default": 14,
            "min": 5,
            "max": 50,
            "step": 1,
            "description": "RSI lookback period"
        },
        "oversold": {
            "default": 30,
            "min": 15,
            "max": 40,
            "step": 1,
            "description": "RSI level to trigger buy"
        },
        "overbought": {
            "default": 70,
            "min": 60,
            "max": 85,
            "step": 1,
            "description": "RSI level to trigger sell"
        }
    },
    "risk_rules": {
        "stop_loss_pct": 0.03,
        "take_profit_pct": 0.06,
        "max_position_pct": 0.10,
        "max_leverage": 1.0,
        "trailing_stop_pct": null
    }
}
```

---

## 7. Backtesting Engine

### Architecture Improvements (Mandatory)

The backtesting engine must include the following architectural components to ensure performance, clarity, and future extensibility.

#### 1. MarketClock

A `MarketClock` component must control the progression of simulated time instead of directly iterating over a dataframe.

File:
```
engine/clock.py
```

Responsibilities:

- Maintain the current simulation timestamp
- Provide the next candle to the engine
- Signal when the dataset has ended

Example interface:

```python
class MarketClock:

    def __init__(self, candles: pd.DataFrame):
        self.candles = candles
        self.index = 0

    def has_next(self) -> bool:
        return self.index < len(self.candles)

    def next(self) -> pd.Series:
        candle = self.candles.iloc[self.index]
        self.index += 1
        return candle
```

The backtest loop **must use MarketClock** instead of iterating the dataframe directly.

Example:

```python
clock = MarketClock(candles)

while clock.has_next():
    candle = clock.next()
```

This abstraction allows future migration to event‑driven backtesting and live trading with minimal changes.

---

#### 2. Trade Ledger Separation

Trade recording must be separated from the portfolio logic.

New module:

```
engine/trade_ledger.py
```

Responsibilities:

- Record every executed trade
- Maintain complete trade history
- Provide data for metrics and result export

Example structure:

```python
class TradeLedger:

    def __init__(self):
        self.trades = []

    def record_trade(self, trade):
        self.trades.append(trade)
```

Architecture rule:

- `portfolio.py` manages balances and positions.
- `trade_ledger.py` records trade history.

The `Backtester` coordinates both components.

---

#### 3. Indicator Precomputation

Indicators must be calculated **before the backtest loop begins**.

The engine must enrich the candle dataframe with all required indicators before strategy execution.

Example flow:

```
raw candles
      ↓
indicator calculation
      ↓
enriched dataframe
      ↓
backtest engine
```

Example implementation:

```python
df["rsi"] = ta.momentum.RSIIndicator(df["close"], window=14).rsi()
df["ema_fast"] = ta.trend.EMAIndicator(df["close"], window=20).ema_indicator()
df["ema_slow"] = ta.trend.EMAIndicator(df["close"], window=50).ema_indicator()
```

Strategies must read indicators from the dataframe instead of recomputing them inside the loop.

Benefits:

- Faster backtests
- Consistent indicator values across strategies
- Easier multi‑strategy testing


### File: `engine/order.py`

Handles order creation, fill simulation, and fee/slippage calculation.

#### Class: `OrderManager`

```python
class OrderManager:
    def __init__(self, fee_rate: float, slippage_pct: float):
        self.fee_rate = fee_rate
        self.slippage_pct = slippage_pct
        self.pending_orders: list[Order] = []
```

#### Methods

```
create_order(
    side: Side,
    pair: str,
    quantity: float,
    price: float,
    timestamp: int,
    order_type: OrderType = OrderType.MARKET,
    leverage: float = 1.0,
    limit_price: float | None = None
) -> Order
```

**Behavior:**
1. Create an `Order` object with status=PENDING.
2. If order_type is MARKET, immediately call `fill_order()` with the provided price.
3. If order_type is LIMIT or STOP, add to `self.pending_orders`.
4. Return the order.

```
fill_order(order: Order, fill_price: float, timestamp: int) -> Order
```

**Behavior:**
1. Apply slippage: `filled_price = apply_slippage(fill_price, order.side)`.
2. Calculate fee: `fee = calculate_fee(order.quantity, filled_price)`.
3. Update order: `status=FILLED, filled_price, fee, slippage`.
4. Return updated order.

```
process_pending_orders(candle: pd.Series) -> list[Order]
```

**Behavior:**
1. For each pending order, check if it fills on this candle:
   - **Limit buy:** fills if `candle.low <= order.price`. Fill price = `order.price`.
   - **Limit sell:** fills if `candle.high >= order.price`. Fill price = `order.price`.
   - **Stop (long SL):** fills if `candle.low <= order.price`. Fill price = `order.price`.
   - **Stop (short SL):** fills if `candle.high >= order.price`. Fill price = `order.price`.
2. Call `fill_order()` for each triggered order.
3. Remove filled orders from `self.pending_orders`.
4. Return list of filled orders.

```
apply_slippage(price: float, side: Side) -> float
```

**Behavior:**
1. Generate random slippage within `[0, self.slippage_pct]` using `numpy.random.uniform`.
2. For BUY: `return price * (1 + slippage)` (worse fill = higher price).
3. For SELL: `return price * (1 - slippage)` (worse fill = lower price).

```
calculate_fee(quantity: float, price: float) -> float
```

**Behavior:**
1. `return quantity * price * self.fee_rate`.

```
cancel_all_pending() -> int
```

**Behavior:**
1. Set all pending orders to status=CANCELLED.
2. Clear `self.pending_orders`.
3. Return count of cancelled orders.

---

### File: `engine/portfolio.py`

Tracks positions, balance, equity, and handles spot/futures logic.

#### Class: `Portfolio`

```python
class Portfolio:
    def __init__(
        self,
        starting_capital: float,
        fee_rate: float,
        slippage_pct: float,
        risk_rules: RiskRules
    ):
        self.starting_capital = starting_capital
        self.balance = starting_capital          # Available cash (USDT)
        self.fee_rate = fee_rate
        self.slippage_pct = slippage_pct
        self.risk_rules = risk_rules
        self.positions: list[Position] = []      # Currently open
        self.trades: list[Trade] = []            # Completed round-trips
        self.equity_curve: list[float] = []
        self.equity_timestamps: list[int] = []
        self.order_manager = OrderManager(fee_rate, slippage_pct)
```

#### Methods

```
open_position(order: Order) -> Position
```

**Behavior:**
1. Determine cost: `cost = order.quantity * order.filled_price`.
2. For spot (leverage=1.0): deduct `cost + order.fee` from `self.balance`.
3. For futures (leverage>1.0): deduct `(cost / order.leverage) + order.fee` from `self.balance` as margin.
4. Create Position with entry_price=order.filled_price, quantity, entry_time=order.timestamp, leverage.
5. For futures, calculate liquidation_price:
   - Long: `entry_price * (1 - 1/leverage + fee_rate)` (approximate).
   - Short: `entry_price * (1 + 1/leverage - fee_rate)` (approximate).
6. Set `highest_price_since_entry = entry_price`, `lowest_price_since_entry = entry_price`.
7. Append to `self.positions`.
8. Return the position.

```
close_position(
    position: Position,
    exit_price: float,
    exit_time: int,
    reason: ExitReason
) -> Trade
```

**Behavior:**
1. Calculate exit fee: `exit_fee = position.quantity * exit_price * self.fee_rate`.
2. Calculate PnL:
   - Long: `pnl = (exit_price - position.entry_price) * position.quantity * position.leverage - entry_fee - exit_fee`.
   - Short: `pnl = (position.entry_price - exit_price) * position.quantity * position.leverage - entry_fee - exit_fee`.
3. Calculate pnl_pct: `pnl / (position.entry_price * position.quantity)`.
4. Add back to balance:
   - Spot: `balance += (position.quantity * exit_price) - exit_fee`.
   - Futures: `balance += margin + pnl` (return margin plus profit/loss).
5. Create Trade object with all fields.
6. Remove position from `self.positions`.
7. Append trade to `self.trades`.
8. Return the trade.

```
check_stops(candle: pd.Series, position: Position) -> ExitReason | None
```

**Behavior (checked in this order):**
1. **Liquidation (futures only):** If leverage > 1.0:
   - Long: if `candle.low <= position.liquidation_price` → return `LIQUIDATION`.
   - Short: if `candle.high >= position.liquidation_price` → return `LIQUIDATION`.
2. **Stop-loss:** If `risk_rules.stop_loss_pct` is set:
   - Long: if `candle.low <= position.entry_price * (1 - risk_rules.stop_loss_pct)` → return `STOP_LOSS`.
   - Short: if `candle.high >= position.entry_price * (1 + risk_rules.stop_loss_pct)` → return `STOP_LOSS`.
3. **Trailing stop:** If `risk_rules.trailing_stop_pct` is set:
   - Update highest/lowest: `position.highest_price_since_entry = max(current, candle.high)`, `position.lowest_price_since_entry = min(current, candle.low)`.
   - Long: if `candle.low <= position.highest_price_since_entry * (1 - risk_rules.trailing_stop_pct)` → return `TRAILING_STOP`.
   - Short: if `candle.high >= position.lowest_price_since_entry * (1 + risk_rules.trailing_stop_pct)` → return `TRAILING_STOP`.
4. **Take-profit:** If `risk_rules.take_profit_pct` is set:
   - Long: if `candle.high >= position.entry_price * (1 + risk_rules.take_profit_pct)` → return `TAKE_PROFIT`.
   - Short: if `candle.low <= position.entry_price * (1 - risk_rules.take_profit_pct)` → return `TAKE_PROFIT`.
5. Return `None` if no stop triggered.

**Important:** Check order matters. Liquidation beats stop-loss beats trailing-stop beats take-profit. In reality, multiple could trigger on the same candle — we assume worst-case fires first.

```
calculate_position_size(signal: Signal, current_price: float) -> float
```

**Behavior:**
1. `max_spend = self.balance * self.risk_rules.max_position_pct`.
2. Scale by signal strength: `spend = max_spend * signal.strength`.
3. If `spend < 1.0` (less than $1), return 0.0 (too small to trade).
4. `quantity = spend / current_price`.
5. For futures: `quantity = quantity * risk_rules.max_leverage`.
6. Return quantity.

```
update_equity(current_price: float, timestamp: int) -> float
```

**Behavior:**
1. Start with `equity = self.balance`.
2. For each open position:
   - Long: `unrealized = (current_price - position.entry_price) * position.quantity * position.leverage`.
   - Short: `unrealized = (position.entry_price - current_price) * position.quantity * position.leverage`.
   - `equity += margin_used + unrealized` (for futures) or `equity += position.quantity * current_price` (for spot).
3. Append equity to `self.equity_curve`.
4. Append timestamp to `self.equity_timestamps`.
5. Return equity.

```
get_position_for_pair(pair: str) -> Position | None
```

**Behavior:**
1. Find first open position matching pair.
2. Return it, or None if no position exists for that pair.

```
has_open_position(pair: str) -> bool
```

**Behavior:**
1. Return `self.get_position_for_pair(pair) is not None`.

---

### File: `engine/backtester.py`

The main backtest loop. This is the core of the entire system.

#### Class: `Backtester`

```python
class Backtester:
    def __init__(
        self,
        starting_capital: float = None,
        fee_rate: float = None,
        slippage_pct: float = None,
    ):
        self.starting_capital = starting_capital or settings.STARTING_CAPITAL
        self.fee_rate = fee_rate or settings.DEFAULT_FEE_RATE
        self.slippage_pct = slippage_pct or settings.DEFAULT_SLIPPAGE_PCT
```

#### Primary method

```
run(
    strategy: BaseStrategy,
    df: pd.DataFrame,
    pair: str,
    timeframe: str,
    risk_rules: RiskRules | None = None
) -> BacktestResult
```

**Complete behavior (this is the most important function in the system):**

```
STEP 1: PREPARE DATA
    1.1  Make a copy of df to avoid mutating the original.
    1.2  Call strategy.indicators(df) to add indicator columns.
    1.3  Drop rows where ANY indicator column is NaN
         (indicators need lookback, so first N rows will be NaN).
    1.4  Reset index.

STEP 2: INITIALIZE PORTFOLIO
    2.1  Create RiskRules from strategy config if not provided.
    2.2  Create Portfolio(starting_capital, fee_rate, slippage_pct, risk_rules).

STEP 3: MAIN LOOP — iterate through each row (candle)
    For i, row in df.iterrows():

        3.1  CHECK STOPS ON OPEN POSITIONS
             For each open position:
                 exit_reason = portfolio.check_stops(row, position)
                 If exit_reason is not None:
                     Determine exit_price based on reason:
                         STOP_LOSS (long): entry_price * (1 - stop_loss_pct)
                         STOP_LOSS (short): entry_price * (1 + stop_loss_pct)
                         TAKE_PROFIT (long): entry_price * (1 + take_profit_pct)
                         TAKE_PROFIT (short): entry_price * (1 - take_profit_pct)
                         TRAILING_STOP (long): highest * (1 - trailing_stop_pct)
                         TRAILING_STOP (short): lowest * (1 + trailing_stop_pct)
                         LIQUIDATION: liquidation_price
                     portfolio.close_position(position, exit_price, row.timestamp, exit_reason)

        3.2  PROCESS PENDING LIMIT ORDERS
             filled = portfolio.order_manager.process_pending_orders(row)
             For each filled order: portfolio.open_position(filled_order)

        3.3  GET STRATEGY SIGNAL
             current_position = portfolio.get_position_for_pair(pair)
             signal = strategy.signal(row, current_position)

        3.4  EXECUTE SIGNAL
             If signal.action == BUY and current_position is None:
                 quantity = portfolio.calculate_position_size(signal, row.close)
                 If quantity > 0:
                     If signal.order_type == MARKET:
                         order = order_manager.create_order(
                             side=BUY, pair=pair, quantity=quantity,
                             price=row.close, timestamp=row.timestamp,
                             leverage=risk_rules.max_leverage
                         )
                         portfolio.open_position(order)
                     If signal.order_type == LIMIT:
                         order_manager.create_order(
                             side=BUY, pair=pair, quantity=quantity,
                             price=signal.limit_price, timestamp=row.timestamp,
                             order_type=LIMIT, leverage=risk_rules.max_leverage
                         )

             If signal.action == SELL and current_position is None:
                 # Short position (futures only, leverage > 1)
                 If risk_rules.max_leverage > 1.0:
                     quantity = portfolio.calculate_position_size(signal, row.close)
                     If quantity > 0:
                         order = order_manager.create_order(
                             side=SELL, pair=pair, quantity=quantity,
                             price=row.close, timestamp=row.timestamp,
                             leverage=risk_rules.max_leverage
                         )
                         portfolio.open_position(order)

             If signal.action == SELL and current_position is not None:
                 # Close long position
                 If current_position.side == BUY:
                     portfolio.close_position(
                         current_position, row.close, row.timestamp, ExitReason.SIGNAL
                     )

             If signal.action == CLOSE and current_position is not None:
                 portfolio.close_position(
                     current_position, row.close, row.timestamp, ExitReason.SIGNAL
                 )

        3.5  UPDATE EQUITY SNAPSHOT
             portfolio.update_equity(row.close, row.timestamp)

STEP 4: CLOSE ALL REMAINING POSITIONS
    For each open position:
        portfolio.close_position(position, last_row.close, last_row.timestamp, ExitReason.END_OF_DATA)

STEP 5: COMPUTE METRICS
    metrics = calculate_all(
        trades=portfolio.trades,
        equity_curve=portfolio.equity_curve,
        starting_capital=self.starting_capital,
        timeframe=timeframe
    )

STEP 6: BUILD AND RETURN RESULT
    Return BacktestResult(
        strategy_id=strategy config id or strategy.name(),
        strategy_name=strategy.name(),
        params_used=strategy.params,
        pair=pair,
        timeframe=timeframe,
        start_date=first row date as ISO string,
        end_date=last row date as ISO string,
        starting_capital=self.starting_capital,
        final_equity=portfolio.equity_curve[-1],
        total_trades=len(portfolio.trades),
        trades=portfolio.trades,
        equity_curve=portfolio.equity_curve,
        equity_timestamps=portfolio.equity_timestamps,
        metrics=metrics,
        metadata={
            "fee_rate": self.fee_rate,
            "slippage_pct": self.slippage_pct,
            "candles_processed": len(df),
            "indicators_added": list of new columns added by strategy,
        }
    )
```

**Anti-bias rules (CRITICAL):**
- The strategy's `signal()` method receives ONLY the current row. It cannot access `df` or future rows.
- Indicators are computed on the full DataFrame ONCE before the loop (this is correct — indicators like MA need the full history to compute on each row, but the strategy only sees the current row's computed values).
- NaN rows at the start (from indicator warmup) are dropped BEFORE the loop starts, so the strategy never sees NaN indicator values.
- All orders fill at the CURRENT candle's close price (for market orders) or at the limit price. Never at the candle that triggered the signal — we use the same candle's close as a simplification of "next available price."

---

## 8. Performance Metrics

### File: `engine/metrics.py`

All pure functions. No state, no side effects. Easy to test in isolation.

#### Functions

**Each function signature and exact formula:**

```
total_return_pct(starting_capital: float, final_equity: float) -> float
```
Formula: `((final_equity - starting_capital) / starting_capital) * 100`

---

```
sharpe_ratio(equity_curve: list[float], timeframe: str, risk_free_rate: float = 0.0) -> float
```
Formula:
1. Compute returns: `returns[i] = (equity[i] - equity[i-1]) / equity[i-1]`
2. `excess_returns = returns - risk_free_rate_per_period`
3. `annualization_factor = sqrt(periods_per_year)` where periods_per_year depends on timeframe (8760 for 1h, 2190 for 4h, 365 for 1d, etc.)
4. `sharpe = mean(excess_returns) / std(excess_returns) * annualization_factor`
5. Return 0.0 if std is 0 or NaN.

---

```
sortino_ratio(equity_curve: list[float], timeframe: str, risk_free_rate: float = 0.0) -> float
```
Formula: Same as Sharpe but `std` uses only negative returns (downside deviation).

---

```
max_drawdown(equity_curve: list[float]) -> float
```
Formula:
1. `running_max = cumulative maximum of equity_curve`
2. `drawdowns = (equity - running_max) / running_max`
3. Return `abs(min(drawdowns))` as a positive percentage.

---

```
max_drawdown_duration(equity_curve: list[float]) -> int
```
Formula: Number of periods in the longest drawdown (time from peak to recovery). If never recovered, duration = periods from peak to end of data.

---

```
win_rate(trades: list[Trade]) -> float
```
Formula: `count(trades where pnl > 0) / total_trades * 100`. Return 0.0 if no trades.

---

```
profit_factor(trades: list[Trade]) -> float
```
Formula: `sum(pnl for trades where pnl > 0) / abs(sum(pnl for trades where pnl < 0))`. Return 0.0 if no losing trades (or no trades).

---

```
expectancy(trades: list[Trade]) -> float
```
Formula: `sum(all pnl) / total_trades`. Average P&L per trade.

---

```
avg_trade_duration(trades: list[Trade], timeframe: str) -> float
```
Formula: `mean((exit_time - entry_time) for all trades)`, converted to hours.

---

```
calmar_ratio(equity_curve: list[float], timeframe: str) -> float
```
Formula: `annualized_return / max_drawdown`. Return 0.0 if max_drawdown is 0.

---

```
recovery_factor(trades: list[Trade], equity_curve: list[float], starting_capital: float) -> float
```
Formula: `net_profit / max_drawdown_absolute`. Where `net_profit = final_equity - starting_capital` and `max_drawdown_absolute = max_drawdown_pct * peak_equity`.

---

```
total_fees_paid(trades: list[Trade]) -> float
```
Formula: `sum(trade.fee_total for all trades)`.

---

```
best_trade(trades: list[Trade]) -> float
```
Formula: `max(trade.pnl for all trades)`. Return 0.0 if no trades.

---

```
worst_trade(trades: list[Trade]) -> float
```
Formula: `min(trade.pnl for all trades)`. Return 0.0 if no trades.

---

```
avg_winner(trades: list[Trade]) -> float
```
Formula: Average pnl of winning trades. Return 0.0 if no winners.

---

```
avg_loser(trades: list[Trade]) -> float
```
Formula: Average pnl of losing trades. Return 0.0 if no losers.

---

```
max_consecutive_wins(trades: list[Trade]) -> int
```
Formula: Longest streak of trades with pnl > 0.

---

```
max_consecutive_losses(trades: list[Trade]) -> int
```
Formula: Longest streak of trades with pnl <= 0.

---

```
long_short_ratio(trades: list[Trade]) -> dict
```
Formula: `{"longs": count of BUY side trades, "shorts": count of SELL side trades, "ratio": longs/shorts}`.

---

#### Master function

```
calculate_all(
    trades: list[Trade],
    equity_curve: list[float],
    starting_capital: float,
    timeframe: str
) -> dict[str, float]
```

**Behavior:**
1. Call every metric function above.
2. Return a flat dict with all values:

```python
{
    "total_return_pct": ...,
    "sharpe_ratio": ...,
    "sortino_ratio": ...,
    "max_drawdown_pct": ...,
    "max_drawdown_duration": ...,
    "win_rate_pct": ...,
    "profit_factor": ...,
    "expectancy": ...,
    "avg_trade_duration_hours": ...,
    "calmar_ratio": ...,
    "recovery_factor": ...,
    "total_trades": ...,
    "total_fees_paid": ...,
    "best_trade": ...,
    "worst_trade": ...,
    "avg_winner": ...,
    "avg_loser": ...,
    "max_consecutive_wins": ...,
    "max_consecutive_losses": ...,
    "longs": ...,
    "shorts": ...,
    "long_short_ratio": ...,
    "final_equity": ...,
    "net_profit": ...,
}
```

#### Annualization mapping

```python
PERIODS_PER_YEAR = {
    "1m": 525_600,
    "5m": 105_120,
    "15m": 35_040,
    "30m": 17_520,
    "1h": 8_760,
    "2h": 4_380,
    "4h": 2_190,
    "6h": 1_460,
    "8h": 1_095,
    "12h": 730,
    "1d": 365,
    "3d": 122,
    "1w": 52,
    "1M": 12,
}
```

---

## 9. Results & Persistence

### File: `db.py`

SQLite helper for metadata storage.

#### Functions

```
init_db() -> None
```

**Behavior:** Create tables if they don't exist:

```sql
CREATE TABLE IF NOT EXISTS strategies (
    strategy_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    version TEXT NOT NULL,
    type TEXT NOT NULL,
    config_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS backtest_runs (
    run_id TEXT PRIMARY KEY,
    strategy_id TEXT NOT NULL,
    strategy_name TEXT NOT NULL,
    pair TEXT NOT NULL,
    timeframe TEXT NOT NULL,
    params_json TEXT NOT NULL,
    metrics_json TEXT NOT NULL,
    starting_capital REAL NOT NULL,
    final_equity REAL NOT NULL,
    total_trades INTEGER NOT NULL,
    started_at TEXT NOT NULL DEFAULT (datetime('now')),
    duration_seconds REAL,
    status TEXT NOT NULL DEFAULT 'completed',
    FOREIGN KEY (strategy_id) REFERENCES strategies(strategy_id)
);

CREATE TABLE IF NOT EXISTS run_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT NOT NULL,
    tag TEXT NOT NULL,
    FOREIGN KEY (run_id) REFERENCES backtest_runs(run_id)
);
```

```
save_run(result: BacktestResult) -> None
```

**Behavior:** Insert a row into `backtest_runs` with metrics_json = JSON serialized metrics dict.

```
get_run(run_id: str) -> dict | None
```

**Behavior:** Query `backtest_runs` by run_id, return row as dict.

```
list_runs(strategy_id: str | None = None, pair: str | None = None, limit: int = 50) -> list[dict]
```

**Behavior:** Query `backtest_runs` with optional filters, ordered by `started_at DESC`.

```
compare_runs(run_ids: list[str]) -> pd.DataFrame
```

**Behavior:** Load metrics for each run_id, return a DataFrame with run_ids as rows and metrics as columns.

```
tag_run(run_id: str, tag: str) -> None
```

**Behavior:** Insert into `run_tags`.

---

### Result file output

After every backtest, save two files:

**`results/backtest_{run_id}.json`**
- Full `BacktestResult.model_dump()` serialized as JSON.
- Pretty-printed with `indent=2`.

**`results/equity_{run_id}.csv`**
- Two columns: `timestamp,equity`.
- One row per candle.

---

## 10. CLI Entry Point

### File: `main.py`

Uses Python's built-in `argparse`. No external CLI libraries.

#### Commands

**`fetch`** — Download candle data
```
python main.py fetch <pair> <timeframe> --days <N> [--exchange <name>]
```
Behavior:
1. Calculate start_date = today - N days.
2. Call `fetcher.fetch_candles(pair, timeframe, start_date)`.
3. Call `storage.save_candles(df, pair, timeframe)`.
4. Print: "Saved {rows} candles to {path}".

**`list-data`** — Show available data
```
python main.py list-data
```
Behavior:
1. Call `storage.list_available()`.
2. Print a formatted table: pair, timeframe, rows, date range, file size.

**`list-strategies`** — Show available strategies
```
python main.py list-strategies
```
Behavior:
1. Call `loader.list_strategies()`.
2. Print a list with name and config status.

**`backtest`** — Run a single backtest
```
python main.py backtest <strategy_name> <pair> <timeframe> [--capital N] [--param key=value ...]
```
Behavior:
1. Load strategy via `loader.load_strategy(name, params)`.
2. Load candles via `storage.load_candles(pair, timeframe)`.
3. Create `Backtester`, call `run()`.
4. Save result JSON, equity CSV, and SQLite row.
5. Print key metrics: return%, Sharpe, max DD, win rate, profit factor, total trades.

**`backtest-all`** — Run on all configured pairs/timeframes
```
python main.py backtest-all <strategy_name> [--capital N]
```
Behavior:
1. Load strategy config to get pairs and timeframes lists.
2. For each pair × timeframe combination, run backtest.
3. Print summary table comparing all runs.

**`show`** — Display full results for a run
```
python main.py show <run_id>
```
Behavior:
1. Load from `results/backtest_{run_id}.json`.
2. Print all metrics, trade count, best/worst trade, and a text-based mini equity curve.

**`compare`** — Side-by-side comparison
```
python main.py compare <run_id_1> <run_id_2> [<run_id_3> ...]
```
Behavior:
1. Call `db.compare_runs(run_ids)`.
2. Print a table with runs as columns, metrics as rows.

**`sweep`** — Grid search over parameters
```
python main.py sweep <strategy_name> <pair> <timeframe> --param <name> <min> <max> <step> [--param ...]
```
Behavior:
1. Generate all combinations of parameter values within specified ranges.
2. For each combination, run a backtest.
3. Sort results by Sharpe ratio descending.
4. Print ranked table: rank, params, Sharpe, return%, max DD, win rate.
5. Save all runs to SQLite and tag them with "sweep_{sweep_id}".

**`tag`** — Tag a run
```
python main.py tag <run_id> <tag>
```

---

## 11. Starter Strategies

### File: `strategies/rsi_reversion.py`

```python
class RsiReversion(BaseStrategy):
    """
    Buy when RSI crosses below oversold threshold.
    Sell when RSI crosses above overbought threshold.
    """

    def name(self) -> str:
        return "RSI Mean Reversion"

    def default_params(self) -> dict[str, ParamDef]:
        return {
            "rsi_period": ParamDef(default=14, min=5, max=50, step=1, description="RSI lookback period"),
            "oversold": ParamDef(default=30, min=15, max=40, step=1, description="Buy when RSI below this"),
            "overbought": ParamDef(default=70, min=60, max=85, step=1, description="Sell when RSI above this"),
        }

    def indicators(self, df: pd.DataFrame) -> pd.DataFrame:
        df["rsi"] = ta.momentum.RSIIndicator(
            df["close"], window=int(self.params["rsi_period"])
        ).rsi()
        return df

    def signal(self, row: pd.Series, position=None) -> Signal:
        rsi = row["rsi"]
        if rsi < self.params["oversold"] and position is None:
            strength = (self.params["oversold"] - rsi) / self.params["oversold"]
            return Signal(action=SignalAction.BUY, strength=min(strength, 1.0),
                          reason=f"RSI={rsi:.1f} < {self.params['oversold']}")
        if rsi > self.params["overbought"] and position is not None:
            strength = (rsi - self.params["overbought"]) / (100 - self.params["overbought"])
            return Signal(action=SignalAction.CLOSE, strength=min(strength, 1.0),
                          reason=f"RSI={rsi:.1f} > {self.params['overbought']}")
        return Signal(action=SignalAction.HOLD)
```

### File: `strategies/configs/rsi_reversion.json`

```json
{
    "strategy_id": "rsi_reversion_v1",
    "name": "RSI Mean Reversion",
    "version": "1.0.0",
    "type": "both",
    "pairs": ["BTC/USDT", "ETH/USDT", "SOL/USDT"],
    "timeframes": ["1h", "4h"],
    "parameters": {
        "rsi_period": {"default": 14, "min": 5, "max": 50, "step": 1, "description": "RSI lookback period"},
        "oversold": {"default": 30, "min": 15, "max": 40, "step": 1, "description": "Buy when RSI below this"},
        "overbought": {"default": 70, "min": 60, "max": 85, "step": 1, "description": "Sell when RSI above this"}
    },
    "risk_rules": {
        "stop_loss_pct": 0.03,
        "take_profit_pct": 0.06,
        "max_position_pct": 0.10,
        "max_leverage": 1.0,
        "trailing_stop_pct": null
    }
}
```

### File: `strategies/ma_crossover.py`

```python
class MaCrossover(BaseStrategy):
    """
    Buy when fast MA crosses above slow MA.
    Sell when fast MA crosses below slow MA.
    Optional ADX filter: only trade when ADX > threshold (trending market).
    """

    def name(self) -> str:
        return "MA Crossover"

    def default_params(self) -> dict[str, ParamDef]:
        return {
            "fast_period": ParamDef(default=9, min=3, max=50, step=1, description="Fast MA period"),
            "slow_period": ParamDef(default=21, min=10, max=200, step=1, description="Slow MA period"),
            "ma_type": ParamDef(default=0, min=0, max=1, step=1, description="0=SMA, 1=EMA"),
            "use_adx_filter": ParamDef(default=0, min=0, max=1, step=1, description="0=off, 1=on"),
            "adx_threshold": ParamDef(default=25, min=15, max=50, step=1, description="Min ADX to trade"),
        }

    def indicators(self, df: pd.DataFrame) -> pd.DataFrame:
        fast = int(self.params["fast_period"])
        slow = int(self.params["slow_period"])

        if self.params["ma_type"] == 0:  # SMA
            df["fast_ma"] = df["close"].rolling(window=fast).mean()
            df["slow_ma"] = df["close"].rolling(window=slow).mean()
        else:  # EMA
            df["fast_ma"] = df["close"].ewm(span=fast, adjust=False).mean()
            df["slow_ma"] = df["close"].ewm(span=slow, adjust=False).mean()

        # Previous values for crossover detection
        df["prev_fast_ma"] = df["fast_ma"].shift(1)
        df["prev_slow_ma"] = df["slow_ma"].shift(1)

        if self.params["use_adx_filter"]:
            df["adx"] = ta.trend.ADXIndicator(
                df["high"], df["low"], df["close"], window=14
            ).adx()

        return df

    def signal(self, row: pd.Series, position=None) -> Signal:
        # Check ADX filter
        if self.params["use_adx_filter"] and row.get("adx", 100) < self.params["adx_threshold"]:
            return Signal(action=SignalAction.HOLD, reason="ADX too low, no trend")

        # Bullish crossover: fast crosses above slow
        if (row["prev_fast_ma"] <= row["prev_slow_ma"] and
            row["fast_ma"] > row["slow_ma"] and position is None):
            gap = (row["fast_ma"] - row["slow_ma"]) / row["slow_ma"]
            return Signal(action=SignalAction.BUY, strength=min(gap * 10, 1.0),
                          reason=f"Bullish crossover: fast={row['fast_ma']:.2f} > slow={row['slow_ma']:.2f}")

        # Bearish crossover: fast crosses below slow
        if (row["prev_fast_ma"] >= row["prev_slow_ma"] and
            row["fast_ma"] < row["slow_ma"] and position is not None):
            gap = (row["slow_ma"] - row["fast_ma"]) / row["slow_ma"]
            return Signal(action=SignalAction.CLOSE, strength=min(gap * 10, 1.0),
                          reason=f"Bearish crossover: fast={row['fast_ma']:.2f} < slow={row['slow_ma']:.2f}")

        return Signal(action=SignalAction.HOLD)
```

### File: `strategies/configs/ma_crossover.json`

```json
{
    "strategy_id": "ma_crossover_v1",
    "name": "MA Crossover",
    "version": "1.0.0",
    "type": "both",
    "pairs": ["BTC/USDT", "ETH/USDT"],
    "timeframes": ["1h", "4h", "1d"],
    "parameters": {
        "fast_period": {"default": 9, "min": 3, "max": 50, "step": 1, "description": "Fast MA period"},
        "slow_period": {"default": 21, "min": 10, "max": 200, "step": 1, "description": "Slow MA period"},
        "ma_type": {"default": 0, "min": 0, "max": 1, "step": 1, "description": "0=SMA, 1=EMA"},
        "use_adx_filter": {"default": 0, "min": 0, "max": 1, "step": 1, "description": "0=off, 1=on"},
        "adx_threshold": {"default": 25, "min": 15, "max": 50, "step": 1, "description": "Min ADX to trade"}
    },
    "risk_rules": {
        "stop_loss_pct": 0.04,
        "take_profit_pct": 0.08,
        "max_position_pct": 0.10,
        "max_leverage": 1.0,
        "trailing_stop_pct": 0.03
    }
}
```

---

## 12. Testing Requirements

### File: `tests/test_fetcher.py`

- Test that `fetch_candles` returns a DataFrame with correct columns and dtypes.
- Test that `detect_gaps` finds intentionally inserted gaps.
- Test that `get_exchange` raises ValueError for invalid exchange names.

### File: `tests/test_storage.py`

- Test `save_candles` creates a Parquet file in the correct location.
- Test `load_candles` with date filtering returns correct subset.
- Test `merge_candles` deduplicates and sorts correctly.
- Test `list_available` returns correct metadata.
- Test `get_parquet_path` produces correct filenames.

### File: `tests/test_strategy.py`

- Test that `RsiReversion` adds an "rsi" column via `indicators()`.
- Test that `signal()` returns BUY when RSI < oversold with no position.
- Test that `signal()` returns CLOSE when RSI > overbought with a position.
- Test that `signal()` returns HOLD in neutral conditions.
- Test parameter clamping to bounds.
- Test `MaCrossover` crossover detection logic.
- Test `loader.load_strategy` returns a valid BaseStrategy instance.

### File: `tests/test_engine.py`

- **Synthetic test:** Create a simple DataFrame where close prices go [100, 90, 80, 90, 100, 110, 120]. Create a strategy that buys at 80 and sells at 120. Verify: exactly 1 trade, entry=80, exit=120, PnL is correct after fees.
- **Stop-loss test:** Set stop_loss_pct=0.05. Start position at 100. Feed candle with low=94. Verify position is closed at 95 (5% stop).
- **Take-profit test:** Set take_profit_pct=0.10. Start position at 100. Feed candle with high=111. Verify position is closed at 110 (10% TP).
- **No-lookahead test:** Create a strategy that tries to access `df` (should not be possible since it only receives `row`). Verify it works with only the row.
- **Futures test:** Run with leverage=3.0, verify margin and PnL calculations.
- **End-of-data test:** Open a position, don't close it. Verify engine force-closes at END_OF_DATA.

### File: `tests/test_metrics.py`

- Test `sharpe_ratio` with a known equity curve where the answer can be hand-calculated.
- Test `max_drawdown` with a curve [100, 110, 90, 95, 100] → drawdown should be ~18.18%.
- Test `win_rate` with 3 winning and 2 losing trades → 60%.
- Test `profit_factor` with known winning/losing amounts.
- Test all functions return 0.0 (not NaN or error) when given empty trade lists.
- Test `calculate_all` returns a dict with all expected keys.

---

## 13. Build Order

Build in this exact sequence. Each step must be testable before proceeding.

| Step | Files | Time est. | Test |
|------|-------|-----------|------|
| 1 | `config.py`, `models.py` | 1 hour | Models serialize/deserialize, settings load |
| 2 | `data/fetcher.py`, `data/storage.py` | 3 hours | Fetch 30d BTC/USDT 1h, verify Parquet file |
| 3 | `strategies/base.py`, `strategies/rsi_reversion.py`, `strategies/configs/rsi_reversion.json` | 2 hours | indicators() adds columns, signal() returns correct actions |
| 4 | `engine/order.py`, `engine/portfolio.py` | 3 hours | Synthetic buy→sell round-trip, PnL matches hand calc |
| 5 | `engine/metrics.py` | 2 hours | All metrics correct on known inputs |
| 6 | `engine/backtester.py` | 3 hours | Full backtest on RSI strategy produces sane results |
| 7 | `db.py`, results output | 1 hour | SQLite tables created, results saved/loaded |
| 8 | `main.py` (all commands) | 2 hours | Every CLI command works end-to-end |
| 9 | `strategies/ma_crossover.py`, `strategies/loader.py` | 2 hours | Second strategy works, dynamic loading works |
| 10 | `tests/` | 2 hours | All tests pass with `pytest tests/` |

**Total: ~21 hours of focused work (3 days).**

---

## 14. Design Rules

These rules apply to every file in the project. The coding agent must follow them strictly.

### Code style
- Python 3.11+ type hints on ALL function signatures.
- Docstrings on every class and public function.
- No `# type: ignore` comments — fix the actual types.
- Use `pathlib.Path` for all file paths, never string concatenation.
- Use f-strings for string formatting.

### Architecture rules
- **No circular imports.** Dependency flow: `config` → `models` → `data` → `strategies` → `engine` → `main`.
- **Strategies never import engine modules.** A strategy only imports from `models.py` and `ta`.
- **Engine never imports strategy implementations.** It only knows about `BaseStrategy`.
- **All data passes through models.** Never use raw dicts where a Pydantic model exists.
- **No global mutable state.** The only module-level singleton is `settings` in `config.py`.

### Error handling
- Use specific exceptions, not bare `except`.
- Raise `ValueError` for bad inputs, `FileNotFoundError` for missing files.
- Log warnings (using Python `logging`) for non-fatal issues (data gaps, parameter clamping).
- Never silently swallow errors.

### Numerical safety
- Check for division by zero in all metric calculations — return 0.0 if denominator is 0.
- Check for NaN in all outputs — replace NaN with 0.0 in metrics.
- Use `numpy.float64` for all financial calculations.

### File I/O
- Create directories with `Path.mkdir(parents=True, exist_ok=True)` before writing.
- Use `json.dump(..., indent=2)` for all JSON output.
- Use `pd.to_parquet(..., engine="pyarrow")` for Parquet writes.
- Always close file handles (use `with` statements).

---

## End of specification.

This document contains everything needed to build the system. Start with Step 1 and proceed sequentially. Each step produces testable output before the next begins.
