import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq
from pathlib import Path
from datetime import datetime
from typing import Optional

from config import settings


def get_parquet_path(pair: str, timeframe: str) -> Path:
    pair_clean = pair.replace("/", "_")
    settings.DATA_DIR.mkdir(parents=True, exist_ok=True)
    return settings.DATA_DIR / f"{pair_clean}_{timeframe}.parquet"


def merge_candles(existing_df: pd.DataFrame, new_df: pd.DataFrame) -> pd.DataFrame:
    merged = pd.concat([existing_df, new_df], ignore_index=True)
    merged = merged.drop_duplicates(subset=["timestamp"], keep="last")
    merged = merged.sort_values("timestamp").reset_index(drop=True)
    return merged


def save_candles(df: pd.DataFrame, pair: str, timeframe: str) -> Path:
    path = get_parquet_path(pair, timeframe)
    settings.DATA_DIR.mkdir(parents=True, exist_ok=True)

    df = df.copy()
    df["timestamp"] = df["timestamp"].astype("int64")
    df["open"] = df["open"].astype("float64")
    df["high"] = df["high"].astype("float64")
    df["low"] = df["low"].astype("float64")
    df["close"] = df["close"].astype("float64")
    df["volume"] = df["volume"].astype("float64")
    df["quote_volume"] = df["quote_volume"].astype("float64")
    df["trades_count"] = df["trades_count"].astype("int32")

    if path.exists():
        existing = pq.read_table(path).to_pandas()
        df = merge_candles(existing, df)

    table = pa.Table.from_pandas(df)
    pq.write_table(table, path)

    return path


def load_candles(
    pair: str,
    timeframe: str,
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
) -> pd.DataFrame:
    path = get_parquet_path(pair, timeframe)

    if not path.exists():
        raise FileNotFoundError(f"No data found for {pair} {timeframe}")

    df = pq.read_table(path).to_pandas()

    if start_date:
        start_ms = int(
            datetime.fromisoformat(start_date.replace("Z", "+00:00")).timestamp() * 1000
        )
        df = df[df["timestamp"] >= start_ms]

    if end_date:
        end_ms = int(
            datetime.fromisoformat(end_date.replace("Z", "+00:00")).timestamp() * 1000
        )
        df = df[df["timestamp"] <= end_ms]

    return df.sort_values("timestamp").reset_index(drop=True)


def list_available() -> list[dict]:
    settings.DATA_DIR.mkdir(parents=True, exist_ok=True)

    results = []
    for path in settings.DATA_DIR.glob("*.parquet"):
        parts = path.stem.rsplit("_", 1)
        if len(parts) != 2:
            continue

        pair_with_underscore, timeframe = parts
        pair = pair_with_underscore.replace("_", "/")

        try:
            df = pq.read_table(path).to_pandas()
            if df.empty:
                continue

            row_count = len(df)
            min_ts = df["timestamp"].min()
            max_ts = df["timestamp"].max()
            file_size_mb = path.stat().st_size / (1024 * 1024)

            results.append(
                {
                    "pair": pair,
                    "timeframe": timeframe,
                    "rows": row_count,
                    "start": datetime.utcfromtimestamp(min_ts / 1000).strftime(
                        "%Y-%m-%d"
                    ),
                    "end": datetime.utcfromtimestamp(max_ts / 1000).strftime(
                        "%Y-%m-%d"
                    ),
                    "file_size_mb": round(file_size_mb, 2),
                }
            )
        except Exception:
            continue

    return results
