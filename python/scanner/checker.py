"""
scanner.checker — per-pair signal evaluation.

Fetches candles, computes indicators, calls strategy.signal(), and returns
a result dict for every BUY hit found within the lookback window.
"""

from datetime import datetime, timezone

import pandas as pd

from screener.models import TickerScore
from scanner.config import TF_CONFIG, TF_WARMUP_CANDLES
from scanner.fetcher import _start_date_for_tf, _pair_to_ccxt, _normalize_pair_for_exchange
from data.fetcher import fetch_candles
from indicators.library.alligator import Alligator, AlligatorValues, compute_alligator
from indicators.library.heikin_ashi import compute_heikin_ashi
from models import Candle, SignalAction
from strategies.loader import load_strategy
from indicators.service import IndicatorService

# Register all indicators (side-effect import)
import indicators  # noqa: F401


# ---------------------------------------------------------------------------
# Diagnostic builder
# ---------------------------------------------------------------------------

def _build_diagnostic(
    pair: str,
    alligator_tf: str,
    row: pd.Series,
    candle_time: str,
    offset: int,
    verbose: bool = False,
) -> dict:
    """
    Build a structured diagnostic dict for one candle.
    Optionally prints the verbose breakdown to stdout.
    Returns the dict regardless of verbose flag.
    """
    ago = "last closed" if offset == 2 else f"{offset - 1} candles ago"

    close      = float(row.get("close") or 0)
    open_      = float(row.get("open")  or 0)
    low        = float(row.get("low")   or 0)
    high       = float(row.get("high")  or 0)
    htf_spread = float(row.get("htf_spread_pct", 0) or 0)
    ltf_spread = float(row.get("ltf_spread_pct", 0) or 0)

    ltf  = AlligatorValues(float(row.get("jaw_off",  0) or 0), float(row.get("teeth_off", 0) or 0), float(row.get("lips_off", 0) or 0))
    disp = AlligatorValues(float(row.get("jaw",  0) or 0),     float(row.get("teeth", 0) or 0),     float(row.get("lips", 0) or 0))
    htf  = AlligatorValues(float(row.get("htf_jaw_off",  0) or 0), float(row.get("htf_teeth_off", 0) or 0), float(row.get("htf_lips_off", 0) or 0))
    htf_bullish = Alligator.is_bullish(htf) if htf.jaw else True

    htf_jaw   = float(row.get("htf_jaw",   0) or 0)
    htf_teeth = float(row.get("htf_teeth", 0) or 0)
    htf_lips  = float(row.get("htf_lips",  0) or 0)

    bar1 = Candle(
        open  = float(row.get("prev_ha_open",  0) or 0),
        high  = float(row.get("prev_ha_high",  0) or 0),
        low   = float(row.get("prev_ha_low",   float("inf"))),
        close = float(row.get("prev_ha_close", 0) or 0),
    )
    bar2 = Candle(
        open  = float(row.get("ha_open",  0) or 0),
        high  = float(row.get("ha_high",  0) or 0),
        low   = float(row.get("ha_low",   0) or 0),
        close = float(row.get("ha_close", 0) or 0),
    )

    alignment_streak = int(row.get("alignment_streak", 0) or 0)

    bar1_touching = Alligator.is_candle_touching(bar1, disp)
    bar2_above    = Alligator.is_candle_above(bar2, disp)
    body_expand   = bar2.body > bar1.body

    bar1_awake = Alligator.is_candle_touching(bar1, disp) or Alligator.is_candle_above(bar1, disp)
    bar1_green = bar1.close > bar1.open
    bar2_green = bar2.close > bar2.open

    pullback  = htf_bullish and Alligator.is_bullish(ltf) and bar1_touching and bar2_above and body_expand
    awakening = (
        htf_bullish
        and 2 <= alignment_streak <= 8
        and Alligator.lips_crossed_teeth(disp)
        and bar1_awake and bar2_above
        and bar2_green and bar1_green
    )

    sl_price    = disp.jaw * 0.999
    sl_dist     = close - sl_price
    sl_ok       = sl_dist > 0
    sl_dist_pct = (sl_dist / close * 100) if close > 0 else 0.0

    if pullback and sl_ok:
        signal_type = "Pullback"
    elif awakening and sl_ok:
        signal_type = "Awakening"
    else:
        signal_type = None

    diagnostic = {
        "candle_time": candle_time,
        "candles_ago": offset - 1,
        "ltf": {
            "jaw": round(disp.jaw, 8), "teeth": round(disp.teeth, 8), "lips": round(disp.lips, 8),
            "jaw_off": round(ltf.jaw, 8), "teeth_off": round(ltf.teeth, 8), "lips_off": round(ltf.lips, 8),
            "spread_pct": round(ltf_spread, 4),
        },
        "htf": {
            "jaw": round(htf_jaw, 8), "teeth": round(htf_teeth, 8), "lips": round(htf_lips, 8),
            "jaw_off": round(htf.jaw, 8), "teeth_off": round(htf.teeth, 8), "lips_off": round(htf.lips, 8),
            "spread_pct": round(htf_spread, 4),
        },
        "candle": {"open": round(open_, 8), "high": round(high, 8), "low": round(low, 8), "close": round(close, 8)},
        "ha_bar1": {"open": round(bar1.open, 8), "high": round(bar1.high, 8), "low": round(bar1.low, 8), "close": round(bar1.close, 8), "body": round(bar1.body, 8)},
        "ha_bar2": {"open": round(bar2.open, 8), "high": round(bar2.high, 8), "low": round(bar2.low, 8), "close": round(bar2.close, 8), "body": round(bar2.body, 8)},
        "alignment_streak": alignment_streak,
        "sl_dist_pct": round(sl_dist_pct, 4),
        "conditions": {
            "pullback": {
                "htf_bullish":   {"pass": htf_bullish},
                "ltf_bullish":   {"pass": Alligator.is_bullish(ltf)},
                "bar1_touching": {"pass": bar1_touching},
                "bar2_above":    {"pass": bar2_above},
                "body_expand":   {"pass": body_expand},
                "sl_ok":         {"pass": sl_ok, "sl_dist_pct": round(sl_dist_pct, 4)},
            },
            "awakening": {
                "htf_bullish":         {"pass": htf_bullish},
                "streak_2_8":          {"pass": 2 <= alignment_streak <= 8, "value": alignment_streak},
                "lips_crossed_teeth":  {"pass": Alligator.lips_crossed_teeth(disp)},
                "bar1_touching_above": {"pass": bar1_awake},
                "bar1_ha_green":       {"pass": bar1_green},
                "bar2_above":          {"pass": bar2_above},
                "bar2_ha_green":       {"pass": bar2_green},
                "sl_ok":               {"pass": sl_ok, "sl_dist_pct": round(sl_dist_pct, 4)},
            },
        },
        "signal": signal_type,
    }

    if verbose:
        _check = lambda c: "PASS" if c else "FAIL"
        print(
            f"\n  {pair} [{alligator_tf}] — {candle_time} ({ago})\n"
            f"    LTF  jaw={disp.jaw:.4f}  teeth={disp.teeth:.4f}  lips={disp.lips:.4f}  spread={ltf_spread:.3f}%\n"
            f"    LTF  jaw_off={ltf.jaw:.4f}  teeth_off={ltf.teeth:.4f}  lips_off={ltf.lips:.4f}\n"
            f"    HTF  jaw={htf_jaw:.4f}  teeth={htf_teeth:.4f}  lips={htf_lips:.4f}  spread={htf_spread:.3f}%\n"
            f"    HTF  jaw_off={htf.jaw:.4f}  teeth_off={htf.teeth:.4f}  lips_off={htf.lips:.4f}\n"
            f"    Current candle  : O={open_:.4f} H={high:.4f} L={low:.4f} C={close:.4f}\n"
            f"    HA Bar1 (prev)  : O={bar1.open:.4f}  H={bar1.high:.4f}  L={bar1.low:.4f}  C={bar1.close:.4f}  body={bar1.body:.4f}\n"
            f"    HA Bar2 (curr)  : O={bar2.open:.4f}  H={bar2.high:.4f}  L={bar2.low:.4f}  C={bar2.close:.4f}  body={bar2.body:.4f}\n"
            f"    ── Pullback ────────────────────────────────────────────\n"
            f"    HTF bullish        : {_check(htf_bullish)}   lips_off={htf.lips:.4f} > teeth_off={htf.teeth:.4f} > jaw_off={htf.jaw:.4f}\n"
            f"    LTF bullish        : {_check(Alligator.is_bullish(ltf))}   lips_off={ltf.lips:.4f} > teeth_off={ltf.teeth:.4f} > jaw_off={ltf.jaw:.4f}\n"
            f"    Bar1 touching zone : {_check(bar1_touching)}   low={bar1.low:.4f} <= lips={disp.lips:.4f}  close={bar1.close:.4f} > lips={disp.lips:.4f}\n"
            f"    Bar2 above zone    : {_check(bar2_above)}   low={bar2.low:.4f} > lips={disp.lips:.4f}\n"
            f"    Bar2 body expand   : {_check(body_expand)}   body={bar2.body:.4f} > prev_body={bar1.body:.4f}\n"
            f"    SL (jaw>0)         : {_check(sl_ok)}   dist={sl_dist_pct:.2f}%  jaw={disp.jaw:.4f}\n"
            f"    ── Awakening (streak 2-8) ──────────────────────────────\n"
            f"    Streak 2-8         : {_check(2 <= alignment_streak <= 8)}   streak={alignment_streak}\n"
            f"    Lips > Teeth       : {_check(Alligator.lips_crossed_teeth(disp))}   lips={disp.lips:.4f} > teeth={disp.teeth:.4f}\n"
            f"    Bar1 touching/above: {_check(bar1_awake)}   touching={Alligator.is_candle_touching(bar1, disp)}  above={Alligator.is_candle_above(bar1, disp)}\n"
            f"    Bar1 HA green      : {_check(bar1_green)}   close={bar1.close:.4f} > open={bar1.open:.4f}\n"
            f"    Bar2 above zone    : {_check(bar2_above)}   low={bar2.low:.4f} > lips={disp.lips:.4f}\n"
            f"    Bar2 HA green      : {_check(bar2_green)}   close={bar2.close:.4f} > open={bar2.open:.4f}\n"
            f"    ── Result: {'BUY ✓ [' + signal_type + ']' if signal_type else 'NO SETUP'}"
        )

    return diagnostic


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

        diagnostic = _build_diagnostic(pair, alligator_tf, row, candle_time, offset, verbose=verbose)
        candle_diagnostics.append(diagnostic)

        if diagnostic["signal"] is not None:
            try:
                signal = strategy.signal(row, position=None)
            except Exception:
                continue

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
