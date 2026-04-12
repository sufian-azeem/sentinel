"""
cli.commands.fetch — fetch historical OHLCV data from an exchange.
"""

import argparse
from datetime import datetime, timedelta

from data import fetcher, storage


def cmd_fetch(args: argparse.Namespace) -> None:
    start_date = (datetime.now() - timedelta(days=args.days)).strftime("%Y-%m-%d")
    print(f"Fetching {args.pair} {args.timeframe} from {start_date} to now...")

    df = fetcher.fetch_candles(
        pair=args.pair,
        timeframe=args.timeframe,
        start_date=start_date,
        end_date=None,
        exchange=args.exchange,
    )

    print(f"Fetched {len(df)} candles")
    path = storage.save_candles(df, args.pair, args.timeframe)
    print(f"Saved to {path}")
