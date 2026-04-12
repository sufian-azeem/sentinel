"""
Moving-average indicators — registered as 'ma_crossover'.

'ma_crossover'
    Columns added:
        ma_fast    — SMA of fast_period
        ma_slow    — SMA of slow_period
"""

import pandas as pd
import ta

from indicators.registry import IndicatorRegistry


def compute_ma_crossover(
    df: pd.DataFrame,
    fast_period: int = 10,
    slow_period: int = 50,
) -> pd.DataFrame:
    """Compute fast and slow SMAs for a crossover strategy."""
    df["ma_fast"] = ta.trend.SMAIndicator(
        df["close"], window=int(fast_period)
    ).sma_indicator()
    df["ma_slow"] = ta.trend.SMAIndicator(
        df["close"], window=int(slow_period)
    ).sma_indicator()
    return df


IndicatorRegistry.register("ma_crossover", compute_ma_crossover)
