"""
HTF EMA trend filter — registered as 'ema_htf'.

Resamples the LTF DataFrame to a higher timeframe (htf_multiplier × LTF),
computes EMA fast and slow on HTF closes, merges back with no lookahead
(only completed HTF candles are visible).

Columns added:
    htf_ema_fast    — fast EMA on the higher timeframe
    htf_ema_slow    — slow EMA on the higher timeframe
    htf_trend_bull  — True when htf_ema_fast > htf_ema_slow (HTF bullish)
    htf_trend_bear  — True when htf_ema_fast < htf_ema_slow (HTF bearish)
"""

import pandas as pd
import ta

from indicators.registry import IndicatorRegistry


def compute_ema_htf(
    df: pd.DataFrame,
    htf_multiplier: int = 4,
    htf_fast: int = 8,
    htf_slow: int = 21,
) -> pd.DataFrame:
    """
    Resample LTF bars to HTF, compute EMA alignment, merge back with no lookahead.

    The value visible at each LTF bar comes from the LAST COMPLETED HTF candle
    (the HTF series is shifted by 1 before merging, then forward-filled).
    """
    ltf_ms = int(df["timestamp"].diff().median())
    htf_ms = ltf_ms * htf_multiplier

    df = df.copy()
    df["_htf_period"] = (df["timestamp"] // htf_ms) * htf_ms

    # Build HTF close: last LTF close in each HTF bucket
    htf = (
        df.groupby("_htf_period", sort=True)
        .agg(_htf_close=("close", "last"))
        .reset_index()
    )

    htf["htf_ema_fast"] = ta.trend.EMAIndicator(
        htf["_htf_close"], window=int(htf_fast)
    ).ema_indicator()
    htf["htf_ema_slow"] = ta.trend.EMAIndicator(
        htf["_htf_close"], window=int(htf_slow)
    ).ema_indicator()

    # Shift by 1 HTF period — only completed HTF bars visible on LTF
    htf["htf_ema_fast"] = htf["htf_ema_fast"].shift(1)
    htf["htf_ema_slow"] = htf["htf_ema_slow"].shift(1)

    df = df.merge(
        htf[["_htf_period", "htf_ema_fast", "htf_ema_slow"]],
        on="_htf_period",
        how="left",
    )
    df[["htf_ema_fast", "htf_ema_slow"]] = (
        df[["htf_ema_fast", "htf_ema_slow"]].ffill()
    )
    df.drop(columns=["_htf_period"], inplace=True)

    df["htf_trend_bull"] = df["htf_ema_fast"] > df["htf_ema_slow"]
    df["htf_trend_bear"] = df["htf_ema_fast"] < df["htf_ema_slow"]

    return df


IndicatorRegistry.register("ema_htf", compute_ema_htf)
