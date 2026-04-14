from dataclasses import dataclass
from pydantic import BaseModel, Field
from typing import Optional
from enum import Enum
import uuid
from datetime import datetime


@dataclass
class Candle:
    """Lightweight OHLC container for passing candle data to indicator helpers."""
    open: float
    high: float
    low: float
    close: float

    @property
    def body(self) -> float:
        return abs(self.close - self.open)


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
    TAKE_PROFIT_1 = "take_profit_1"
    TAKE_PROFIT_2 = "take_profit_2"
    TRAILING_STOP = "trailing_stop"
    LIQUIDATION = "liquidation"
    END_OF_DATA = "end_of_data"


class StrategyType(str, Enum):
    SPOT = "spot"
    FUTURES = "futures"
    BOTH = "both"


class ParamDef(BaseModel):
    default: float
    min: float
    max: float
    step: float
    description: str = ""


class IndicatorRequest(BaseModel):
    """Declares one indicator a strategy needs computed before the backtest loop."""
    name: str
    params: dict = {}


class RiskRules(BaseModel):
    stop_loss_pct: Optional[float] = 0.03
    take_profit_pct: Optional[float] = 0.06
    max_position_pct: float = 0.10
    max_leverage: float = 1.0
    trailing_stop_pct: Optional[float] = None


class StrategyConfig(BaseModel):
    strategy_id: str
    name: str
    version: str = "1.0.0"
    type: StrategyType = StrategyType.BOTH
    pairs: list[str] = ["BTC/USDT"]
    timeframes: list[str] = ["1h"]
    parameters: dict[str, ParamDef] = {}
    risk_rules: RiskRules = RiskRules()


class Signal(BaseModel):
    action: SignalAction = SignalAction.HOLD
    strength: float = 0.0
    reason: str = ""
    order_type: OrderType = OrderType.MARKET
    limit_price: Optional[float] = None
    close_pct: float = 1.0   # fraction of position to close (0.5 = 50%, 1.0 = 100%)
    sl_price:  Optional[float] = None   # strategy-defined SL price
    tp1_price: Optional[float] = None   # TP1 price (partial close 50%)
    tp2_price: Optional[float] = None   # TP2 price (close remaining 100%)
    entry_type: Optional[str] = None    # "Pullback" or "Awakening" (set on BUY signals)
    conditions: dict = {}               # per-condition pass/fail breakdown + indicator values


class Order(BaseModel):
    order_id: str = Field(default_factory=lambda: str(uuid.uuid4())[:8])
    timestamp: int
    side: Side
    order_type: OrderType = OrderType.MARKET
    pair: str
    quantity: float
    price: float
    filled_price: Optional[float] = None
    fee: float = 0.0
    slippage: float = 0.0
    status: OrderStatus = OrderStatus.PENDING
    leverage: float = 1.0
    sl_price:  Optional[float] = None
    tp1_price: Optional[float] = None
    tp2_price: Optional[float] = None


class Position(BaseModel):
    position_id: str = Field(default_factory=lambda: str(uuid.uuid4())[:8])
    pair: str
    side: Side
    entry_price: float
    quantity: float
    entry_time: int
    leverage: float = 1.0
    unrealized_pnl: float = 0.0
    highest_price_since_entry: float = 0.0
    lowest_price_since_entry: float = 999999999.0
    liquidation_price: Optional[float] = None
    sl_price:  Optional[float] = None
    tp1_price: Optional[float] = None
    tp2_price: Optional[float] = None
    tp1_hit:   bool = False


class Trade(BaseModel):
    trade_id: str = Field(default_factory=lambda: str(uuid.uuid4())[:8])
    pair: str
    side: Side
    entry_price: float
    exit_price: float
    quantity: float
    entry_time: int
    exit_time: int
    pnl: float
    pnl_pct: float
    fee_total: float
    leverage: float = 1.0
    exit_reason: ExitReason = ExitReason.SIGNAL
    sl_price: Optional[float] = None


class BacktestResult(BaseModel):
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
    equity_curve: list[float]
    equity_timestamps: list[int]
    metrics: dict[str, float]
    metadata: dict = {}
    created_at: str = Field(default_factory=lambda: datetime.utcnow().isoformat())
