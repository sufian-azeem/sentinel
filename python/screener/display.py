"""
screener.display — render screener results to stdout.

No I/O, no scoring. Pure presentation.
"""

from typing import Optional

from screener.models import TickerScore, TFSnapshot, TIMEFRAMES
from screener.scoring import _MIN_VOLA_BULLISH


def _fmt_vol(v: float) -> str:
    if v >= 1_000_000_000:
        return f"${v/1_000_000_000:.1f}B"
    if v >= 1_000_000:
        return f"${v/1_000_000:.1f}M"
    if v >= 1_000:
        return f"${v/1_000:.0f}K"
    return f"${v:.0f}"


def _chg_str(v: float) -> str:
    return f"{v:+.2f}%"


def _bull_marker(snap: Optional[TFSnapshot]) -> str:
    """Return a short marker: '+X.X' if bullish, '-X.X' if bearish, '  ---' if no data."""
    if snap is None:
        return "  ---"
    v = snap.change_pct
    if snap.bullish:
        return f"+{v:.1f}"
    return f"{v:+.1f}"


def print_results(results: list[TickerScore], top: int = 10) -> None:
    """Print a ranked multi-timeframe table with Alligator TF recommendation."""
    top_results = results[:top]

    if not top_results:
        print("\nNo pairs matched the filters. Try --min-bullish-tfs 2 or --min-change 0.")
        return

    TF_LABELS = [lb for _, lb, _ in TIMEFRAMES]   # 5M 15M 1H 4H 8H 12H 1D

    width = 120
    print(f"\n{'=' * width}")
    print("  ALLIGATOR SCREENER — Multi-Timeframe Analysis  (+ = bullish & trending)")
    print(f"{'=' * width}")

    hdr = (
        f"{'#':<3} {'Pair':<12} {'Price':>11}  "
        + "  ".join(f"{lb:>5}" for lb in TF_LABELS)
        + f"  {'Vol 1H':>8}  {'rVol':>5}  {'Corr':>5}  {'Score':>5}  {'Alligator TF':>12}  Confluence"
    )
    print(hdr)
    print("-" * width)

    for i, t in enumerate(top_results, 1):
        tf_cols = "  ".join(f"{_bull_marker(t.tfs.get(lb)):>5}" for lb in TF_LABELS)
        vol_1h  = t.tfs["1H"].volume_usd if "1H" in t.tfs else 0.0
        corr_1h = t.tfs["1H"].btc_corr   if "1H" in t.tfs else 0.0

        print(
            f"{i:<3} {t.pair:<12} {t.price:>11.4f}  "
            f"{tf_cols}  "
            f"{_fmt_vol(vol_1h):>8}  {t.rvol15m:>5.2f}  {corr_1h:>5.2f}  "
            f"{t.score:>5.3f}  {t.alligator_tf:>12}  {t.confluence}"
        )

    print("-" * width)
    print(
        f"  Top {len(top_results)} of {len(results)} matched pairs  |  "
        f"+ = bullish (change ≥ threshold AND volatility > {_MIN_VOLA_BULLISH}%)\n"
    )
