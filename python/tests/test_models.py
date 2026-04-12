import pytest
import pandas as pd
from datetime import datetime

from models import (
    ParamDef,
    RiskRules,
    StrategyConfig,
    Signal,
    SignalAction,
    Order,
    Position,
    Trade,
    BacktestResult,
    Side,
    OrderType,
    OrderStatus,
    ExitReason,
)


def test_param_def():
    param = ParamDef(default=14, min=5, max=50, step=1, description="RSI period")
    assert param.default == 14
    assert param.min == 5
    assert param.max == 50


def test_risk_rules():
    rules = RiskRules(
        stop_loss_pct=0.03,
        take_profit_pct=0.06,
        max_position_pct=0.10,
        max_leverage=1.0,
    )
    assert rules.stop_loss_pct == 0.03
    assert rules.max_leverage == 1.0


def test_signal():
    signal = Signal(
        action=SignalAction.BUY,
        strength=0.8,
        reason="RSI oversold",
        order_type=OrderType.MARKET,
    )
    assert signal.action == SignalAction.BUY
    assert signal.strength == 0.8


def test_order():
    order = Order(
        timestamp=1000000, side=Side.BUY, pair="BTC/USDT", quantity=0.1, price=50000.0
    )
    assert order.side == Side.BUY
    assert order.pair == "BTC/USDT"
    assert order.status == OrderStatus.PENDING


def test_position():
    position = Position(
        pair="BTC/USDT",
        side=Side.BUY,
        entry_price=50000.0,
        quantity=0.1,
        entry_time=1000000,
    )
    assert position.side == Side.BUY
    assert position.entry_price == 50000.0


def test_trade():
    trade = Trade(
        pair="BTC/USDT",
        side=Side.BUY,
        entry_price=50000.0,
        exit_price=55000.0,
        quantity=0.1,
        entry_time=1000000,
        exit_time=2000000,
        pnl=500.0,
        pnl_pct=10.0,
        fee_total=10.0,
    )
    assert trade.pnl == 500.0
    assert trade.pnl_pct == 10.0


def test_backtest_result():
    result = BacktestResult(
        strategy_id="test_strategy",
        strategy_name="Test Strategy",
        params_used={"rsi_period": 14},
        pair="BTC/USDT",
        timeframe="1h",
        start_date="2024-01-01",
        end_date="2024-12-31",
        starting_capital=10000.0,
        final_equity=12000.0,
        total_trades=10,
        trades=[],
        equity_curve=[10000.0, 10500.0, 11000.0, 11500.0, 12000.0],
        equity_timestamps=[1000, 2000, 3000, 4000, 5000],
        metrics={
            "total_return_pct": 20.0,
            "sharpe_ratio": 1.5,
            "max_drawdown_pct": 5.0,
            "win_rate_pct": 60.0,
        },
    )
    assert result.total_trades == 10
    assert result.final_equity == 12000.0
    assert result.metrics["sharpe_ratio"] == 1.5


def test_serialization():
    signal = Signal(action=SignalAction.BUY, strength=0.5)
    data = signal.model_dump()
    assert data["action"] == "buy"
    assert data["strength"] == 0.5
