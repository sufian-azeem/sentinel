"""
screener.scoring — filter, score, and rank tickers for Alligator suitability.

Pure business logic; no I/O, no display.
"""

from typing import Optional

from screener.models import TickerScore, TFSnapshot, TIMEFRAMES, ALLIGATOR_TF_RULES


# Minimum volatility for a TF to be considered "trending" (alligator mouth open).
# Below this the market is ranging/sleeping.
_MIN_VOLA_BULLISH = 0.03   # 0.03% per candle

# Shared filter defaults — single source of truth for both screener and scanner runners.
DEFAULT_MIN_CHANGE_PCT  = 0.2
DEFAULT_MIN_VOLUME_USD  = 100_000
DEFAULT_MIN_RVOL        = 0.4
DEFAULT_MAX_BTC_CORR    = 0.97
DEFAULT_MIN_BULLISH_TFS = 3


def _safe(d: Optional[dict], key: str, default: float = 0.0) -> float:
    if not d:
        return default
    v = d.get(key, default)
    return float(v) if v is not None else default


def _recommend_alligator_tf(bullish_labels: set[str]) -> str:
    """
    Return the best Alligator entry timeframe given which TFs are bullish.
    Prefers the lowest TF with macro confirmation above it.
    """
    for entry_tf, required in ALLIGATOR_TF_RULES:
        if all(tf in bullish_labels for tf in required):
            return entry_tf
    return "—"


def filter_and_score(
    tickers: list[dict],
    min_change_pct: float = DEFAULT_MIN_CHANGE_PCT,
    min_volume_usd: float = DEFAULT_MIN_VOLUME_USD,
    min_rvol: float = DEFAULT_MIN_RVOL,
    max_btc_corr: float = DEFAULT_MAX_BTC_CORR,
    min_bullish_tfs: int = DEFAULT_MIN_BULLISH_TFS,
) -> list[TickerScore]:
    """
    Analyse all 7 timeframes per ticker and score for Alligator suitability.

    Hard filters:
    - At least min_bullish_tfs timeframes must be bullish (trending up, mouth open)
    - 1H volume ≥ min_volume_usd
    - rvol15m ≥ min_rvol
    - BTC correlation on 1H ≤ max_btc_corr
    - USDT/USDC pairs only; stablecoins/leveraged tokens excluded

    Returns ALL evaluated tickers (qualified + disqualified), sorted:
    qualified first by descending score, then disqualified.
    Each ticker carries filters_json with per-filter pass/fail and actual values.
    """
    results: list[TickerScore] = []

    _skip_contains = ("UP", "DOWN", "BULL", "BEAR", "3L", "3S", "2L", "2S")

    for t in tickers:
        # ── Normalise symbol ──────────────────────────────────────────────
        raw_sym    = t.get("symbol", "")
        quote_curr = t.get("quoteCurrency", "")

        if quote_curr:
            base = raw_sym
            sym  = raw_sym + quote_curr
        else:
            sym  = raw_sym
            base = sym[:-4] if sym.endswith("USDT") else sym[:-4] if sym.endswith("USDC") else sym

        # ── Symbol-level exclusions (no filter_json entry — not meaningful) ──
        _stable_bases = ("USDC", "BUSD", "DAI", "TUSD", "FDUSD")
        if base in _stable_bases:
            continue
        if ":" in raw_sym:
            continue
        if any(s in base for s in _skip_contains):
            continue
        if base and base[0].isdigit():
            continue  # futures multiplier prefix e.g. 1000PEPE, 1000SHIB
        if not (sym.endswith("USDT") or sym.endswith("USDC")):
            continue

        price = float(t.get("price") or 0.0)
        if price <= 0:
            continue

        rvol = float(t.get("rvol15m") or 0.0)

        # ── Build per-TF snapshots ────────────────────────────────────────
        snapshots: dict[str, TFSnapshot] = {}
        for api_key, label, _ in TIMEFRAMES:
            raw  = t.get(api_key) or {}
            chg  = _safe(raw, "changePercent")
            vol  = _safe(raw, "volume")
            vola = _safe(raw, "volatility")
            vd   = _safe(raw, "vdelta")
            corr = _safe(raw, "btcCorrelation", default=1.0)
            bullish = chg >= min_change_pct and vola >= _MIN_VOLA_BULLISH
            snapshots[label] = TFSnapshot(
                label=label,
                change_pct=chg,
                volume_usd=vol,
                volatility=vola,
                vdelta=vd,
                btc_corr=corr,
                bullish=bullish,
            )

        vol_1h  = snapshots["1H"].volume_usd if "1H" in snapshots else 0.0
        corr_1h = snapshots["1H"].btc_corr   if "1H" in snapshots else 1.0

        bullish_labels = {lb for lb, snap in snapshots.items() if snap.bullish}
        bullish_count  = len(bullish_labels)

        # ── Hard filters — track pass/fail per filter ─────────────────────
        filters: dict = {
            "volume_1h":   {"value": round(vol_1h, 2),  "threshold": min_volume_usd, "pass": vol_1h >= min_volume_usd},
            "rvol_15m":    {"value": round(rvol, 4),    "threshold": min_rvol,        "pass": rvol >= min_rvol},
            "btc_corr":    {"value": round(corr_1h, 4), "threshold": max_btc_corr,    "pass": corr_1h <= max_btc_corr},
            "bullish_tfs": {"value": bullish_count,     "threshold": min_bullish_tfs, "pass": bullish_count >= min_bullish_tfs},
        }

        disqualify_reason = ""
        if not filters["volume_1h"]["pass"]:
            disqualify_reason = "low_volume"
        elif not filters["rvol_15m"]["pass"]:
            disqualify_reason = "low_rvol"
        elif not filters["btc_corr"]["pass"]:
            disqualify_reason = "high_btc_corr"
        elif not filters["bullish_tfs"]["pass"]:
            disqualify_reason = "low_bullish_tfs"

        qualified = disqualify_reason == ""

        alligator_tf   = _recommend_alligator_tf(bullish_labels)
        confluence_str = " ".join(lb for _, lb, _ in TIMEFRAMES if lb in bullish_labels)

        # ── Composite score (only meaningful for qualified tickers) ───────
        composite = 0.0
        if qualified:
            for api_key, label, weight in TIMEFRAMES:
                if label not in snapshots:
                    continue
                snap = snapshots[label]
                momentum   = min(abs(snap.change_pct) / 10.0, 1.0) * (1.0 if snap.change_pct > 0 else -0.3)
                vola_score = min(snap.volatility / 1.0, 1.0)
                composite += weight * (0.7 * momentum + 0.3 * vola_score)

            rvol_bonus  = min(rvol / 3.0, 1.0) * 0.05
            indep_bonus = (1.0 - abs(corr_1h)) * 0.03
            vd_15m      = snapshots["15M"].vdelta     if "15M" in snapshots else 0.0
            vol_15m     = snapshots["15M"].volume_usd if "15M" in snapshots else 1.0
            vd_bonus    = (min(max(vd_15m / max(vol_15m, 1), -1.0), 1.0) * 0.5 + 0.5) * 0.02
            composite  += rvol_bonus + indep_bonus + vd_bonus
            composite  += (bullish_count / len(TIMEFRAMES)) * 0.05

        results.append(TickerScore(
            symbol=sym,
            price=price,
            rvol15m=rvol,
            tfs=snapshots,
            score=composite,
            alligator_tf=alligator_tf,
            bullish_count=bullish_count,
            confluence=confluence_str,
            qualified=qualified,
            disqualify_reason=disqualify_reason,
            filters_json=filters,
        ))

    # Qualified first (by score desc), then disqualified
    results.sort(key=lambda x: (not x.qualified, -x.score))
    return results
