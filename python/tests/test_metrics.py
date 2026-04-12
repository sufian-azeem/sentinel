import pytest
import pandas as pd
import numpy as np
from datetime import datetime

from models import Trade, Side, ExitReason
from engine.metrics import (
    total_return_pct,
    sharpe_ratio,
    sortino_ratio,
    max_drawdown,
    max_drawdown_duration,
    win_rate,
    profit_factor,
    expectancy,
    avg_trade_duration,
    calmar_ratio,
    recovery_factor,
    total_fees_paid,
    best_trade,
    worst_trade,
    avg_winner,
    avg_loser,
    max_consecutive_wins,
    max_consecutive_losses,
    long_short_ratio,
    calculate_all,
)


def test_total_return_pct():
    result = total_return_pct(10000.0, 12000.0)
    assert result == 20.0


def test_sharpe_ratio():
    equity = [10000.0, 10500.0, 11000.0, 10500.0, 12000.0]
    ratio = sharpe_ratio(equity, "1h")
    assert isinstance(ratio, float)


def test_max_drawdown():
    equity = [10000.0, 12000.0, 10000.0, 11000.0]
    dd = max_drawdown(equity)
    assert dd > 0


def test_win_rate():
    trades = [
        Trade(
            pair="BTC/USDT",
            side=Side.BUY,
            entry_price=50000.0,
            exit_price=55000.0,
            quantity=0.1,
            entry_time=1000,
            exit_time=2000,
            pnl=500.0,
            pnl_pct=10.0,
            fee_total=10.0,
        ),
        Trade(
            pair="BTC/USDT",
            side=Side.BUY,
            entry_price=50000.0,
            exit_price=48000.0,
            quantity=0.1,
            entry_time=2000,
            exit_time=3000,
            pnl=-200.0,
            pnl_pct=-4.0,
            fee_total=10.0,
        ),
    ]
    wr = win_rate(trades)
    assert wr == 50.0


def test_profit_factor():
    trades = [
        Trade(
            pair="BTC/USDT",
            side=Side.BUY,
            entry_price=50000.0,
            exit_price=55000.0,
            quantity=0.1,
            entry_time=1000,
            exit_time=2000,
            pnl=500.0,
            pnl_pct=10.0,
            fee_total=10.0,
        ),
        Trade(
            pair="BTC/USDT",
            side=Side.BUY,
            entry_price=50000.0,
            exit_price=48000.0,
            quantity=0.1,
            entry_time=2000,
            exit_time=3000,
            pnl=-200.0,
            pnl_pct=-4.0,
            fee_total=10.0,
        ),
    ]
    pf = profit_factor(trades)
    assert pf == 2.5


def test_expectancy():
    trades = [
        Trade(
            pair="BTC/USDT",
            side=Side.BUY,
            entry_price=50000.0,
            exit_price=55000.0,
            quantity=0.1,
            entry_time=1000,
            exit_time=2000,
            pnl=500.0,
            pnl_pct=10.0,
            fee_total=10.0,
        ),
        Trade(
            pair="BTC/USDT",
            side=Side.BUY,
            entry_price=50000.0,
            exit_price=48000.0,
            quantity=0.1,
            entry_time=2000,
            exit_time=3000,
            pnl=-200.0,
            pnl_pct=-4.0,
            fee_total=10.0,
        ),
    ]
    exp = expectancy(trades)
    assert exp == 150.0


def test_calculate_all():
    trades = [
        Trade(
            pair="BTC/USDT",
            side=Side.BUY,
            entry_price=50000.0,
            exit_price=55000.0,
            quantity=0.1,
            entry_time=1000,
            exit_time=2000,
            pnl=500.0,
            pnl_pct=10.0,
            fee_total=10.0,
        ),
    ]
    equity = [10000.0, 10500.0, 11000.0]

    metrics = calculate_all(trades, equity, 10000.0, "1h")

    assert "total_return_pct" in metrics
    assert "sharpe_ratio" in metrics
    assert "max_drawdown_pct" in metrics
    assert metrics["total_trades"] == 1
