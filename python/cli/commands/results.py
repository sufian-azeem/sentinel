"""
cli.commands.results — show, compare, and tag backtest runs.
"""

import argparse

from db import get_run, compare_runs, tag_run


def cmd_show(args: argparse.Namespace) -> None:
    result = get_run(args.run_id)

    if not result:
        print(f"Run not found: {args.run_id}")
        return

    print(f"{'=' * 50}")
    print(f"Run ID:       {result.run_id}")
    print(f"Strategy:     {result.strategy_name}")
    print(f"Pair:         {result.pair} {result.timeframe}")
    print(f"Period:       {result.start_date} - {result.end_date}")
    print(f"Capital:      ${result.starting_capital:.2f}")
    print(f"Final Equity: ${result.final_equity:.2f}")
    print(f"\nMetrics:")
    print(f"  Total Return: {result.metrics['total_return_pct']:.2f}%")
    print(f"  Sharpe Ratio: {result.metrics['sharpe_ratio']:.2f}")
    print(f"  Max Drawdown: {result.metrics['max_drawdown_pct']:.2f}%")
    print(f"  Win Rate:     {result.metrics['win_rate_pct']:.1f}%")
    print(f"  Profit Factor:{result.metrics['profit_factor']:.2f}")
    print(f"  Total Trades: {result.total_trades}")
    print(f"\nParams: {result.params_used}")


def cmd_compare(args: argparse.Namespace) -> None:
    results = compare_runs(args.run_ids)

    if not results:
        print("No runs found")
        return

    print(f"{'Run ID':<10} {'Strategy':<20} {'Pair':<15} {'Return%':<10} {'Sharpe':<8} {'Trades':<8}")
    print("-" * 80)
    for run_id, data in results.items():
        metrics = data["metrics"]
        print(
            f"{run_id:<10} {data['strategy_name']:<20} {data['pair']:<15} "
            f"{metrics['total_return_pct']:<10.2f} {metrics['sharpe_ratio']:<8.2f} {data['total_trades']:<8}"
        )


def cmd_tag(args: argparse.Namespace) -> None:
    tag_run(args.run_id, args.tag)
    print(f"Tagged {args.run_id} with '{args.tag}'")
