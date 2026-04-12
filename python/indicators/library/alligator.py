from dataclasses import dataclass

import pandas as pd

from indicators.registry import IndicatorRegistry
from models import Candle


@dataclass
class AlligatorValues:
    """Snapshot of alligator line values (jaw, teeth, lips) for a single bar."""
    jaw: float
    teeth: float
    lips: float


class Alligator:
    """
    Stateless helper for interpreting alligator values.
    Pass AlligatorValues built from either LTF (_off) or HTF (htf_*_off) columns —
    the class has no knowledge of which timeframe it is operating on.
    """

    # ── Trend ──────────────────────────────────────────────────────────────────

    @staticmethod
    def is_bullish(a: AlligatorValues) -> bool:
        """Lines in bullish order: lips > teeth > jaw"""
        return a.lips > a.teeth > a.jaw

    @staticmethod
    def is_sleeping(a: AlligatorValues) -> bool:
        """Lines tangled — not in clear bull or bear order"""
        return not (a.lips > a.teeth > a.jaw) and not (a.jaw > a.teeth > a.lips)

    @staticmethod
    def lips_crossed_teeth(a: AlligatorValues) -> bool:
        """Displaced lips > teeth — chart line order confirmed (used for awakening)."""
        return a.lips > a.teeth

    # ── Candle position ────────────────────────────────────────────────────────
    # All functions return False if the alligator is not in bullish order —
    # position checks are undefined on a sleeping/tangled alligator.

    @staticmethod
    def is_candle_above(candle: Candle, a: AlligatorValues) -> bool:
        """Entire candle above all lines: low > lips"""
        if not Alligator.is_bullish(a):
            return False
        return candle.low > a.lips

    @staticmethod
    def is_candle_touching(candle: Candle, a: AlligatorValues) -> bool:
        """Candle dipped into zone but closed back above lips: low <= lips and close > lips"""
        if not Alligator.is_bullish(a):
            return False
        return candle.low <= a.lips and candle.close > a.lips

    @staticmethod
    def is_candle_in_zone(candle: Candle, a: AlligatorValues) -> bool:
        """Candle closed inside the alligator zone: low >= jaw and close <= lips"""
        if not Alligator.is_bullish(a):
            return False
        return candle.low >= a.jaw and candle.close <= a.lips

    @staticmethod
    def is_candle_below(candle: Candle, a: AlligatorValues) -> bool:
        """Candle closed at or below jaw"""
        if not Alligator.is_bullish(a):
            return False
        return candle.close <= a.jaw


def _off(series: pd.Series, period: int) -> pd.Series:
    """SMMA seeded with SMA — matches the official Bill Williams PineScript formula."""
    sma_seed = series.rolling(period).mean()
    result = series.copy().astype(float) * float("nan")
    first = sma_seed.first_valid_index()
    if first is None:
        return result
    idx = series.index.get_loc(first)
    result.iloc[idx] = sma_seed.iloc[idx]
    for i in range(idx + 1, len(series)):
        result.iloc[i] = (result.iloc[i - 1] * (period - 1) + series.iloc[i]) / period
    return result


def _off_seeded(series: pd.Series, period: int, seed: float) -> pd.Series:
    """SMMA seeded with a known value — skips the SMA warmup phase entirely.

    Use this when continuing an alligator computation from a previously stored
    SMMA value (incremental / progressive scan mode).
    """
    result = series.copy().astype(float) * float("nan")
    result.iloc[0] = seed
    for i in range(1, len(series)):
        result.iloc[i] = (result.iloc[i - 1] * (period - 1) + series.iloc[i]) / period
    return result


def _hl2(df: pd.DataFrame) -> pd.Series:
    return (df["high"] + df["low"]) / 2.0


def _alligator_on_series(
    src: pd.Series,
    jaw_period: int, jaw_shift: int,
    teeth_period: int, teeth_shift: int,
    lips_period: int, lips_shift: int,
) -> dict[str, pd.Series]:
    """
    Compute all alligator values for a given source series.
    jaw/teeth/lips       = forward-displaced lines (what appears on the chart)
    jaw_off/teeth_off/lips_off = raw SMMA values (no offset, internal only)
    """
    jaw_smma   = _off(src, jaw_period)
    teeth_smma = _off(src, teeth_period)
    lips_smma  = _off(src, lips_period)
    return {
        "jaw":       jaw_smma.shift(jaw_shift),     # forward-displaced (chart value)
        "teeth":     teeth_smma.shift(teeth_shift),
        "lips":      lips_smma.shift(lips_shift),
        "jaw_off":   jaw_smma,                      # raw SMMA (no offset)
        "teeth_off": teeth_smma,
        "lips_off":  lips_smma,
    }


def _resample_and_merge(
    df: pd.DataFrame,
    multiplier: int,
    prefix: str,
    jaw_period: int, jaw_shift: int,
    teeth_period: int, teeth_shift: int,
    lips_period: int, lips_shift: int,
) -> pd.DataFrame:
    """
    Resample df to a higher timeframe, compute alligator, merge back with no lookahead.
    All output columns are prefixed with `prefix` (e.g. 'htf_' or 'macro_htf_').
    """
    ltf_ms = int(df["timestamp"].diff().median())
    htf_ms = ltf_ms * multiplier
    bucket = "_bucket"

    df = df.copy()
    df[bucket] = (df["timestamp"] // htf_ms) * htf_ms
    df["_hl2"] = _hl2(df)

    htf = df.groupby(bucket, sort=True).agg(src=("_hl2", "mean")).reset_index()
    df.drop(columns=["_hl2"], inplace=True)

    lines = _alligator_on_series(
        htf["src"],
        jaw_period, jaw_shift,
        teeth_period, teeth_shift,
        lips_period, lips_shift,
    )

    cols = {}
    for key, series in lines.items():
        cols[f"{prefix}{key}"] = series.shift(1)   # shift(1) = no lookahead
    htf_out = pd.DataFrame(cols)
    htf_out[bucket] = htf[bucket].values

    df = df.merge(htf_out, on=bucket, how="left")
    out_cols = list(cols.keys())
    df[out_cols] = df[out_cols].ffill()
    df.drop(columns=[bucket], inplace=True)

    p = prefix
    df[f"{p}bull"] = (df[f"{p}lips_off"] > df[f"{p}teeth_off"]) & (df[f"{p}teeth_off"] > df[f"{p}jaw_off"])
    df[f"{p}bear"] = (df[f"{p}jaw_off"]  > df[f"{p}teeth_off"]) & (df[f"{p}teeth_off"] > df[f"{p}lips_off"])
    jaw_safe = df[f"{p}jaw_off"].replace(0.0, float("nan"))
    df[f"{p}spread_pct"] = (df[f"{p}lips_off"] - df[f"{p}jaw_off"]) / jaw_safe * 100.0

    return df


# ── Public compute functions ──────────────────────────────────────────────────

def compute_alligator(
    df: pd.DataFrame,
    jaw_period: int = 13, jaw_shift: int = 8,
    teeth_period: int = 8, teeth_shift: int = 5,
    lips_period: int = 5,  lips_shift: int = 3,
    jaw_seed: "float | None" = None,
    teeth_seed: "float | None" = None,
    lips_seed: "float | None" = None,
) -> pd.DataFrame:
    """
    LTF alligator on hl2 source. Adds:
        jaw, teeth, lips               — shifted lines (for price zone checks)
        jaw_off, teeth_off, lips_off   — unshifted SMMA (for direction checks)
        ltf_bull, ltf_bear, ltf_spread_pct
        prev_low/high/close/open, prev_body_ratio, prev_close_vs_teeth_pct
        alignment_streak

    When jaw_seed/teeth_seed/lips_seed are provided the SMMA is seeded from
    stored values instead of warming up from SMA — used for incremental scans.
    When jaw_off already exists in df the function is a no-op (guard for
    incremental mode where indicators are pre-computed before IndicatorService).
    """
    if "jaw_off" in df.columns:
        return df  # already computed — incremental mode pre-seeded externally

    src = _hl2(df)
    if jaw_seed is not None and teeth_seed is not None and lips_seed is not None:
        jaw_smma   = _off_seeded(src, jaw_period,   jaw_seed)
        teeth_smma = _off_seeded(src, teeth_period, teeth_seed)
        lips_smma  = _off_seeded(src, lips_period,  lips_seed)
        lines = {
            "jaw":       jaw_smma.shift(jaw_shift),
            "teeth":     teeth_smma.shift(teeth_shift),
            "lips":      lips_smma.shift(lips_shift),
            "jaw_off":   jaw_smma,
            "teeth_off": teeth_smma,
            "lips_off":  lips_smma,
        }
    else:
        lines = _alligator_on_series(src, jaw_period, jaw_shift, teeth_period, teeth_shift, lips_period, lips_shift)

    for key, series in lines.items():
        df[key] = series

    df["ltf_bull"] = (df["lips_off"] > df["teeth_off"]) & (df["teeth_off"] > df["jaw_off"])
    df["ltf_bear"] = (df["jaw_off"]  > df["teeth_off"]) & (df["teeth_off"] > df["lips_off"])
    jaw_safe = df["jaw_off"].replace(0.0, float("nan"))
    df["ltf_spread_pct"] = (df["lips_off"] - df["jaw_off"]) / jaw_safe * 100.0

    df["prev_low"]   = df["low"].shift(1)
    df["prev_high"]  = df["high"].shift(1)
    df["prev_close"] = df["close"].shift(1)
    df["prev_open"]  = df["open"].shift(1)

    prev_range = (df["prev_high"] - df["prev_low"]).replace(0.0, float("nan"))
    df["prev_body_ratio"] = (df["prev_close"] - df["prev_open"]).abs() / prev_range

    teeth_safe = df["teeth"].replace(0.0, float("nan"))
    df["prev_close_vs_teeth_pct"] = (df["prev_close"] - df["teeth"]) / teeth_safe * 100.0

    bull_change = (df["ltf_bull"] != df["ltf_bull"].shift()).cumsum()
    streak = df.groupby(bull_change).cumcount() + 1
    df["alignment_streak"] = streak.where(df["ltf_bull"], 0).fillna(0).astype(int)

    return df


def compute_alligator_htf(
    df: pd.DataFrame,
    htf_multiplier: int = 4,
    jaw_period: int = 13, jaw_shift: int = 8,
    teeth_period: int = 8, teeth_shift: int = 5,
    lips_period: int = 5,  lips_shift: int = 3,
) -> pd.DataFrame:
    """HTF alligator resampled from LTF. Adds htf_jaw/teeth/lips (shifted + raw), htf_bull/bear/spread_pct."""
    return _resample_and_merge(df, htf_multiplier, "htf_", jaw_period, jaw_shift, teeth_period, teeth_shift, lips_period, lips_shift)


def compute_alligator_macro(
    df: pd.DataFrame,
    macro_htf_multiplier: int = 24,
    jaw_period: int = 13, jaw_shift: int = 8,
    teeth_period: int = 8, teeth_shift: int = 5,
    lips_period: int = 5,  lips_shift: int = 3,
) -> pd.DataFrame:
    """Macro HTF alligator (cycle filter). Adds macro_htf_jaw/teeth/lips (shifted + raw), macro_htf_bull/bear/spread_pct."""
    return _resample_and_merge(df, macro_htf_multiplier, "macro_htf_", jaw_period, jaw_shift, teeth_period, teeth_shift, lips_period, lips_shift)


IndicatorRegistry.register("alligator",       compute_alligator)
IndicatorRegistry.register("alligator_htf",   compute_alligator_htf)
IndicatorRegistry.register("alligator_macro", compute_alligator_macro)
