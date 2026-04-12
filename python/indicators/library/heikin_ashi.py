import pandas as pd
from indicators.registry import IndicatorRegistry


def compute_heikin_ashi(
    df: pd.DataFrame,
    prev_ha_open: "float | None" = None,
    prev_ha_close: "float | None" = None,
) -> pd.DataFrame:
    """
    Heikin Ashi candles. Adds:
        ha_open, ha_close, ha_high, ha_low, ha_body
        prev_ha_open, prev_ha_close, prev_ha_low, prev_ha_body

    When prev_ha_open/prev_ha_close are provided the first ha_open is seeded
    from stored values instead of raw candle data — used for incremental scans.
    When ha_open already exists in df the function is a no-op (guard for
    incremental mode where indicators are pre-computed before IndicatorService).
    """
    if "ha_open" in df.columns:
        return df  # already computed — incremental mode pre-seeded externally

    n = len(df)

    ha_close = (df["open"] + df["high"] + df["low"] + df["close"]) / 4.0

    ha_open = ha_close.copy()
    if prev_ha_open is not None and prev_ha_close is not None:
        ha_open.iloc[0] = (prev_ha_open + prev_ha_close) / 2.0
    else:
        ha_open.iloc[0] = (df["open"].iloc[0] + df["close"].iloc[0]) / 2.0
    for i in range(1, n):
        ha_open.iloc[i] = (ha_open.iloc[i - 1] + ha_close.iloc[i - 1]) / 2.0

    ha_high = pd.concat([df["high"], ha_open, ha_close], axis=1).max(axis=1)
    ha_low  = pd.concat([df["low"],  ha_open, ha_close], axis=1).min(axis=1)
    ha_body = (ha_close - ha_open).abs()

    df["ha_open"]  = ha_open
    df["ha_close"] = ha_close
    df["ha_high"]  = ha_high
    df["ha_low"]   = ha_low
    df["ha_body"]  = ha_body

    df["prev_ha_open"]  = ha_open.shift(1)
    df["prev_ha_close"] = ha_close.shift(1)
    df["prev_ha_high"]  = ha_high.shift(1)
    df["prev_ha_low"]   = ha_low.shift(1)
    df["prev_ha_body"]  = ha_body.shift(1)

    return df


IndicatorRegistry.register("heikin_ashi", compute_heikin_ashi)
