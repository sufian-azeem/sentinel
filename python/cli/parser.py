"""
cli.parser — argparse setup for the backtesting CLI.

All subcommand definitions live here. main.py calls build_parser()
and dispatches to the appropriate command module.
"""

import argparse


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Crypto Backtesting Engine")
    subparsers = parser.add_subparsers(dest="command", help="Commands")

    # fetch
    fetch_parser = subparsers.add_parser("fetch", help="Fetch historical data")
    fetch_parser.add_argument("pair",      help="Trading pair (e.g., BTC/USDT)")
    fetch_parser.add_argument("timeframe", help="Timeframe (e.g., 1h, 4h)")
    fetch_parser.add_argument("--days",     type=int,   default=30,        help="Number of days to fetch")
    fetch_parser.add_argument("--exchange", type=str,   default="binance", help="Exchange name")

    # list-data
    subparsers.add_parser("list-data",       help="List available data")
    subparsers.add_parser("list-strategies", help="List available strategies")

    # backtest
    backtest_parser = subparsers.add_parser("backtest", help="Run backtest")
    backtest_parser.add_argument("strategy",  help="Strategy name")
    backtest_parser.add_argument("pair",      help="Trading pair")
    backtest_parser.add_argument("timeframe", help="Timeframe")
    backtest_parser.add_argument("--capital", type=float, default=10000.0, help="Starting capital")
    backtest_parser.add_argument("--param",   nargs="+",                   help="Parameters (e.g., rsi_period=14)")

    # backtest-all
    subparsers.add_parser("backtest-all", help="Run all strategy combinations")

    # show
    show_parser = subparsers.add_parser("show", help="Show backtest result")
    show_parser.add_argument("run_id", help="Run ID")

    # compare
    compare_parser = subparsers.add_parser("compare", help="Compare runs")
    compare_parser.add_argument("run_ids", nargs="+", help="Run IDs to compare")

    # sweep
    sweep_parser = subparsers.add_parser("sweep", help="Parameter sweep")
    sweep_parser.add_argument("strategy",  help="Strategy name")
    sweep_parser.add_argument("pair",      help="Trading pair")
    sweep_parser.add_argument("timeframe", help="Timeframe")
    sweep_parser.add_argument("--capital", type=float, default=10000.0, help="Starting capital")
    sweep_parser.add_argument("--param",   nargs="+",                   help="Parameter ranges (e.g., rsi_period=10,20,2)")

    # tag
    tag_parser = subparsers.add_parser("tag", help="Tag a run")
    tag_parser.add_argument("run_id", help="Run ID")
    tag_parser.add_argument("tag",    help="Tag name")

    return parser
