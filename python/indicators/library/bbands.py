"""
Bollinger Bands + Squeeze indicator — registered as 'bbands'.

Columns added:
    bb_upper        — upper band
    bb_mid          — middle band (SMA)
    bb_lower        — lower band
    bb_width        — (upper - lower) / mid  — normalized band width
    bb_pct          — %B — where price is within the band (0=lower, 1=upper)
    bb_squeeze      — 1 if bb_width < squeeze_threshold, else 0
    prev_bb_upper   — prior bar upper band (breakout detection)
    prev_bb_lower   — prior bar lower band
    prev_bb_width   — prior bar width (squeeze exit detection)
"""

import pandas as pd
import ta

from indicators.registry import IndicatorRegistry


def compute_bbands(
    df: pd.DataFrame,
    bb_period: int = 20,
    bb_std: float = 2.0,
    squeeze_pct: float = 0.03,
) -> pd.DataFrame:
    """Compute Bollinger Bands, width, %B, and squeeze flag."""
    bb = ta.volatility.BollingerBands(
        df["close"],
        window=int(bb_period),
        window_dev=float(bb_std),
    )
    df["bb_upper"] = bb.bollinger_hband()
    df["bb_mid"]   = bb.bollinger_mavg()
    df["bb_lower"] = bb.bollinger_lband()
    df["bb_width"] = (df["bb_upper"] - df["bb_lower"]) / df["bb_mid"].replace(0, float("nan"))
    df["bb_pct"]   = (df["close"] - df["bb_lower"]) / (df["bb_upper"] - df["bb_lower"]).replace(0, float("nan"))

    # Squeeze: band width below threshold = low volatility coiling
    df["bb_squeeze"]    = (df["bb_width"] < squeeze_pct).astype(float)
    df["prev_bb_upper"] = df["bb_upper"].shift(1)
    df["prev_bb_lower"] = df["bb_lower"].shift(1)
    df["prev_bb_width"] = df["bb_width"].shift(1)
    df["prev_bb_squeeze"] = df["bb_squeeze"].shift(1)
    return df


IndicatorRegistry.register("bbands", compute_bbands)
