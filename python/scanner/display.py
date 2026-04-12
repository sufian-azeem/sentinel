"""
scanner.display — render signal results to stdout.

No I/O, no business logic. Pure presentation.
"""


def print_signals(signals: list[dict]) -> None:
    """Print a clean table of active BUY signals."""
    if not signals:
        print("\n  No active BUY signals found in the screened pairs.\n")
        return

    width = 110
    print(f"\n{'=' * width}")
    print("  ACTIVE ALLIGATOR BUY SIGNALS")
    print(f"{'=' * width}")
    hdr = (
        f"{'#':<3} {'Pair':<14} {'TF':<5} {'Ago':>4} {'Price':>10} "
        f"{'SL':>10} {'TP1':>10} "
        f"{'Candle Time':<22} {'Confluence':<25} Reason"
    )
    print(hdr)
    print("-" * width)

    for i, s in enumerate(signals, 1):
        sl_str  = f"{s['sl_price']:.4f}"  if s["sl_price"]  else "  trailing"
        tp1_str = f"{s['tp1_price']:.4f}" if s["tp1_price"] else "  trailing"
        ago     = "now" if s["candles_ago"] == 1 else f"-{s['candles_ago']}"
        print(
            f"{i:<3} {s['pair']:<14} {s['alligator_tf']:<5} {ago:>4} {s['price']:>10.4f} "
            f"{sl_str:>10} {tp1_str:>10} "
            f"{s['candle_time']:<22} {s['screener_confluence']:<25} {s['reason']}"
        )

    print("-" * width)
    print(f"  {len(signals)} signal(s) found\n")
