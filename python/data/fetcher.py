import ccxt
import pandas as pd
import time
from typing import Optional
from datetime import datetime

TIMEFRAME_MS = {
    "1m": 60_000,
    "3m": 180_000,
    "5m": 300_000,
    "15m": 900_000,
    "30m": 1_800_000,
    "1h": 3_600_000,
    "2h": 7_200_000,
    "4h": 14_400_000,
    "6h": 21_600_000,
    "8h": 28_800_000,
    "12h": 43_200_000,
    "1d": 86_400_000,
    "3d": 259_200_000,
    "1w": 604_800_000,
    "1M": 2_592_000_000,
}


_EXCHANGE_CACHE: dict[str, ccxt.Exchange] = {}


def get_exchange(name: str) -> ccxt.Exchange:
    if name in _EXCHANGE_CACHE:
        return _EXCHANGE_CACHE[name]

    if name not in ccxt.exchanges:
        raise ValueError(f"Exchange '{name}' not supported by ccxt")

    options: dict = {"enableRateLimit": True}

    # HyperLiquid requires walletAddress even for public read-only market data.
    # A zero address is sufficient — no private key needed for OHLCV fetching.
    if name == "hyperliquid":
        options["walletAddress"] = "0x0000000000000000000000000000000000000000"

    ex = getattr(ccxt, name)(options)
    _EXCHANGE_CACHE[name] = ex
    return ex


def fetch_candles(
    pair: str,
    timeframe: str,
    start_date: str,
    end_date: Optional[str] = None,
    exchange: str = "binance",
) -> pd.DataFrame:
    if timeframe not in TIMEFRAME_MS:
        raise ValueError(f"Unsupported timeframe: {timeframe}")

    ex = get_exchange(exchange)

    start_ms = int(
        datetime.fromisoformat(start_date.replace("Z", "+00:00")).timestamp() * 1000
    )
    end_ms = (
        int(
            datetime.fromisoformat(
                (end_date or datetime.utcnow().isoformat()).replace("Z", "+00:00")
            ).timestamp()
            * 1000
        )
        if end_date
        else None
    )

    all_candles = []
    since = start_ms

    for attempt in range(5):
        try:
            while True:
                ohlcv = ex.fetch_ohlcv(pair, timeframe, since=since, limit=1000)
                if not ohlcv:
                    break
                all_candles.extend(ohlcv)

                last_ts = ohlcv[-1][0]
                if end_ms and last_ts >= end_ms:
                    break
                since = last_ts + 1

                time.sleep(0.2)
            break
        except (ccxt.BadSymbol, ccxt.NotSupported) as e:
            # Symbol doesn't exist on this exchange — retrying won't help
            raise RuntimeError(f"Failed to fetch candles after 5 attempts: {e}")
        except Exception as e:
            is_rate_limit = "429" in str(e) or "Too Many Requests" in str(e) or isinstance(e, ccxt.RateLimitExceeded)
            if attempt < 4:
                wait_time = 30 if is_rate_limit else 2 ** attempt
                time.sleep(wait_time)
            else:
                raise RuntimeError(f"Failed to fetch candles after 5 attempts: {e}")

    if not all_candles:
        return pd.DataFrame(
            columns=[
                "timestamp",
                "open",
                "high",
                "low",
                "close",
                "volume",
                "quote_volume",
                "trades_count",
            ]
        )

    num_cols = len(all_candles[0])
    if num_cols == 6:
        col_names = ["timestamp", "open", "high", "low", "close", "volume"]
    elif num_cols >= 7:
        col_names = [
            "timestamp",
            "open",
            "high",
            "low",
            "close",
            "volume",
            "quote_volume",
        ]
    else:
        col_names = ["timestamp", "open", "high", "low", "close", "volume"]

    df = pd.DataFrame(all_candles, columns=col_names[:num_cols])

    if end_ms:
        df = df[df["timestamp"] <= end_ms]

    df = df.drop_duplicates(subset=["timestamp"]).sort_values("timestamp")

    if "quote_volume" not in df.columns:
        df["quote_volume"] = 0.0
    df["trades_count"] = 0

    final_cols = [
        "timestamp",
        "open",
        "high",
        "low",
        "close",
        "volume",
        "quote_volume",
        "trades_count",
    ]

    df = df[final_cols]

    return df.reset_index(drop=True)


def detect_gaps(df: pd.DataFrame, timeframe: str) -> list[tuple[int, int]]:
    if len(df) < 2:
        return []

    expected_interval = TIMEFRAME_MS.get(timeframe)
    if not expected_interval:
        return []

    gaps = []
    timestamps = df["timestamp"].values

    for i in range(1, len(timestamps)):
        diff = timestamps[i] - timestamps[i - 1]
        if diff > expected_interval * 1.5:
            gaps.append((timestamps[i - 1], timestamps[i]))

    return gaps
