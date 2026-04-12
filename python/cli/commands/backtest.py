"""
cli.commands.backtest — run a single backtest or all strategy combinations.
"""

import argparse
import sys

from config import settings
from data import storage
from strategies import loader
from engine.backtester import Backtester
from models import RiskRules
from db import save_run


def cmd_backtest(args: argparse.Namespace) -> None:
    try:
        df = storage.load_candles(args.pair, args.timeframe)
    except FileNotFoundError:
        print(f"Error: No data found for {args.pair} {args.timeframe}")
        print("Run: python main.py fetch <pair> <timeframe> --days N")
        sys.exit(1)

    params = {}
    if args.param:
        for p in args.param:
            key, value = p.split("=")
            params[key] = float(value)

    strategy = loader.load_strategy(args.strategy, params)

    config_path = settings.CONFIGS_DIR / f"{args.strategy}.json"
    if config_path.exists():
        risk_rules = loader.load_config(args.strategy).risk_rules
    else:
        risk_rules = RiskRules()

    backtester = Backtester(starting_capital=args.capital)
    print(f"Running backtest: {strategy.name()} on {args.pair} {args.timeframe}")
    print(f"Capital: ${args.capital}, Params: {strategy.params}")

    result = backtester.run(
        strategy=strategy,
        df=df,
        pair=args.pair,
        timeframe=args.timeframe,
        risk_rules=risk_rules,
    )

    print(f"\n{'=' * 50}")
    print("Backtest Results")
    print(f"{'=' * 50}")
    print(f"Run ID:       {result.run_id}")
    print(f"Period:       {result.start_date} to {result.end_date}")
    print(f"Trades:       {result.total_trades}")
    print(f"Final Equity: ${result.final_equity:.2f}")
    print(f"Total Return: {result.metrics['total_return_pct']:.2f}%")
    print(f"Sharpe Ratio: {result.metrics['sharpe_ratio']:.2f}")
    print(f"Max Drawdown: {result.metrics['max_drawdown_pct']:.2f}%")
    print(f"Win Rate:     {result.metrics['win_rate_pct']:.1f}%")
    print(f"Profit Factor:{result.metrics['profit_factor']:.2f}")

    run_id = save_run(result)
    print(f"\nSaved to database: {run_id}")


def cmd_backtest_all(args: argparse.Namespace) -> None:
    print("Backtest all not yet implemented")
