"""
cli.commands.sweep — exhaustive parameter sweep over a strategy.
"""

import argparse
import itertools
import sys

from data import storage
from strategies import loader
from engine.backtester import Backtester


def cmd_sweep(args: argparse.Namespace) -> None:
    param_ranges: dict[str, tuple[float, float, float]] = {}
    for p in args.param:
        if "=" not in p:
            print(f"Error: Invalid param format: {p} (use param=min,max,step)")
            continue
        name, range_str = p.split("=", 1)
        parts = range_str.split(",")
        if len(parts) < 2:
            print(f"Error: Invalid param format: {p}")
            continue
        min_val = float(parts[0])
        max_val = float(parts[1])
        step    = float(parts[2]) if len(parts) > 2 else 1.0
        param_ranges[name] = (min_val, max_val, step)

    print(f"Running parameter sweep for {args.strategy} on {args.pair} {args.timeframe}")
    print(f"Ranges: {param_ranges}")

    try:
        df = storage.load_candles(args.pair, args.timeframe)
    except FileNotFoundError:
        print(f"Error: No data found for {args.pair} {args.timeframe}")
        sys.exit(1)

    keys   = list(param_ranges.keys())
    ranges = []
    for k in keys:
        min_val, max_val, step = param_ranges[k]
        values = list(range(int(min_val), int(max_val) + 1, int(step)))
        ranges.append(values)

    results = []
    for combo in itertools.product(*ranges):
        params     = dict(zip(keys, combo))
        strategy   = loader.load_strategy(args.strategy, params)
        backtester = Backtester(starting_capital=args.capital)
        result     = backtester.run(
            strategy=strategy, df=df, pair=args.pair, timeframe=args.timeframe
        )
        results.append({
            "params":   params,
            "sharpe":   result.metrics["sharpe_ratio"],
            "return":   result.metrics["total_return_pct"],
            "drawdown": result.metrics["max_drawdown_pct"],
            "trades":   result.total_trades,
            "run_id":   result.run_id,
        })

    results.sort(key=lambda x: x["sharpe"], reverse=True)

    print(f"\n{'Params':<30} {'Sharpe':<8} {'Return%':<10} {'Drawdown%':<10} {'Trades':<8}")
    print("-" * 70)
    for r in results:
        params_str = ", ".join(f"{k}={v}" for k, v in r["params"].items())
        print(
            f"{params_str:<30} {r['sharpe']:<8.2f} {r['return']:<10.2f} "
            f"{r['drawdown']:<10.2f} {r['trades']:<8}"
        )
