"""
Swing high / swing low indicator — registered as 'swing'.

Computes the rolling N-bar swing high and swing low, shifted by 1
so the current bar is never included (no lookahead).

Columns added:
    swing_high  — highest high over the last `swing_lookback` bars (excluding current)
    swing_low   — lowest low  over the last `swing_lookback` bars (excluding current)
"""

import pandas as pd

from indicators.registry import IndicatorRegistry


def compute_swing(
    df: pd.DataFrame,
    swing_lookback: int = 20,
) -> pd.DataFrame:
    """Add rolling swing high and swing low columns."""
    lookback = int(swing_lookback)
    # shift(1) so the current bar is excluded — only completed bars
    df["swing_high"] = df["high"].shift(1).rolling(lookback).max()
    df["swing_low"]  = df["low"].shift(1).rolling(lookback).min()
    return df


IndicatorRegistry.register("swing", compute_swing)
