"""
MACD indicator — registered as 'macd'.

Columns added:
    macd_line       — MACD line (fast_ema - slow_ema)
    macd_signal     — Signal line (EMA of MACD line)
    macd_hist       — Histogram (macd_line - macd_signal)
    prev_macd_hist  — Histogram of previous bar
"""

import pandas as pd
import ta

from indicators.registry import IndicatorRegistry


def compute_macd(
    df: pd.DataFrame,
    macd_fast: int = 12,
    macd_slow: int = 26,
    macd_signal: int = 9,
) -> pd.DataFrame:
    """Compute MACD line, signal, and histogram."""
    macd = ta.trend.MACD(
        df["close"],
        window_fast=int(macd_fast),
        window_slow=int(macd_slow),
        window_sign=int(macd_signal),
    )
    df["macd_line"]      = macd.macd()
    df["macd_signal"]    = macd.macd_signal()
    df["macd_hist"]      = macd.macd_diff()
    df["prev_macd_hist"] = df["macd_hist"].shift(1)
    return df


IndicatorRegistry.register("macd", compute_macd)
