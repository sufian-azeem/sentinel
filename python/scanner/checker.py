"""
scanner.checker — per-pair signal evaluation.

Fetches candles, computes indicators, calls strategy.signal(), and returns
a result dict for every BUY hit found within the lookback window.

conditions_json is built directly from strategy.signal().conditions — there
is no separate diagnostic evaluator, so the UI always reflects the exact
same logic that decides whether a signal is created.
"""

from datetime import datetime, timezone

import pandas as pd

from screener.models import TickerScore
from scanner.config import TF_CONFIG, TF_WARMUP_CANDLES
from scanner.fetcher import _start_date_for_tf, _pair_to_ccxt, _normalize_pair_for_exchange
from data.fetcher import fetch_candles
from indicators.library.alligator import compute_alligator
from indicators.library.heikin_ashi import compute_heikin_ashi
from models import SignalAction
from strategies.loader import load_strategy
from indicators.service import IndicatorService

# Register all indicators (side-effect import)
import indicators  # noqa: F401


# ---------------------------------------------------------------------------
# Signal evaluation
# ---------------------------------------------------------------------------

def check_signal(
    ticker: TickerScore,
    exchange: str = "binance",
    lookback: int = 1,
    verbose: bool = False,
    ltf_df: "pd.DataFrame | None" = None,
    htf_df: "pd.DataFrame | None" = None,
    alligator_seed: "dict | None" = None,
) -> dict:
    """
    Fetch candles for ticker on its recommended Alligator TF, compute
    indicators, and check the last `lookback` closed candles for a BUY signal.

    Always returns a result dict. Use result["signal_found"] to check if a
    signal was detected. conditions_json contains per-candle diagnostics.

    alligator_seed: when provided, enables incremental mode. Expected keys:
        jaw_smma, teeth_smma, lips_smma   — stored SMMA values to seed from
        last_ha_open, last_ha_close       — stored HA values to seed from
        htf_bullish, htf_spread_pct       — stored HTF direction (skips HTF fetch)
        htf_jaw, htf_teeth, htf_lips      — stored HTF SMMA values
    """
    alligator_tf = ticker.alligator_tf

    def _no_signal(reason: str, candles_fetched: int = 0, seed: dict = None) -> dict:
        result = {
            "pair":                ticker.pair,
            "alligator_tf":        alligator_tf,
            "signal_found":        False,
            "skip_reason":         reason,
            "candles_fetched":     candles_fetched,
            "screener_score":      round(ticker.score, 4),
            "screener_confluence": ticker.confluence,
            "conditions_json":     [],
        }
        if seed:
            result["alligator_seed"] = seed
        return result

    if alligator_tf not in TF_CONFIG:
        return _no_signal(f"unsupported_tf:{alligator_tf}")

    cfg       = TF_CONFIG[alligator_tf]
    ccxt_tf   = cfg["ccxt_tf"]
    n_candles = TF_WARMUP_CANDLES[alligator_tf]
    pair      = _normalize_pair_for_exchange(_pair_to_ccxt(ticker.pair), exchange)
    start_date = _start_date_for_tf(ccxt_tf, n_candles)

    # ── LTF candles (use pre-fetched if provided) ─────────────────────────
    if ltf_df is not None:
        df = ltf_df
    else:
        try:
            df = fetch_candles(pair, ccxt_tf, start_date=start_date, exchange=exchange)
        except Exception as e:
            if verbose:
                print(f"  {pair} [{alligator_tf}]: fetch failed — {e}")
            return _no_signal(f"fetch_error:{e}")

    min_candles = 12 if alligator_seed else 50
    if len(df) < min_candles:
        if verbose:
            print(f"  {pair} [{alligator_tf}]: only {len(df)} candles, skipping")
        return _no_signal(f"too_few_candles:{len(df)}", candles_fetched=len(df))

    # ── HTF: use stored seed values (incremental) or fetch/compute normally ──
    htf_bull_live   = True
    htf_bear_live   = False
    htf_spread_live = 0.0
    df_htf          = pd.DataFrame()
    _htf_from_seed  = False

    if alligator_seed and "htf_bullish" in alligator_seed:
        # Incremental mode — inject HTF values from stored alligator snapshot
        htf_bull_live   = bool(alligator_seed["htf_bullish"])
        htf_bear_live   = not htf_bull_live
        htf_spread_live = float(alligator_seed.get("htf_spread_pct", 0.0) or 0.0)
        _htf_from_seed  = True
    else:
        htf_ccxt_tf = cfg["htf_ccxt_tf"]
        htf_n       = TF_WARMUP_CANDLES[alligator_tf]
        htf_start   = _start_date_for_tf(htf_ccxt_tf, htf_n)
        if htf_df is not None:
            df_htf = htf_df
        else:
            try:
                df_htf = fetch_candles(pair, htf_ccxt_tf, start_date=htf_start, exchange=exchange)
            except Exception:
                pass

        if len(df_htf) >= 30:
            df_htf          = compute_alligator(df_htf)
            last_htf        = df_htf.iloc[-2]
            htf_bull_live   = bool(last_htf.get("ltf_bull",  False))
            htf_bear_live   = bool(last_htf.get("ltf_bear",  False))
            htf_spread_live = float(last_htf.get("ltf_spread_pct", 0.0) or 0.0)

    # ── Load strategy ─────────────────────────────────────────────────────
    try:
        strategy = load_strategy("cwt", {"htf_multiplier": 1})
    except Exception as e:
        if verbose:
            print(f"  {pair} [{alligator_tf}]: strategy load failed — {e}")
        return _no_signal(f"strategy_error:{e}", candles_fetched=len(df))

    # ── Incremental pre-compute (seeded indicators before IndicatorService) ──
    # compute_alligator and compute_heikin_ashi have guards: if their output
    # columns already exist in df they return early — so IndicatorService
    # calling them again afterwards is a safe no-op.
    if alligator_seed:
        compute_alligator(
            df,
            jaw_seed=alligator_seed.get("jaw_smma"),
            teeth_seed=alligator_seed.get("teeth_smma"),
            lips_seed=alligator_seed.get("lips_smma"),
        )
        compute_heikin_ashi(
            df,
            prev_ha_open=alligator_seed.get("last_ha_open"),
            prev_ha_close=alligator_seed.get("last_ha_close"),
        )

    # ── Compute LTF indicators ─────────────────────────────────────────────
    try:
        df = IndicatorService.compute(df, strategy.required_indicators())
    except Exception as e:
        if verbose:
            print(f"  {pair} [{alligator_tf}]: indicator error — {e}")
        return _no_signal(f"indicator_error:{e}", candles_fetched=len(df))

    # Inject live HTF values
    df["htf_bull"]       = htf_bull_live
    df["htf_bear"]       = htf_bear_live
    df["htf_spread_pct"] = htf_spread_live
    if _htf_from_seed:
        # Incremental mode — HTF alligator from stored values
        htf_jaw_v   = float(alligator_seed.get("htf_jaw",   0) or 0)
        htf_teeth_v = float(alligator_seed.get("htf_teeth", 0) or 0)
        htf_lips_v  = float(alligator_seed.get("htf_lips",  0) or 0)
        df["htf_jaw"]       = htf_jaw_v
        df["htf_teeth"]     = htf_teeth_v
        df["htf_lips"]      = htf_lips_v
        df["htf_jaw_off"]   = htf_jaw_v
        df["htf_teeth_off"] = htf_teeth_v
        df["htf_lips_off"]  = htf_lips_v
    elif len(df_htf) >= 30:
        last_htf = df_htf.iloc[-2]
        df["htf_jaw"]       = float(last_htf.get("jaw",       0) or 0)
        df["htf_teeth"]     = float(last_htf.get("teeth",     0) or 0)
        df["htf_lips"]      = float(last_htf.get("lips",      0) or 0)
        df["htf_jaw_off"]   = float(last_htf.get("jaw_off",   0) or 0)
        df["htf_teeth_off"] = float(last_htf.get("teeth_off", 0) or 0)
        df["htf_lips_off"]  = float(last_htf.get("lips_off",  0) or 0)

    if len(df) < 3:
        return _no_signal("insufficient_data_after_indicators", candles_fetched=len(df))

    # ── Extract seed for future incremental scans ─────────────────────────
    last_row   = df.iloc[-1]
    _new_seed  = {
        "jaw_smma":       float(last_row.get("jaw_off",   0) or 0),
        "teeth_smma":     float(last_row.get("teeth_off", 0) or 0),
        "lips_smma":      float(last_row.get("lips_off",  0) or 0),
        "last_ha_open":   float(last_row.get("ha_open",   0) or 0),
        "last_ha_close":  float(last_row.get("ha_close",  0) or 0),
        "last_timestamp": int(last_row.get("timestamp",   0) or 0),
    }

    # ── Check last `lookback` closed candles ──────────────────────────────
    hits: list[dict] = []
    candle_diagnostics: list[dict] = []

    for offset in range(2, 2 + lookback):
        if offset >= len(df):
            break
        row = df.iloc[-offset]

        candle_time = datetime.fromtimestamp(
            int(row["timestamp"]) / 1000, tz=timezone.utc
        ).strftime("%Y-%m-%d %H:%M UTC")

        signal = strategy.signal(row, position=None)
        candle_diagnostics.append({
            "candle_time": candle_time,
            "candles_ago": offset - 1,
            "signal":      signal.entry_type,
            **signal.conditions,
        })

        if signal.action == SignalAction.BUY:
            hits.append({
                "candle_time": candle_time,
                "price":       float(row["close"]),
                "sl_price":    signal.sl_price,
                "tp1_price":   signal.tp1_price,
                "tp2_price":   signal.tp2_price,
                "reason":      signal.reason,
                "candles_ago": offset - 1,
            })
        elif verbose:
            print(f"  [{pair}] [{alligator_tf}] {candle_time}: {signal.reason}")

    if not hits:
        return {
            "pair":                pair,
            "alligator_tf":        alligator_tf,
            "signal_found":        False,
            "skip_reason":         "no_setup",
            "candles_fetched":     len(df),
            "screener_score":      round(ticker.score, 4),
            "screener_confluence": ticker.confluence,
            "conditions_json":     candle_diagnostics,
            "alligator_seed":      _new_seed,
        }

    best = hits[0]
    entry_price = best["price"]
    sl_price    = best["sl_price"] or 0.0
    risk_pct    = ((entry_price - sl_price) / entry_price * 100) if sl_price and entry_price else 0.0

    return {
        "pair":                pair,
        "alligator_tf":        alligator_tf,
        "signal_found":        True,
        "price":               entry_price,
        "candle_time":         best["candle_time"],
        "candles_ago":         best["candles_ago"],
        "sl_price":            best["sl_price"],
        "tp1_price":           best["tp1_price"],
        "tp2_price":           best["tp2_price"],
        "reason":              best["reason"],
        "risk_pct":            round(risk_pct, 4),
        "all_hits":            len(hits),
        "candles_fetched":     len(df),
        "screener_score":      round(ticker.score, 4),
        "screener_confluence": ticker.confluence,
        "conditions_json":     candle_diagnostics,
        "alligator_seed":      _new_seed,
    }


def check_signal_direct(
    pair: str,
    alligator_tf: str,
    exchange: str = "binance",
    lookback: int = 1,
    verbose: bool = False,
) -> dict:
    """
    Directly check a specific pair on a specific TF — bypasses the screener entirely.
    Wraps check_signal() by constructing a minimal TickerScore.
    """
    symbol = pair.replace("/", "")
    ticker = TickerScore(
        symbol=symbol,
        price=0.0,
        rvol15m=0.0,
        score=0.0,
        confluence="manual",
        alligator_tf=alligator_tf,
    )
    return check_signal(ticker, exchange=exchange, lookback=lookback, verbose=verbose)
