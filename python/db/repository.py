"""
db.repository — all INSERT/UPDATE functions for the crypto_signals database.

Each function opens its own connection, executes, commits, and closes.
JSON columns are serialised with json.dumps before insertion.
"""

import json
from datetime import datetime

from db.connection import get_connection
from screener.models import TickerScore


# ---------------------------------------------------------------------------
# screener_runs
# ---------------------------------------------------------------------------

def create_screener_run(data_source: str, filters: dict) -> int:
    """Insert a new screener_run row and return its id."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO screener_runs
                    (data_source, filters_json, status, started_at, created_at)
                VALUES (%s, %s, 'running', NOW(), NOW())
                """,
                (data_source, json.dumps(filters)),
            )
            last_id = cur.lastrowid
        conn.commit()
        return last_id
    finally:
        conn.close()


def complete_screener_run(run_id: int, total_scanned: int, total_matched: int) -> None:
    """Mark a screener_run as completed with final counts."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE screener_runs
                SET status = 'completed', finished_at = NOW(),
                    total_scanned = %s, total_matched = %s
                WHERE id = %s
                """,
                (total_scanned, total_matched, run_id),
            )
        conn.commit()
    finally:
        conn.close()


def fail_screener_run(run_id: int, error_message: str) -> None:
    """Mark a screener_run as failed."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE screener_runs
                SET status = 'failed', error_message = %s, finished_at = NOW()
                WHERE id = %s
                """,
                (error_message[:65535], run_id),
            )
        conn.commit()
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# screener_results
# ---------------------------------------------------------------------------

def create_screener_result(run_id: int, ticker: TickerScore) -> int:
    """Insert one screener_result row for a ticker and return its id."""
    tf_data = {
        label: {
            "change_pct": snap.change_pct,
            "volume_usd": snap.volume_usd,
            "volatility":  snap.volatility,
            "vdelta":      snap.vdelta,
            "btc_corr":    snap.btc_corr,
            "bullish":     snap.bullish,
        }
        for label, snap in ticker.tfs.items()
    }

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO screener_results
                    (screener_run_id, symbol, pair, price, rvol, score,
                     alligator_tf, bullish_count, confluence,
                     qualified, disqualify_reason,
                     tf_data_json, filters_json, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                """,
                (
                    run_id,
                    ticker.symbol,
                    ticker.pair,
                    ticker.price,
                    ticker.rvol15m,
                    ticker.score,
                    ticker.alligator_tf if ticker.alligator_tf != "—" else None,
                    ticker.bullish_count,
                    ticker.confluence,
                    1 if ticker.qualified else 0,
                    ticker.disqualify_reason or None,
                    json.dumps(tf_data),
                    json.dumps(ticker.filters_json),
                ),
            )
            last_id = cur.lastrowid
        conn.commit()
        return last_id
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# screener_results (update)
# ---------------------------------------------------------------------------

def update_screener_result_alligator(
    result_id: int,
    tf_alligator: dict,
    tf_exchange: dict | None = None,
) -> None:
    """Merge alligator snapshot values into screener_result.tf_data_json.

    tf_alligator: {"15M": {"jaw": ..., "teeth": ..., "lips": ..., "bullish": ..., "spread_pct": ...}, ...}
    tf_exchange:  {"15M": "mexc", "1H": "binance", ...}  — which exchange supplied candle data per TF.
                  Stored at tf_data_json[tf]["exchange"] so progressive scans reuse the same source.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT tf_data_json FROM screener_results WHERE id = %s", (result_id,))
            row = cur.fetchone()
            existing = json.loads(row[0]) if row and row[0] else {}
            for tf, alligator_vals in tf_alligator.items():
                existing.setdefault(tf, {})["alligator"] = alligator_vals
                if tf_exchange and tf in tf_exchange:
                    existing[tf]["exchange"] = tf_exchange[tf]
            cur.execute(
                "UPDATE screener_results SET tf_data_json = %s WHERE id = %s",
                (json.dumps(existing), result_id),
            )
        conn.commit()
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# screener_results (read)
# ---------------------------------------------------------------------------

def load_qualified_pairs(screener_run_id: int, top_n: int) -> list[dict]:
    """Load top-N qualified screener results for a given run, ordered by score desc."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT id, pair, alligator_tf, price, score, confluence, rvol
                FROM screener_results
                WHERE screener_run_id = %s AND qualified = 1
                ORDER BY score DESC
                LIMIT %s
                """,
                (screener_run_id, top_n),
            )
            rows = cur.fetchall()
        return [
            {
                "screener_result_id": row[0],
                "pair":              row[1],
                "alligator_tf":      row[2],
                "price":             float(row[3]),
                "score":             float(row[4]),
                "confluence":        row[5] or "",
                "rvol":              float(row[6]),
            }
            for row in rows
        ]
    finally:
        conn.close()


def load_pair_by_result_id(result_id: int) -> dict | None:
    """Load a single screener result by its id (used by per-pair scanner jobs)."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT id, screener_run_id, pair, alligator_tf, price, score, confluence, rvol, tf_data_json
                FROM screener_results
                WHERE id = %s
                """,
                (result_id,),
            )
            row = cur.fetchone()
        if not row:
            return None
        return {
            "screener_result_id": row[0],
            "screener_run_id":    row[1],
            "pair":               row[2],
            "alligator_tf":       row[3],
            "price":              float(row[4]),
            "score":              float(row[5]),
            "confluence":         row[6] or "",
            "rvol":               float(row[7]),
            "tf_data_json":       json.loads(row[8]) if row[8] else {},
        }
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# signal_scans
# ---------------------------------------------------------------------------

def delete_signal_scans_for_run(run_id: int) -> None:
    """Delete all signal_scans for a screener run before re-scanning (signals are preserved)."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute("DELETE FROM signal_scans WHERE screener_run_id = %s", (run_id,))
        conn.commit()
    finally:
        conn.close()


def create_signal_scan(
    run_id: int,
    result_id: int | None,
    pair: str,
    timeframe: str,
    exchange: str,
    strategy: str,
) -> int:
    """Insert a new signal_scan row and return its id."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO signal_scans
                    (screener_run_id, screener_result_id, pair, timeframe,
                     exchange, strategy, status, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, 'scanned', NOW())
                """,
                (run_id, result_id, pair, timeframe, exchange, strategy),
            )
            last_id = cur.lastrowid
        conn.commit()
        return last_id
    finally:
        conn.close()


def update_signal_scan(
    scan_id: int,
    status: str,
    candles_fetched: int,
    conditions_json: list,
    error_message: str | None = None,
) -> None:
    """Update a signal_scan with results after check_signal() completes."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE signal_scans
                SET status = %s, candles_fetched = %s,
                    conditions_json = %s, error_message = %s
                WHERE id = %s
                """,
                (
                    status,
                    candles_fetched,
                    json.dumps(conditions_json),
                    error_message,
                    scan_id,
                ),
            )
        conn.commit()
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# signals
# ---------------------------------------------------------------------------

def create_signal(scan_id: int, result: dict) -> int:
    """Insert a signal row from a check_signal() result dict and return its id."""
    # Parse candle_time string → datetime
    candle_time_str = result.get("candle_time", "")
    try:
        candle_time = datetime.strptime(candle_time_str, "%Y-%m-%d %H:%M UTC")
    except ValueError:
        candle_time = datetime.utcnow()

    entry_price = result.get("price") or 0.0
    sl_price    = result.get("sl_price") or 0.0
    risk_pct    = result.get("risk_pct") or (
        (entry_price - sl_price) / entry_price * 100 if sl_price and entry_price else 0.0
    )

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO signals
                    (signal_scan_id, pair, timeframe, strategy,
                     entry_type, entry_price, sl_price, tp1_price, tp2_price,
                     risk_pct, candle_time, candles_ago,
                     screener_score, confluence, conditions_json,
                     status, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'active', NOW())
                """,
                (
                    scan_id,
                    result.get("pair", ""),
                    result.get("alligator_tf", ""),
                    "cwt",
                    result.get("reason", ""),
                    entry_price,
                    sl_price or None,
                    result.get("tp1_price") or None,
                    result.get("tp2_price") or None,
                    round(risk_pct, 4),
                    candle_time,
                    result.get("candles_ago", 1),
                    result.get("screener_score", 0.0),
                    result.get("screener_confluence", ""),
                    json.dumps(result.get("conditions_json", [])),
                ),
            )
            last_id = cur.lastrowid
        conn.commit()
        return last_id
    finally:
        conn.close()
