import numpy as np
from typing import List

from models import Trade


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


def total_return_pct(starting_capital: float, final_equity: float) -> float:
    if starting_capital == 0:
        return 0.0
    return ((final_equity - starting_capital) / starting_capital) * 100


def sharpe_ratio(
    equity_curve: List[float], timeframe: str, risk_free_rate: float = 0.0
) -> float:
    if len(equity_curve) < 2:
        return 0.0

    returns = []
    for i in range(1, len(equity_curve)):
        if equity_curve[i - 1] != 0:
            ret = (equity_curve[i] - equity_curve[i - 1]) / equity_curve[i - 1]
            returns.append(ret)

    if not returns:
        return 0.0

    returns = np.array(returns)
    std = np.std(returns)
    if std == 0 or np.isnan(std):
        return 0.0

    periods_per_year = PERIODS_PER_YEAR.get(timeframe, 8760)
    annualization_factor = np.sqrt(periods_per_year)

    mean_return = np.mean(returns)
    excess_returns = mean_return - (risk_free_rate / periods_per_year)

    return (excess_returns / std) * annualization_factor


def sortino_ratio(
    equity_curve: List[float], timeframe: str, risk_free_rate: float = 0.0
) -> float:
    if len(equity_curve) < 2:
        return 0.0

    returns = []
    for i in range(1, len(equity_curve)):
        if equity_curve[i - 1] != 0:
            ret = (equity_curve[i] - equity_curve[i - 1]) / equity_curve[i - 1]
            returns.append(ret)

    if not returns:
        return 0.0

    returns = np.array(returns)
    downside_returns = returns[returns < 0]

    if len(downside_returns) == 0:
        return 0.0

    downside_std = np.std(downside_returns)
    if downside_std == 0 or np.isnan(downside_std):
        return 0.0

    periods_per_year = PERIODS_PER_YEAR.get(timeframe, 8760)
    annualization_factor = np.sqrt(periods_per_year)

    mean_return = np.mean(returns)
    excess_returns = mean_return - (risk_free_rate / periods_per_year)

    return (excess_returns / downside_std) * annualization_factor


def max_drawdown(equity_curve: List[float]) -> float:
    if not equity_curve:
        return 0.0

    running_max = np.maximum.accumulate(equity_curve)
    drawdowns = (np.array(equity_curve) - running_max) / running_max

    return abs(np.min(drawdowns)) * 100


def max_drawdown_duration(equity_curve: List[float]) -> int:
    if not equity_curve:
        return 0

    equity = np.array(equity_curve)
    running_max = np.maximum.accumulate(equity)
    in_drawdown = equity < running_max

    max_duration = 0
    current_duration = 0

    for i in range(len(in_drawdown)):
        if in_drawdown[i]:
            current_duration += 1
            max_duration = max(max_duration, current_duration)
        else:
            current_duration = 0

    return max_duration


def win_rate(trades: List[Trade]) -> float:
    if not trades:
        return 0.0

    winning_trades = sum(1 for t in trades if t.pnl > 0)
    return (winning_trades / len(trades)) * 100


def profit_factor(trades: List[Trade]) -> float:
    if not trades:
        return 0.0

    gross_profit = sum(t.pnl for t in trades if t.pnl > 0)
    gross_loss = abs(sum(t.pnl for t in trades if t.pnl < 0))

    if gross_loss == 0:
        return 0.0

    return gross_profit / gross_loss


def expectancy(trades: List[Trade]) -> float:
    if not trades:
        return 0.0

    return sum(t.pnl for t in trades) / len(trades)


def avg_trade_duration(trades: List[Trade], timeframe: str) -> float:
    if not trades:
        return 0.0

    durations_ms = [t.exit_time - t.entry_time for t in trades]
    avg_ms = sum(durations_ms) / len(durations_ms)

    hours_per_ms = 1 / (1000 * 60 * 60)
    return avg_ms * hours_per_ms


def calmar_ratio(equity_curve: List[float], timeframe: str) -> float:
    if len(equity_curve) < 2:
        return 0.0

    periods_per_year = PERIODS_PER_YEAR.get(timeframe, 8760)
    periods = len(equity_curve)

    final_equity = equity_curve[-1]
    starting_capital = equity_curve[0]

    if starting_capital == 0:
        return 0.0

    total_return = (final_equity - starting_capital) / starting_capital
    annualized_return = total_return * (periods_per_year / periods)

    dd = max_drawdown(equity_curve)
    if dd == 0:
        return 0.0

    return annualized_return / (dd / 100)


def recovery_factor(
    trades: List[Trade], equity_curve: List[float], starting_capital: float
) -> float:
    if not equity_curve or starting_capital == 0:
        return 0.0

    net_profit = equity_curve[-1] - starting_capital
    dd = max_drawdown(equity_curve)

    if dd == 0:
        return 0.0

    running_max = max(equity_curve)
    max_dd_absolute = (dd / 100) * running_max

    if max_dd_absolute == 0:
        return 0.0

    return net_profit / max_dd_absolute


def total_fees_paid(trades: List[Trade]) -> float:
    return sum(t.fee_total for t in trades)


def best_trade(trades: List[Trade]) -> float:
    if not trades:
        return 0.0
    return max(t.pnl for t in trades)


def worst_trade(trades: List[Trade]) -> float:
    if not trades:
        return 0.0
    return min(t.pnl for t in trades)


def avg_winner(trades: List[Trade]) -> float:
    winners = [t.pnl for t in trades if t.pnl > 0]
    if not winners:
        return 0.0
    return sum(winners) / len(winners)


def avg_loser(trades: List[Trade]) -> float:
    losers = [t.pnl for t in trades if t.pnl < 0]
    if not losers:
        return 0.0
    return sum(losers) / len(losers)


def max_consecutive_wins(trades: List[Trade]) -> int:
    if not trades:
        return 0

    max_streak = 0
    current_streak = 0

    for t in trades:
        if t.pnl > 0:
            current_streak += 1
            max_streak = max(max_streak, current_streak)
        else:
            current_streak = 0

    return max_streak


def max_consecutive_losses(trades: List[Trade]) -> int:
    if not trades:
        return 0

    max_streak = 0
    current_streak = 0

    for t in trades:
        if t.pnl <= 0:
            current_streak += 1
            max_streak = max(max_streak, current_streak)
        else:
            current_streak = 0

    return max_streak


def long_short_ratio(trades: List[Trade]) -> dict:
    longs = sum(1 for t in trades if t.side.value == "buy")
    shorts = sum(1 for t in trades if t.side.value == "sell")

    ratio = longs / shorts if shorts > 0 else float("inf") if longs > 0 else 0.0

    return {"longs": longs, "shorts": shorts, "ratio": ratio}


def calculate_all(
    trades: List[Trade],
    equity_curve: List[float],
    starting_capital: float,
    timeframe: str,
) -> dict[str, float]:
    if not equity_curve:
        equity_curve = [starting_capital]

    final_equity = equity_curve[-1] if equity_curve else starting_capital

    return {
        "total_return_pct": total_return_pct(starting_capital, final_equity),
        "sharpe_ratio": sharpe_ratio(equity_curve, timeframe),
        "sortino_ratio": sortino_ratio(equity_curve, timeframe),
        "max_drawdown_pct": max_drawdown(equity_curve),
        "max_drawdown_duration": max_drawdown_duration(equity_curve),
        "win_rate_pct": win_rate(trades),
        "profit_factor": profit_factor(trades),
        "expectancy": expectancy(trades),
        "avg_trade_duration_hours": avg_trade_duration(trades, timeframe),
        "calmar_ratio": calmar_ratio(equity_curve, timeframe),
        "recovery_factor": recovery_factor(trades, equity_curve, starting_capital),
        "total_trades": len(trades),
        "total_fees_paid": total_fees_paid(trades),
        "best_trade": best_trade(trades),
        "worst_trade": worst_trade(trades),
        "avg_winner": avg_winner(trades),
        "avg_loser": avg_loser(trades),
        "max_consecutive_wins": max_consecutive_wins(trades),
        "max_consecutive_losses": max_consecutive_losses(trades),
        "longs": long_short_ratio(trades)["longs"],
        "shorts": long_short_ratio(trades)["shorts"],
        "long_short_ratio": long_short_ratio(trades)["ratio"],
        "final_equity": final_equity,
        "net_profit": final_equity - starting_capital,
    }
