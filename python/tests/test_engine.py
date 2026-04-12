import pytest
import pandas as pd
import numpy as np

from engine.clock import MarketClock
from engine.order import OrderManager
from engine.portfolio import Portfolio
from engine.trade_ledger import TradeLedger
from models import RiskRules, Side, OrderType, OrderStatus, ExitReason


def test_market_clock():
    df = pd.DataFrame({"timestamp": [1000, 2000, 3000], "close": [50000, 51000, 52000]})

    clock = MarketClock(df)

    assert clock.has_next() is True

    row = clock.next()
    assert row["close"] == 50000

    assert clock.has_next() is True
    clock.next()
    clock.next()

    assert clock.has_next() is False


def test_order_manager():
    manager = OrderManager(fee_rate=0.001, slippage_pct=0.0005)

    order = manager.create_order(
        side=Side.BUY,
        pair="BTC/USDT",
        quantity=0.1,
        price=50000,
        timestamp=1000,
        order_type=OrderType.MARKET,
    )

    assert order.status == OrderStatus.FILLED
    assert order.filled_price is not None


def test_portfolio_initialization():
    rules = RiskRules()
    portfolio = Portfolio(
        starting_capital=10000.0, fee_rate=0.001, slippage_pct=0.0005, risk_rules=rules
    )

    assert portfolio.balance == 10000.0
    assert len(portfolio.positions) == 0
    assert len(portfolio.trades) == 0


def test_portfolio_calculate_position_size():
    from models import Signal, SignalAction

    rules = RiskRules(max_position_pct=0.1)
    portfolio = Portfolio(
        starting_capital=10000.0, fee_rate=0.001, slippage_pct=0.0005, risk_rules=rules
    )

    signal = Signal(action=SignalAction.BUY, strength=1.0)
    size = portfolio.calculate_position_size(signal, 50000)

    assert size > 0


def test_trade_ledger():
    ledger = TradeLedger()

    from models import Trade

    trade = Trade(
        pair="BTC/USDT",
        side=Side.BUY,
        entry_price=50000,
        exit_price=55000,
        quantity=0.1,
        entry_time=1000,
        exit_time=2000,
        pnl=500,
        pnl_pct=10,
        fee_total=10,
    )

    ledger.record_trade(trade)

    assert len(ledger.all_trades()) == 1
    assert len(ledger.trades_for_pair("BTC/USDT")) == 1
    assert len(ledger.trades_for_pair("ETH/USDT")) == 0
