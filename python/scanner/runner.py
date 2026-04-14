"""
scanner.runner — CLI entrypoint for the signal scanner.

Parses arguments, runs the screener pipeline, then checks each top-N pair
for active Alligator BUY signals.
"""

import argparse
import sys

import db as repo
from screener.loader import fetch_screener_data, load_screener_data, SCREENER_URL
from screener.models import TickerScore, TIMEFRAMES
from screener.scoring import (filter_and_score, DEFAULT_MIN_CHANGE_PCT,
                               DEFAULT_MIN_VOLUME_USD, DEFAULT_MIN_RVOL,
                               DEFAULT_MIN_BULLISH_TFS)
from scanner.config import TF_CONFIG, TF_WARMUP_CANDLES
from scanner.fetcher import _pair_to_ccxt, _start_date_for_tf, _normalize_pair_for_exchange
from scanner.checker import check_signal, check_signal_direct
from scanner.display import print_signals
from data.fetcher import fetch_candles

# TFs to scan for signals on every qualified pair
SCAN_TFS = ["15M", "1H", "4H"]

# Minimum spread between lips and jaw (as % of jaw) to consider the alligator aligned.
# Below this threshold the lines are tangled even if technically in the right order.
MIN_ALLIGATOR_SPREAD_PCT = 0.15

# Candles fetched in progressive (incremental) scan mode.
# Must be > jaw_shift + lookback + 3 = 8 + 1 + 3 = 12.  20 gives comfortable headroom.
INCREMENTAL_CANDLES = 20

# HTF map for candle reuse and incremental HTF injection
HTF_MAP: dict[str, str | None] = {"15M": "1H", "1H": "4H", "4H": None}


def _make_ticker_from_db(row: dict) -> TickerScore:
    """Reconstruct a minimal TickerScore from a DB-loaded screener_result row."""
    pair = row["pair"]          # e.g. "BTC/USDT"
    symbol = pair.replace("/", "")  # → "BTCUSDT"
    return TickerScore(
        symbol=symbol,
        price=row["price"],
        rvol15m=row["rvol"],
        tfs={},
        score=row["score"],
        alligator_tf=row["alligator_tf"] or "—",
        confluence=row["confluence"],
        qualified=True,
    )


def _print_shortlist(candidates: list[TickerScore]) -> None:
    """Print a table explaining why each pair was shortlisted by the screener."""
    TF_LABELS = [lb for _, lb, _ in TIMEFRAMES]   # 5M 15M 1H 4H 8H 12H 1D

    width = 115
    print(f"{'─' * width}")
    print("  SHORTLISTED PAIRS — screener qualification summary")
    print(f"  {'#':<3} {'Pair':<14} {'Entry TF':<9} {'Score':>6}  {'rVol':>5}  {'Vol 1H':>8}  "
          f"{'Bullish TFs':<30}  Confluence")
    print(f"{'─' * width}")

    for i, t in enumerate(candidates, 1):
        vol_1h = t.tfs["1H"].volume_usd if "1H" in t.tfs else 0.0
        if vol_1h >= 1_000_000_000:
            vol_str = f"${vol_1h/1_000_000_000:.1f}B"
        elif vol_1h >= 1_000_000:
            vol_str = f"${vol_1h/1_000_000:.1f}M"
        else:
            vol_str = f"${vol_1h/1_000:.0f}K"

        bullish_details = "  ".join(
            f"{lb}({t.tfs[lb].change_pct:+.1f}%)"
            for lb in TF_LABELS
            if lb in t.tfs and t.tfs[lb].bullish
        )

        print(
            f"  {i:<3} {t.pair:<14} {t.alligator_tf:<9} {t.score:>6.3f}  "
            f"{t.rvol15m:>5.2f}  {vol_str:>8}  "
            f"{bullish_details:<30}  {t.confluence}"
        )

    print(f"{'─' * width}\n")


def _scan_pair_with_candle_reuse(
    ticker: TickerScore,
    run_id: int,
    result_id: int,
    exchange: str,
    lookback: int,
    verbose: bool,
) -> list[dict]:
    """
    Fetch 15M, 1H, and 4H candles once, then scan all three TFs reusing them:
      15M scan → ltf=15M candles, htf=1H candles
      1H  scan → ltf=1H  candles, htf=4H candles
      4H  scan → ltf=4H  candles, htf=None (1D fetched internally)
    Returns list of result dicts.
    """
    import time as _time
    pair      = _normalize_pair_for_exchange(_pair_to_ccxt(ticker.pair), exchange)
    mexc_pair = _pair_to_ccxt(ticker.pair)  # standard BTC/USDT for MEXC fallback

    # Pre-fetch the three LTF candle sets we'll reuse (small delay between fetches)
    candles: dict[str, object] = {}
    candle_exchange: dict[str, str] = {}  # tracks which exchange actually supplied data per TF
    for tf in SCAN_TFS:
        cfg        = TF_CONFIG[tf]
        ccxt_tf    = cfg["ccxt_tf"]
        start_date = _start_date_for_tf(ccxt_tf, TF_WARMUP_CANDLES[tf])
        try:
            candles[tf] = fetch_candles(pair, ccxt_tf, start_date=start_date, exchange=exchange)
            candle_exchange[tf] = exchange
        except Exception as e:
            print(f"  [{ticker.pair}] candle fetch failed for {tf} on {exchange}: {e}")
            try:
                candles[tf] = fetch_candles(mexc_pair, ccxt_tf, start_date=start_date, exchange="mexc")
                candle_exchange[tf] = "mexc"
                print(f"  [{ticker.pair}] using MEXC fallback for {tf}")
            except Exception as e2:
                print(f"  [{ticker.pair}] MEXC fallback also failed for {tf}: {e2}")
                candles[tf] = None
                candle_exchange[tf] = exchange
        _time.sleep(0.5)  # avoid hammering the API when multiple jobs run in parallel

    # HTF reuse map — the 15M HTF is 1H, the 1H HTF is 4H, 4H fetches its own HTF (1D)
    htf_map = {"15M": "1H", "1H": "4H", "4H": None}

    all_results: list[dict] = []
    for tf in SCAN_TFS:
        ticker.alligator_tf = tf
        scan_id = None
        try:
            scan_id = repo.create_signal_scan(run_id, result_id, ticker.pair, tf, exchange, "cwt")
        except Exception as e:
            print(f"  [db] signal_scan create failed for {ticker.pair} {tf}: {e}")

        htf_key = htf_map[tf]
        result = check_signal(
            ticker,
            exchange=exchange,
            lookback=lookback,
            verbose=verbose,
            ltf_df=candles.get(tf),
            htf_df=candles.get(htf_key) if htf_key else None,
        )
        all_results.append(result)

        try:
            if scan_id:
                skip = result.get("skip_reason") or ""
                if not skip or skip == "no_setup":
                    status = "scanned"
                elif skip.startswith(("fetch_error", "strategy_error", "indicator_error")):
                    status = "error"
                else:
                    status = "skipped"
                repo.update_signal_scan(
                    scan_id, status,
                    result.get("candles_fetched", 0),
                    result.get("conditions_json", []),
                    result.get("skip_reason") or None,
                )
                if result.get("signal_found"):
                    repo.create_signal(scan_id, result)

                # Extract and store alligator snapshot from conditions_json
                first_candle = (result.get("conditions_json") or [{}])[0]
                ltf = first_candle.get("ltf", {})
                if ltf.get("jaw_off") is not None:
                    jaw   = float(ltf["jaw_off"]   or 0)
                    teeth = float(ltf["teeth_off"] or 0)
                    lips  = float(ltf["lips_off"]  or 0)
                    alligator_entry: dict = {
                        "jaw":        jaw,
                        "teeth":      teeth,
                        "lips":       lips,
                        "bullish":    lips > teeth > jaw and float(ltf.get("spread_pct", 0) or 0) >= MIN_ALLIGATOR_SPREAD_PCT,
                        "spread_pct": float(ltf.get("spread_pct", 0) or 0),
                    }
                    if result.get("alligator_seed"):
                        alligator_entry["seed"] = result["alligator_seed"]
                    repo.update_screener_result_alligator(
                        result_id,
                        {tf: alligator_entry},
                        tf_exchange={tf: candle_exchange.get(tf, exchange)},
                    )
        except Exception as e:
            print(f"  [db] signal_scan update failed for {ticker.pair} {tf}: {e}")

    return all_results


def _scan_pair_incremental(
    ticker: TickerScore,
    run_id: int,
    result_id: int,
    tf_data: dict,
    exchange: str,
    lookback: int,
    verbose: bool,
) -> list[dict]:
    """
    Progressive scan: fetch only INCREMENTAL_CANDLES per TF and seed
    the Alligator SMMA + Heikin-Ashi from stored values.  3–4× less data
    than a full warmup scan.

    Falls back to _scan_pair_with_candle_reuse() if seed data is missing
    for any TF (e.g. first run after adding this feature).
    """
    import time as _time

    # Verify all seeds are available before committing to incremental mode
    for tf in SCAN_TFS:
        alligator = (tf_data.get(tf) or {}).get("alligator") or {}
        if not alligator.get("seed"):
            print(f"  [{ticker.pair}] no seed for {tf} — falling back to full scan")
            return _scan_pair_with_candle_reuse(ticker, run_id, result_id, exchange, lookback, verbose)

    base_pair = _pair_to_ccxt(ticker.pair)  # standard BTC/USDT
    mexc_pair = base_pair

    # Fetch a small window of recent candles for each TF
    candles: dict[str, object] = {}
    candle_exchange: dict[str, str] = {}  # tracks which exchange actually supplied data per TF
    for tf in SCAN_TFS:
        cfg        = TF_CONFIG[tf]
        ccxt_tf    = cfg["ccxt_tf"]
        start_date = _start_date_for_tf(ccxt_tf, INCREMENTAL_CANDLES)
        # Use the exchange that was stored from the last successful fetch for this TF
        tf_exchange = (tf_data.get(tf) or {}).get("exchange") or exchange
        pair = _normalize_pair_for_exchange(base_pair, tf_exchange)
        try:
            candles[tf] = fetch_candles(pair, ccxt_tf, start_date=start_date, exchange=tf_exchange)
            candle_exchange[tf] = tf_exchange
        except Exception as e:
            print(f"  [{ticker.pair}] incremental fetch failed for {tf} on {tf_exchange}: {e}")
            try:
                candles[tf] = fetch_candles(mexc_pair, ccxt_tf, start_date=start_date, exchange="mexc")
                candle_exchange[tf] = "mexc"
                print(f"  [{ticker.pair}] using MEXC fallback for {tf}")
            except Exception as e2:
                print(f"  [{ticker.pair}] MEXC fallback also failed for {tf}: {e2}")
                candles[tf] = None
                candle_exchange[tf] = tf_exchange
        _time.sleep(0.1)  # incremental: small window, no need for long delay

    all_results: list[dict] = []
    for tf in SCAN_TFS:
        ticker.alligator_tf = tf
        scan_id = None
        try:
            scan_id = repo.create_signal_scan(run_id, result_id, ticker.pair, tf, exchange, "cwt")
        except Exception as e:
            print(f"  [db] signal_scan create failed for {ticker.pair} {tf}: {e}")

        # Build seed dict: LTF seeds from stored alligator, HTF values from HTF TF's alligator
        ltf_alligator = tf_data[tf]["alligator"]
        ltf_seed      = ltf_alligator["seed"]
        htf_tf        = HTF_MAP[tf]
        htf_alligator = (tf_data.get(htf_tf) or {}).get("alligator") if htf_tf else None

        alligator_seed: dict = {
            "jaw_smma":       ltf_seed["jaw_smma"],
            "teeth_smma":     ltf_seed["teeth_smma"],
            "lips_smma":      ltf_seed["lips_smma"],
            "last_ha_open":   ltf_seed["last_ha_open"],
            "last_ha_close":  ltf_seed["last_ha_close"],
        }
        if htf_alligator:
            alligator_seed.update({
                "htf_bullish":   htf_alligator.get("bullish", True),
                "htf_spread_pct": htf_alligator.get("spread_pct", 0.0),
                "htf_jaw":       htf_alligator.get("jaw", 0.0),
                "htf_teeth":     htf_alligator.get("teeth", 0.0),
                "htf_lips":      htf_alligator.get("lips", 0.0),
            })

        result = check_signal(
            ticker,
            exchange=exchange,
            lookback=lookback,
            verbose=verbose,
            ltf_df=candles.get(tf),
            alligator_seed=alligator_seed,
        )
        all_results.append(result)

        try:
            if scan_id:
                skip = result.get("skip_reason") or ""
                if not skip or skip == "no_setup":
                    status = "scanned"
                elif skip.startswith(("fetch_error", "strategy_error", "indicator_error")):
                    status = "error"
                else:
                    status = "skipped"
                repo.update_signal_scan(
                    scan_id, status,
                    result.get("candles_fetched", 0),
                    result.get("conditions_json", []),
                    result.get("skip_reason") or None,
                )
                if result.get("signal_found"):
                    repo.create_signal(scan_id, result)

                # Update stored alligator + seed (and exchange) for next incremental scan
                new_seed = result.get("alligator_seed")
                if new_seed:
                    alligator_entry: dict = {**ltf_alligator, "seed": new_seed}
                    repo.update_screener_result_alligator(
                        result_id,
                        {tf: alligator_entry},
                        tf_exchange={tf: candle_exchange.get(tf, exchange)},
                    )
        except Exception as e:
            print(f"  [db] signal_scan update failed for {ticker.pair} {tf}: {e}")

    return all_results


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Screen pairs and check for live Alligator BUY signals."
    )
    parser.add_argument("--pair", "-p", type=str, default=None,
                        help="Check a specific pair directly, e.g. STO/USDT (skips screener)")
    parser.add_argument("--tf", type=str, default=None,
                        help="Alligator TF for --pair mode: 15M, 1H, 4H, 1D")
    parser.add_argument("--file", "-f", type=str, default=None,
                        help="Path to local Orion Terminal JSON file")
    parser.add_argument("--top", "-n", type=int, default=20,
                        help="Number of screened pairs to check (default: 20)")
    parser.add_argument("--exchange", type=str, default="binance",
                        help="CCXT exchange to fetch candles from (default: binance)")
    parser.add_argument("--min-change", type=float, default=DEFAULT_MIN_CHANGE_PCT,
                        help=f"Min %% change per TF to count as bullish (default: {DEFAULT_MIN_CHANGE_PCT})")
    parser.add_argument("--min-volume", type=float, default=DEFAULT_MIN_VOLUME_USD,
                        help=f"Min 1H volume USD (default: {DEFAULT_MIN_VOLUME_USD:,.0f})")
    parser.add_argument("--min-rvol", type=float, default=DEFAULT_MIN_RVOL,
                        help=f"Min relative volume on 15M (default: {DEFAULT_MIN_RVOL})")
    parser.add_argument("--min-bullish-tfs", type=int, default=DEFAULT_MIN_BULLISH_TFS,
                        help=f"Min bullish TFs required from screener (default: {DEFAULT_MIN_BULLISH_TFS})")
    parser.add_argument("--screener-run-id", type=int, default=None,
                        help="Load qualified pairs from this screener_run DB row (skips screener)")
    parser.add_argument("--screener-result-id", type=int, default=None,
                        help="Scan a single pair by screener_result DB row id (used by per-pair jobs)")
    parser.add_argument("--progressive", action="store_true",
                        help="Incremental scan: fetch only recent candles using stored SMMA/HA seeds")
    parser.add_argument("--lookback", type=int, default=1,
                        help="Number of recent closed candles to check per pair (default: 1)")
    parser.add_argument("--verbose", "-v", action="store_true",
                        help="Show per-candle breakdown of every condition")
    args = parser.parse_args()

    # ── Direct pair mode — skips screener ─────────────────────────────────
    if args.pair:
        tf = (args.tf or "1H").upper()
        if tf not in TF_CONFIG:
            print(f"Unknown TF '{tf}'. Choose from: {', '.join(TF_CONFIG)}")
            sys.exit(1)
        pair = _pair_to_ccxt(args.pair)
        print(f"Checking {pair} [{tf}] directly (lookback={args.lookback})...\n")
        result = check_signal_direct(
            pair, tf,
            exchange=args.exchange,
            lookback=args.lookback,
            verbose=args.verbose,
        )
        print_signals([result] if result.get("signal_found") else [])
        return

    # ── Single-pair mode — invoked by SignalScanPairJob (one job per pair) ───
    if args.screener_result_id:
        row = repo.load_pair_by_result_id(args.screener_result_id)
        if not row:
            print(f"No screener_result found with id={args.screener_result_id}")
            sys.exit(1)
        ticker = _make_ticker_from_db(row)
        scan_fn = _scan_pair_incremental if args.progressive else _scan_pair_with_candle_reuse
        kwargs: dict = dict(
            ticker=ticker,
            run_id=row["screener_run_id"],
            result_id=row["screener_result_id"],
            exchange=args.exchange,
            lookback=args.lookback,
            verbose=args.verbose,
        )
        if args.progressive:
            kwargs["tf_data"] = row.get("tf_data_json") or {}
        signals = scan_fn(**kwargs)
        print_signals([r for r in signals if r.get("signal_found")])
        return

    # ── Steps 1+2: Load qualified pairs ───────────────────────────────────
    run_id = None
    result_id_map: dict[str, int] = {}
    candidates: list[TickerScore] = []

    if args.screener_run_id:
        # ── DB-driven mode: Laravel already ran the screener ───────────────
        run_id = args.screener_run_id
        print(f"Loading qualified pairs for screener_run_id={run_id}...")
        try:
            raw_pairs = repo.load_qualified_pairs(run_id, args.top)
        except Exception as e:
            print(f"  [db] load_qualified_pairs failed: {e}")
            sys.exit(1)

        if not raw_pairs:
            print("No qualified pairs found in DB — nothing to scan.")
            return

        candidates = [_make_ticker_from_db(p) for p in raw_pairs]
        result_id_map = {p["pair"]: p["screener_result_id"] for p in raw_pairs}
        print(f"Loaded {len(candidates)} qualified pairs — checking...\n")

        try:
            repo.delete_signal_scans_for_run(run_id)
        except Exception as e:
            print(f"  [db] cleanup failed: {e}")

    else:
        # ── Legacy mode: run full screener from file or live feed ──────────
        if args.file:
            print(f"Loading screener data from {args.file}...")
            tickers = load_screener_data(args.file)
        else:
            print(f"Fetching screener data from {SCREENER_URL}...")
            try:
                tickers = fetch_screener_data()
            except Exception as e:
                print(f"\nScreener fetch failed: {e}")
                print("Tip: save the JSON from your browser and pass --file data.json")
                sys.exit(1)

        print(f"Loaded {len(tickers)} tickers.")

        ranked = filter_and_score(
            tickers,
            min_change_pct=args.min_change,
            min_volume_usd=args.min_volume,
            min_rvol=args.min_rvol,
            min_bullish_tfs=args.min_bullish_tfs,
        )

        qualified  = [t for t in ranked if t.qualified]
        candidates = qualified[:args.top]
        print(f"Screener found {len(qualified)} matching pairs — checking top {len(candidates)}...\n")

        filters_dict = {
            "min_change":      args.min_change,
            "min_volume":      args.min_volume,
            "min_rvol":        args.min_rvol,
            "min_bullish_tfs": args.min_bullish_tfs,
            "top_n":           args.top,
        }
        data_source = "orion_file" if args.file else "orion_live"

        try:
            run_id = repo.create_screener_run(data_source, filters_dict)
            for ticker in ranked:
                rid = repo.create_screener_result(run_id, ticker)
                result_id_map[ticker.pair] = rid
        except Exception as e:
            print(f"  [db] screener logging failed: {e}")

        if args.verbose:
            _print_shortlist(candidates)

    # ── Step 3: Check signals on each of 15M / 1H / 4H ──────────────────────
    # Alligator values are extracted from each scan's conditions_json and stored
    # in screener_results.tf_data_json — no separate pre-fetch needed.
    all_results: list[dict] = []
    for ticker in candidates:
        result_id = result_id_map.get(ticker.pair)
        for tf in SCAN_TFS:
            ticker.alligator_tf = tf   # drive check_signal() to use this TF
            scan_id = None
            try:
                if run_id:
                    scan_id = repo.create_signal_scan(
                        run_id, result_id, ticker.pair, tf, args.exchange, "cwt",
                    )
            except Exception as e:
                print(f"  [db] signal_scan create failed for {ticker.pair} {tf}: {e}")

            result = check_signal(
                ticker,
                exchange=args.exchange,
                lookback=args.lookback,
                verbose=args.verbose,
            )
            all_results.append(result)

            try:
                if run_id and scan_id:
                    skip = result.get("skip_reason") or ""
                    if not skip or skip == "no_setup":
                        status = "scanned"
                    elif skip.startswith(("fetch_error", "strategy_error", "indicator_error")):
                        status = "error"
                    else:
                        status = "skipped"
                    repo.update_signal_scan(
                        scan_id, status,
                        result.get("candles_fetched", 0),
                        result.get("conditions_json", []),
                        result.get("skip_reason") or None,
                    )
                    if result.get("signal_found"):
                        repo.create_signal(scan_id, result)

                    # Extract alligator snapshot from conditions_json and store in screener_result
                    if result_id:
                        first_candle = (result.get("conditions_json") or [{}])[0]
                        ltf = first_candle.get("ltf", {})
                        if ltf.get("jaw_off") is not None:
                            jaw   = float(ltf["jaw_off"]   or 0)
                            teeth = float(ltf["teeth_off"] or 0)
                            lips  = float(ltf["lips_off"]  or 0)
                            alligator_entry: dict = {
                                "jaw":        jaw,
                                "teeth":      teeth,
                                "lips":       lips,
                                "bullish":    lips > teeth > jaw and float(ltf.get("spread_pct", 0) or 0) >= MIN_ALLIGATOR_SPREAD_PCT,
                                "spread_pct": float(ltf.get("spread_pct", 0) or 0),
                            }
                            if result.get("alligator_seed"):
                                alligator_entry["seed"] = result["alligator_seed"]
                            repo.update_screener_result_alligator(result_id, {tf: alligator_entry})
            except Exception as e:
                print(f"  [db] signal_scan update failed for {ticker.pair} {tf}: {e}")

    # ── Step 4: Display results ────────────────────────────────────────────
    signals = [r for r in all_results if r.get("signal_found")]
    print_signals(signals)

    # ── Step 5: Complete screener run (legacy mode only — Laravel handles this in DB mode) ──
    if not args.screener_run_id:
        if run_id:
            try:
                repo.complete_screener_run(run_id, total_scanned=len(ranked), total_matched=len(qualified))
            except Exception as e:
                print(f"  [db] complete_screener_run failed: {e}")
                try:
                    repo.fail_screener_run(run_id, str(e))
                except Exception:
                    pass


if __name__ == "__main__":
    main()
