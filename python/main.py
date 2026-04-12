#!/usr/bin/env python3
"""
main.py — Crypto Backtesting Engine CLI.

Thin router: parses arguments and dispatches to the appropriate
command module in cli/commands/. All command logic lives there.
"""

from cli.parser import build_parser
from cli.commands import fetch, data, backtest, results, sweep
from db import init_db

COMMAND_MAP = {
    "fetch":           fetch.cmd_fetch,
    "list-data":       data.cmd_list_data,
    "list-strategies": data.cmd_list_strategies,
    "backtest":        backtest.cmd_backtest,
    "backtest-all":    backtest.cmd_backtest_all,
    "show":            results.cmd_show,
    "compare":         results.cmd_compare,
    "sweep":           sweep.cmd_sweep,
    "tag":             results.cmd_tag,
}


def main() -> None:
    parser = build_parser()
    args   = parser.parse_args()

    if not args.command:
        parser.print_help()
        return

    init_db()
    COMMAND_MAP[args.command](args)


if __name__ == "__main__":
    main()
