"""
Donchian Channel indicator — registered as 'donchian'.

Columns added:
    dc_upper    — highest high over the lookback period
    dc_lower    — lowest low over the lookback period
    dc_mid      — midpoint of upper and lower
    prev_dc_upper — prior bar's upper channel (for breakout detection)
    prev_dc_lower — prior bar's lower channel
"""

import pandas as pd

from indicators.registry import IndicatorRegistry


def compute_donchian(df: pd.DataFrame, dc_period: int = 20) -> pd.DataFrame:
    """Compute Donchian Channel upper, lower, and midpoint."""
    df["dc_upper"]      = df["high"].rolling(window=int(dc_period)).max()
    df["dc_lower"]      = df["low"].rolling(window=int(dc_period)).min()
    df["dc_mid"]        = (df["dc_upper"] + df["dc_lower"]) / 2
    df["prev_dc_upper"] = df["dc_upper"].shift(1)
    df["prev_dc_lower"] = df["dc_lower"].shift(1)
    return df


IndicatorRegistry.register("donchian", compute_donchian)
