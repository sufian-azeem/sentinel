"""
cli.commands.data — list cached datasets and available strategies.
"""

import argparse

from data import storage
from strategies import loader


def cmd_list_data(args: argparse.Namespace) -> None:
    available = storage.list_available()

    if not available:
        print("No data available")
        return

    print(f"{'Pair':<15} {'Timeframe':<10} {'Rows':<8} {'Start':<12} {'End':<12} {'Size (MB)':<10}")
    print("-" * 70)
    for item in available:
        print(
            f"{item['pair']:<15} {item['timeframe']:<10} {item['rows']:<8} "
            f"{item['start']:<12} {item['end']:<12} {item['file_size_mb']:<10}"
        )


def cmd_list_strategies(args: argparse.Namespace) -> None:
    strategies = loader.list_strategies()

    print(f"{'Name':<25} {'Config':<10}")
    print("-" * 40)
    for s in strategies:
        print(f"{s['name']:<25} {'Yes' if s['has_config'] else 'No':<10}")
