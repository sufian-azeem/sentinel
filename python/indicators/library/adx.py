"""
ADX (Average Directional Index) indicator — registered as 'adx'.

Columns added:
    adx         — ADX value (trend strength, 0–100)
    adx_3_ago   — ADX value N candles ago (default 3) for rising/falling check
"""

import pandas as pd
import ta

from indicators.registry import IndicatorRegistry


def compute_adx(
    df: pd.DataFrame,
    adx_period: int = 14,
    adx_lookback: int = 3,
) -> pd.DataFrame:
    """Compute ADX and its lookback-shifted value for trend-strength filtering."""
    df["adx"]       = ta.trend.ADXIndicator(
        df["high"], df["low"], df["close"], window=int(adx_period)
    ).adx()
    df["adx_3_ago"] = df["adx"].shift(int(adx_lookback))
    return df


IndicatorRegistry.register("adx", compute_adx)
