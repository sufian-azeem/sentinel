"""
scanner.fetcher — low-level utilities for pair normalisation and date ranges.

No business logic, no indicator computation.
"""

from datetime import datetime, timezone, timedelta

from data.fetcher import TIMEFRAME_MS


def _start_date_for_tf(ccxt_tf: str, n_candles: int) -> str:
    """Return an ISO start date string to fetch at least n_candles of ccxt_tf."""
    ms_per_candle = TIMEFRAME_MS.get(ccxt_tf, 3_600_000)
    lookback_ms   = ms_per_candle * n_candles
    start_dt      = datetime.now(timezone.utc) - timedelta(milliseconds=lookback_ms)
    return start_dt.strftime("%Y-%m-%dT%H:%M:%S")


def _pair_to_ccxt(pair: str) -> str:
    """Normalise screener symbol to standard ccxt format: BTCUSDT → BTC/USDT."""
    if "/" in pair:
        return pair
    if pair.endswith("USDT"):
        return f"{pair[:-4]}/USDT"
    return pair


def _normalize_pair_for_exchange(pair: str, exchange: str) -> str:
    """
    Convert a standard BTC/USDT pair to the format required by a specific exchange.

    HyperLiquid trades perpetuals quoted in USDC, so BTC/USDT → BTC/USDC:USDC.
    All other exchanges use the pair as-is.
    """
    if exchange == "hyperliquid":
        # Perpetual format: BASE/USDC:USDC  (e.g. BTC/USDC:USDC, ETH/USDC:USDC)
        base = pair.split("/")[0]
        return f"{base}/USDC:USDC"
    return pair
