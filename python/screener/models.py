"""
screener.models — data structures for the Orion Terminal screener.

Contains only pure data definitions; no I/O, no scoring logic.
"""

from dataclasses import dataclass, field
from typing import Optional


# ---------------------------------------------------------------------------
# Timeframe config — all TFs available in the API, ordered lowest → highest
# ---------------------------------------------------------------------------

# (api_key, label, weight_in_score)
# Higher TFs get more weight — they represent the macro trend.
TIMEFRAMES: list[tuple[str, str, float]] = [
    ("tf5m",  "5M",  0.05),
    ("tf15m", "15M", 0.10),
    ("tf1h",  "1H",  0.20),
    ("tf4h",  "4H",  0.25),
    ("tf8h",  "8H",  0.15),
    ("tf12h", "12H", 0.10),
    ("tf1d",  "1D",  0.15),
]

# Alligator TF recommendation rules.
# Each entry: (entry_tf_label, required_bullish_tfs)
# Rules are evaluated top-to-bottom; first match wins.
# A TF is "bullish" when changePercent > 0 AND volatility > threshold.
ALLIGATOR_TF_RULES: list[tuple[str, list[str]]] = [
    ("15M", ["15M", "1H", "4H"]),   # short-term scalp with strong macro
    ("1H",  ["1H",  "4H", "1D"]),   # intraday swing with daily trend
    ("4H",  ["4H",  "1D"]),         # multi-day swing, daily confirmed
    ("1D",  ["1D"]),                # macro trend only
]


# ---------------------------------------------------------------------------
# Data models
# ---------------------------------------------------------------------------

@dataclass
class TFSnapshot:
    """Per-timeframe data extracted from the API ticker."""

    label: str
    change_pct: float
    volume_usd: float
    volatility: float
    vdelta: float
    btc_corr: float
    bullish: bool = False   # trending up with open alligator mouth


@dataclass
class TickerScore:
    symbol: str
    price: float
    rvol15m: float
    tfs: dict[str, TFSnapshot] = field(default_factory=dict)   # label → TFSnapshot
    score: float = 0.0
    alligator_tf: str = "—"     # recommended entry TF
    bullish_count: int = 0      # how many TFs are bullish
    confluence: str = ""        # e.g. "15M 1H 4H 1D"
    qualified: bool = True      # passed all hard filters
    disqualify_reason: str = "" # "low_volume", "low_rvol", "high_btc_corr", "low_bullish_tfs"
    filters_json: dict = field(default_factory=dict)  # per-filter pass/fail with actual vs threshold

    @property
    def pair(self) -> str:
        """Convert full symbol to ccxt-style pair: BTCUSDT → BTC/USDT, BTCUSDC → BTC/USDC."""
        if self.symbol.endswith("USDT"):
            return f"{self.symbol[:-4]}/USDT"
        if self.symbol.endswith("USDC"):
            return f"{self.symbol[:-4]}/USDC"
        if self.symbol.endswith("BTC"):
            return f"{self.symbol[:-3]}/BTC"
        return self.symbol

    def change(self, label: str) -> float:
        """Return changePercent for a given TF label, or 0."""
        return self.tfs[label].change_pct if label in self.tfs else 0.0
