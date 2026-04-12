"""
screener.runner — CLI entrypoint for the Orion Terminal screener.

Parses arguments, orchestrates load → score → display → export.
"""

import argparse
import json
import sys
from pathlib import Path

import db as repo
from screener.loader import fetch_screener_data, load_screener_data, SCREENER_URL
from screener.scoring import (filter_and_score, DEFAULT_MIN_CHANGE_PCT,
                               DEFAULT_MIN_VOLUME_USD, DEFAULT_MIN_RVOL,
                               DEFAULT_MAX_BTC_CORR, DEFAULT_MIN_BULLISH_TFS)
from screener.display import print_results


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Screen Orion Terminal data for Alligator strategy candidates."
    )
    parser.add_argument("--file", "-f", type=str, default=None,
                        help="Path to local JSON file (skips API fetch)")
    parser.add_argument("--top", "-n", type=int, default=10,
                        help="Number of top results to show (default: 10)")
    parser.add_argument("--min-change", type=float, default=DEFAULT_MIN_CHANGE_PCT,
                        help=f"Min %% change for a TF to count as bullish (default: {DEFAULT_MIN_CHANGE_PCT})")
    parser.add_argument("--min-volume", type=float, default=DEFAULT_MIN_VOLUME_USD,
                        help=f"Min 1H volume in USD (default: {DEFAULT_MIN_VOLUME_USD:,.0f})")
    parser.add_argument("--min-rvol", type=float, default=DEFAULT_MIN_RVOL,
                        help=f"Min relative volume on 15M (default: {DEFAULT_MIN_RVOL})")
    parser.add_argument("--max-corr", type=float, default=DEFAULT_MAX_BTC_CORR,
                        help=f"Max BTC correlation on 1H (default: {DEFAULT_MAX_BTC_CORR})")
    parser.add_argument("--min-bullish-tfs", type=int, default=DEFAULT_MIN_BULLISH_TFS,
                        help=f"Min number of bullish TFs required (default: {DEFAULT_MIN_BULLISH_TFS})")
    parser.add_argument("--output", "-o", type=str, default=None,
                        help="Save results to JSON file")
    args = parser.parse_args()

    # Load data
    if args.file:
        print(f"Loading from {args.file}...")
        tickers = load_screener_data(args.file)
    else:
        print(f"Fetching from {SCREENER_URL}...")
        try:
            tickers = fetch_screener_data()
        except Exception as e:
            print(f"\nFetch failed: {e}")
            print(
                "\nThe Orion Terminal API is protected by Cloudflare.\n"
                "\nFix options:\n"
                "  1. Install curl_cffi (recommended):\n"
                "       pip install curl_cffi\n"
                "\n"
                "  2. Fetch manually from your browser:\n"
                "       - Open https://screener.orionterminal.com/api/screener\n"
                "       - Save the JSON to a file (e.g. data.json)\n"
                "       - Run:  python run_screener.py --file data.json\n"
            )
            sys.exit(1)

    print(f"Loaded {len(tickers)} tickers.")

    all_results = filter_and_score(
        tickers,
        min_change_pct=args.min_change,
        min_volume_usd=args.min_volume,
        min_rvol=args.min_rvol,
        max_btc_corr=args.max_corr,
        min_bullish_tfs=args.min_bullish_tfs,
    )
    qualified = [r for r in all_results if r.qualified]

    print_results(qualified, top=args.top)

    # ── DB logging ─────────────────────────────────────────────────────────
    data_source  = "orion_file" if args.file else "orion_live"
    filters_dict = {
        "min_change":     args.min_change,
        "min_volume":     args.min_volume,
        "min_rvol":       args.min_rvol,
        "max_corr":       args.max_corr,
        "min_bullish_tfs": args.min_bullish_tfs,
        "top_n":          args.top,
    }
    try:
        run_id = repo.create_screener_run(data_source, filters_dict)
        for ticker in all_results:
            repo.create_screener_result(run_id, ticker)
        repo.complete_screener_run(run_id, total_scanned=len(all_results), total_matched=len(qualified))
    except Exception as e:
        print(f"  [db] screener logging failed: {e}")

    if args.output:
        out_path = Path(args.output)
        out_data = [
            {
                "pair":          r.pair,
                "price":         r.price,
                "rvol15m":       r.rvol15m,
                "alligator_tf":  r.alligator_tf,
                "bullish_count": r.bullish_count,
                "confluence":    r.confluence,
                "score":         round(r.score, 4),
                "timeframes": {
                    lb: {
                        "change_pct":  snap.change_pct,
                        "volume_usd":  snap.volume_usd,
                        "volatility":  snap.volatility,
                        "bullish":     snap.bullish,
                    }
                    for lb, snap in r.tfs.items()
                },
            }
            for r in results[:args.top]
        ]
        out_path.write_text(json.dumps(out_data, indent=2))
        print(f"Results saved to {out_path}")


if __name__ == "__main__":
    main()
