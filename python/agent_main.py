#!/usr/bin/env python3
import argparse
import os
import sys
from pathlib import Path
from glob import glob

from config import settings
from agents.refinement_agent import RefinementAgent
from agents.strategy_agent import StrategyAgent
from agents.orchestrator import Orchestrator


def cmd_refine(args):
    api_key = os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        print("Error: ANTHROPIC_API_KEY not set")
        sys.exit(1)

    agent = RefinementAgent(api_key)

    print(f"Refining {args.strategy_name} on {args.pair} {args.timeframe}")

    result = agent.run(
        strategy_name=args.strategy_name,
        pair=args.pair,
        timeframe=args.timeframe,
        iterations=args.iterations,
    )

    print(f"\n{'=' * 50}")
    print("Refinement Results")
    print(f"{'=' * 50}")
    print(f"Best params: {result.get('best_params')}")
    print(f"Baseline Sharpe: {result.get('baseline_sharpe', 0):.2f}")
    print(f"Final Sharpe: {result.get('final_sharpe', 0):.2f}")
    print(f"Improvement: {result.get('improvement_pct', 0):.1f}%")
    print(f"\n{result.get('summary', '')}")


def cmd_create(args):
    api_key = os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        print("Error: ANTHROPIC_API_KEY not set")
        sys.exit(1)

    agent = StrategyAgent(api_key)

    print(f"Creating {args.type} strategy for {args.pairs}")

    result = agent.run(
        strategy_type=args.type, pairs=args.pairs, timeframe=args.timeframe
    )

    if "error" in result:
        print(f"Error: {result['error']}")
        sys.exit(1)

    print(f"\n{'=' * 50}")
    print("Strategy Created")
    print(f"{'=' * 50}")
    print(f"Strategy name: {result.get('strategy_name')}")
    print(f"File path: {result.get('file_path')}")
    print(f"Validation: {result.get('validation_result')}")
    print(f"Notes: {result.get('notes')}")


def cmd_optimize(args):
    api_key = os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        print("Error: ANTHROPIC_API_KEY not set")
        sys.exit(1)

    orchestrator = Orchestrator(api_key)

    print(f"Running optimization...")
    print(f"Pairs: {args.pairs}")
    print(f"Timeframe: {args.timeframe}")
    print(f"Cycles: {args.cycles}")
    print(f"Generate new: {args.generate}")

    result = orchestrator.run_full_cycle(
        pairs=args.pairs,
        timeframes=[args.timeframe],
        generate_new=args.generate,
        cycles=args.cycles,
    )

    print(f"\n{'=' * 50}")
    print("Optimization Complete")
    print(f"{'=' * 50}")
    print(f"Report: {result.get('report_path')}")
    print("\nTop strategies:")

    for i, r in enumerate(result.get("rankings", [])[:5]):
        print(
            f"  {i + 1}. {r['strategy']} on {r['pair']} - Score: {r.get('score', 0):.3f}"
        )


def cmd_report(args):
    if args.latest:
        reports = sorted(settings.RESULTS_DIR.glob("agent_report_*.json"))
        if not reports:
            print("No reports found")
            return
        report_path = reports[-1]
    else:
        report_path = Path(args.report_id)

    if not report_path.exists():
        print(f"Report not found: {report_path}")
        return

    import json

    with open(report_path) as f:
        data = json.load(f)

    print(f"\n{'=' * 50}")
    print(f"Agent Report - {data.get('timestamp')}")
    print(f"{'=' * 50}")

    rankings = data.get("rankings", [])

    print(
        f"\n{'Strategy':<25} {'Pair':<15} {'Sharpe':<8} {'DD%':<8} {'Win%':<8} {'Score':<8}"
    )
    print("-" * 80)

    for r in rankings:
        print(
            f"{r.get('strategy', ''):<25} {r.get('pair', ''):<15} {r.get('sharpe_ratio', 0):<8.2f} {r.get('max_drawdown_pct', 0):<8.2f} {r.get('win_rate_pct', 0):<8.1f} {r.get('score', 0):<8.3f}"
        )


def main():
    parser = argparse.ArgumentParser(description="Crypto Backtesting Agent")
    subparsers = parser.add_subparsers(dest="command", help="Commands")

    refine_parser = subparsers.add_parser("refine", help="Refine strategy parameters")
    refine_parser.add_argument("strategy_name", help="Strategy name")
    refine_parser.add_argument("pair", help="Trading pair")
    refine_parser.add_argument("timeframe", help="Timeframe")
    refine_parser.add_argument(
        "--iterations", type=int, default=3, help="Number of iterations"
    )

    create_parser = subparsers.add_parser("create", help="Create new strategy")
    create_parser.add_argument(
        "--type", choices=["momentum", "mean_reversion", "breakout"], required=True
    )
    create_parser.add_argument("--pairs", nargs="+", required=True)
    create_parser.add_argument("--timeframe", required=True)

    optimize_parser = subparsers.add_parser("optimize", help="Full optimization cycle")
    optimize_parser.add_argument("--pairs", nargs="+", required=True)
    optimize_parser.add_argument("--timeframe", required=True)
    optimize_parser.add_argument("--cycles", type=int, default=1)
    optimize_parser.add_argument("--generate", type=int, default=0)

    report_parser = subparsers.add_parser("report", help="Show agent report")
    report_parser.add_argument(
        "--latest", action="store_true", help="Show latest report"
    )
    report_parser.add_argument("report_id", nargs="?", help="Report file path")

    args = parser.parse_args()

    if not args.command:
        parser.print_help()
        return

    if args.command == "refine":
        cmd_refine(args)
    elif args.command == "create":
        cmd_create(args)
    elif args.command == "optimize":
        cmd_optimize(args)
    elif args.command == "report":
        cmd_report(args)


if __name__ == "__main__":
    main()
