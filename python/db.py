import sqlite3
import json
from pathlib import Path
from datetime import datetime
from typing import Optional, List

from config import settings
from models import BacktestResult


def init_db() -> None:
    settings.DB_PATH.parent.mkdir(parents=True, exist_ok=True)

    conn = sqlite3.connect(str(settings.DB_PATH))
    cursor = conn.cursor()

    cursor.execute("""
        CREATE TABLE IF NOT EXISTS strategies (
            strategy_id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            version TEXT,
            type TEXT,
            pairs TEXT,
            timeframes TEXT,
            parameters TEXT,
            risk_rules TEXT,
            created_at TEXT
        )
    """)

    cursor.execute("""
        CREATE TABLE IF NOT EXISTS backtest_runs (
            run_id TEXT PRIMARY KEY,
            strategy_id TEXT,
            strategy_name TEXT,
            params_used TEXT,
            pair TEXT,
            timeframe TEXT,
            start_date TEXT,
            end_date TEXT,
            starting_capital REAL,
            final_equity REAL,
            total_trades INTEGER,
            metrics TEXT,
            metadata TEXT,
            created_at TEXT,
            FOREIGN KEY (strategy_id) REFERENCES strategies(strategy_id)
        )
    """)

    cursor.execute("""
        CREATE TABLE IF NOT EXISTS run_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id TEXT,
            tag TEXT,
            created_at TEXT,
            FOREIGN KEY (run_id) REFERENCES backtest_runs(run_id)
        )
    """)

    conn.commit()
    conn.close()


def save_run(result: BacktestResult) -> str:
    init_db()

    conn = sqlite3.connect(str(settings.DB_PATH))
    cursor = conn.cursor()

    cursor.execute(
        """
        INSERT INTO backtest_runs (
            run_id, strategy_id, strategy_name, params_used, pair, timeframe,
            start_date, end_date, starting_capital, final_equity, total_trades,
            metrics, metadata, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """,
        (
            result.run_id,
            result.strategy_id,
            result.strategy_name,
            json.dumps(result.params_used),
            result.pair,
            result.timeframe,
            result.start_date,
            result.end_date,
            result.starting_capital,
            result.final_equity,
            result.total_trades,
            json.dumps(result.metrics),
            json.dumps(result.metadata),
            result.created_at,
        ),
    )

    conn.commit()
    conn.close()

    save_json_result(result)
    save_equity_csv(result)

    return result.run_id


def save_json_result(result: BacktestResult) -> Path:
    settings.RESULTS_DIR.mkdir(parents=True, exist_ok=True)

    path = settings.RESULTS_DIR / f"backtest_{result.run_id}.json"

    with open(path, "w") as f:
        json.dump(result.model_dump(), f, indent=2)

    return path


def save_equity_csv(result: BacktestResult) -> Path:
    settings.RESULTS_DIR.mkdir(parents=True, exist_ok=True)

    path = settings.RESULTS_DIR / f"equity_{result.run_id}.csv"

    lines = ["timestamp,equity\n"]
    for ts, eq in zip(result.equity_timestamps, result.equity_curve):
        lines.append(f"{ts},{eq}\n")

    with open(path, "w") as f:
        f.writelines(lines)

    return path


def get_run(run_id: str) -> Optional[BacktestResult]:
    init_db()

    conn = sqlite3.connect(str(settings.DB_PATH))
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()

    cursor.execute("SELECT * FROM backtest_runs WHERE run_id = ?", (run_id,))
    row = cursor.fetchone()

    conn.close()

    if not row:
        return None

    return BacktestResult(
        run_id=row["run_id"],
        strategy_id=row["strategy_id"],
        strategy_name=row["strategy_name"],
        params_used=json.loads(row["params_used"]),
        pair=row["pair"],
        timeframe=row["timeframe"],
        start_date=row["start_date"],
        end_date=row["end_date"],
        starting_capital=row["starting_capital"],
        final_equity=row["final_equity"],
        total_trades=row["total_trades"],
        trades=[],
        equity_curve=[],
        equity_timestamps=[],
        metrics=json.loads(row["metrics"]),
        metadata=json.loads(row["metadata"]),
        created_at=row["created_at"],
    )


def list_runs(
    strategy_name: Optional[str] = None, pair: Optional[str] = None, limit: int = 10
) -> List[dict]:
    init_db()

    conn = sqlite3.connect(str(settings.DB_PATH))
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()

    query = "SELECT run_id, strategy_name, pair, timeframe, start_date, end_date, final_equity, total_trades, metrics, created_at FROM backtest_runs"
    conditions = []
    params = []

    if strategy_name:
        conditions.append("strategy_name = ?")
        params.append(strategy_name)
    if pair:
        conditions.append("pair = ?")
        params.append(pair)

    if conditions:
        query += " WHERE " + " AND ".join(conditions)

    query += " ORDER BY created_at DESC LIMIT ?"
    params.append(limit)

    cursor.execute(query, params)
    rows = cursor.fetchall()

    conn.close()

    results = []
    for row in rows:
        metrics = json.loads(row["metrics"])
        results.append(
            {
                "run_id": row["run_id"],
                "strategy_name": row["strategy_name"],
                "pair": row["pair"],
                "timeframe": row["timeframe"],
                "start_date": row["start_date"],
                "end_date": row["end_date"],
                "final_equity": row["final_equity"],
                "total_trades": row["total_trades"],
                "sharpe_ratio": metrics.get("sharpe_ratio", 0),
                "max_drawdown_pct": metrics.get("max_drawdown_pct", 0),
                "win_rate_pct": metrics.get("win_rate_pct", 0),
                "created_at": row["created_at"],
            }
        )

    return results


def compare_runs(run_ids: List[str]) -> dict:
    init_db()

    conn = sqlite3.connect(str(settings.DB_PATH))
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()

    cursor.execute(
        """
        SELECT * FROM backtest_runs WHERE run_id IN ({})
    """.format(",".join("?" * len(run_ids))),
        run_ids,
    )

    rows = cursor.fetchall()
    conn.close()

    results = {}
    for row in rows:
        results[row["run_id"]] = {
            "strategy_name": row["strategy_name"],
            "pair": row["pair"],
            "timeframe": row["timeframe"],
            "params_used": json.loads(row["params_used"]),
            "metrics": json.loads(row["metrics"]),
            "total_trades": row["total_trades"],
            "final_equity": row["final_equity"],
            "created_at": row["created_at"],
        }

    return results


def tag_run(run_id: str, tag: str) -> None:
    init_db()

    conn = sqlite3.connect(str(settings.DB_PATH))
    cursor = conn.cursor()

    cursor.execute(
        """
        INSERT INTO run_tags (run_id, tag, created_at) VALUES (?, ?, ?)
    """,
        (run_id, tag, datetime.utcnow().isoformat()),
    )

    conn.commit()
    conn.close()
