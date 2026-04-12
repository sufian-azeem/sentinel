"""
RSI indicator — registered as 'rsi'.

Columns added:
    rsi    — Relative Strength Index (0–100)
"""

import pandas as pd
import ta

from indicators.registry import IndicatorRegistry


def compute_rsi(df: pd.DataFrame, rsi_period: int = 14) -> pd.DataFrame:
    """Compute RSI and add 'rsi' and 'prev_rsi' columns."""
    df["rsi"]      = ta.momentum.RSIIndicator(df["close"], window=int(rsi_period)).rsi()
    df["prev_rsi"] = df["rsi"].shift(1)
    return df


IndicatorRegistry.register("rsi", compute_rsi)
