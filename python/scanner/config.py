"""
scanner.config — constants for the signal scanner.

TF mapping and warmup candle counts. No logic.
"""

# ---------------------------------------------------------------------------
# TF mapping: screener label → ccxt timeframe string + HTF params
# ---------------------------------------------------------------------------

# For each entry TF we set htf_multiplier so that:
#   LTF (base)  = entry TF
#   HTF         = intermediate confirmation TF
# Macro HTF is fetched directly (not resampled) for live scanning.
TF_CONFIG: dict[str, dict] = {
    "15M": {
        "ccxt_tf":        "15m",
        "htf_ccxt_tf":    "1h",
        "htf_multiplier": 4,    # 15m × 4 = 1h confirmation
    },
    "1H": {
        "ccxt_tf":        "1h",
        "htf_ccxt_tf":    "4h",
        "htf_multiplier": 4,    # 1h  × 4 = 4h confirmation
    },
    "4H": {
        "ccxt_tf":        "4h",
        "htf_ccxt_tf":    "1d",
        "htf_multiplier": 6,    # 4h  × 6 = 1d confirmation
    },
    "1D": {
        "ccxt_tf":        "1d",
        "htf_ccxt_tf":    "1w",
        "htf_multiplier": 7,    # 1d  × 7 = 1w confirmation
    },
}

# Candles to fetch per TF — enough to warm up the HTF alligator.
# HTF needs: htf_multiplier × (jaw_period + jaw_shift) = 4 × 21 = 84 base candles minimum.
# Fetching generously avoids NaN on first rows.
TF_WARMUP_CANDLES: dict[str, int] = {
    "15M": 300,
    "1H":  200,
    "4H":  150,
    "1D":  100,
}
