"""
EMA Ribbon + ATR + Volume indicators — registered as 'ema_atr'.

Columns added:
    ema_fast            — fast EMA
    ema_mid             — mid EMA
    ema_slow            — slow EMA
    atr                 — Average True Range
    volume_ma           — rolling mean volume
    volume_ratio        — current volume / volume_ma
    prev_close          — previous bar close
    prev_high           — previous bar high
    prev_low            — previous bar low
    prev_open           — previous bar open
    prev_ema_fast       — previous bar fast EMA
    prev_atr            — previous bar ATR (used for ATR expansion filter)
    prev_volume_ratio   — previous bar volume ratio (used for bounce volume filter)
"""

import pandas as pd
import ta

from indicators.registry import IndicatorRegistry


def compute_ema_atr(
    df: pd.DataFrame,
    ema_fast: int = 8,
    ema_mid: int = 21,
    ema_slow: int = 50,
    atr_period: int = 14,
    volume_period: int = 20,
) -> pd.DataFrame:
    """Compute EMA ribbon, ATR, and volume ratio columns."""
    df["ema_fast"] = ta.trend.EMAIndicator(df["close"], window=int(ema_fast)).ema_indicator()
    df["ema_mid"]  = ta.trend.EMAIndicator(df["close"], window=int(ema_mid)).ema_indicator()
    df["ema_slow"] = ta.trend.EMAIndicator(df["close"], window=int(ema_slow)).ema_indicator()
    df["atr"]      = ta.volatility.AverageTrueRange(
        df["high"], df["low"], df["close"], window=int(atr_period)
    ).average_true_range()
    df["volume_ma"]         = df["volume"].rolling(window=int(volume_period)).mean()
    df["volume_ratio"]      = df["volume"] / df["volume_ma"].replace(0, float("nan"))
    df["prev_close"]        = df["close"].shift(1)
    df["prev_high"]         = df["high"].shift(1)
    df["prev_low"]          = df["low"].shift(1)
    df["prev_open"]         = df["open"].shift(1)
    df["prev_ema_fast"]     = df["ema_fast"].shift(1)
    df["prev_atr"]          = df["atr"].shift(1)
    df["prev_volume_ratio"] = df["volume_ratio"].shift(1)
    return df


IndicatorRegistry.register("ema_atr", compute_ema_atr)
