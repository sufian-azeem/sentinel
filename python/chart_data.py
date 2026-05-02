#!/usr/bin/env python3
"""Fetch OHLCV candles with Alligator indicator for the signal detail chart."""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))

from data.fetcher import fetch_candles
from indicators.library.alligator import compute_alligator


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--pair",      required=True)
    parser.add_argument("--timeframe", required=True)  # e.g. 1H, 15M, 4H
    parser.add_argument("--exchange",  default="binance")
    parser.add_argument("--since",     required=True)  # ISO datetime UTC
    parser.add_argument("--until",     required=True)  # ISO datetime UTC
    args = parser.parse_args()

    tf_ccxt = args.timeframe.lower()  # 1H → 1h, 15M → 15m

    df = fetch_candles(
        args.pair, tf_ccxt,
        start_date=args.since,
        end_date=args.until,
        exchange=args.exchange,
    )

    if df.empty:
        print(json.dumps({"candles": [], "jaw": [], "teeth": [], "lips": []}))
        return

    df = compute_alligator(df)

    candles = [
        {
            "t": int(row["timestamp"]) // 1000,
            "o": round(float(row["open"]),  8),
            "h": round(float(row["high"]),  8),
            "l": round(float(row["low"]),   8),
            "c": round(float(row["close"]), 8),
        }
        for _, row in df.iterrows()
    ]

    def line(col):
        return [
            {"t": int(r["timestamp"]) // 1000, "v": round(float(r[col]), 8)}
            for _, r in df[df[col].notna() & (df[col] > 0)].iterrows()
        ]

    print(json.dumps({
        "candles": candles,
        "jaw":     line("jaw_off"),
        "teeth":   line("teeth_off"),
        "lips":    line("lips_off"),
    }))


if __name__ == "__main__":
    main()
