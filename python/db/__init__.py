"""
db — MySQL persistence layer for the trading dashboard.

Re-exports all public repository functions so callers can simply do:
    import db as repo
    repo.create_screener_run(...)
"""

from db.connection import get_persistent_connection
from db.repository import (
    create_screener_run,
    complete_screener_run,
    fail_screener_run,
    create_screener_result,
    update_screener_result_alligator,
    delete_signal_scans_for_run,
    create_signal_scan,
    update_signal_scan,
    create_signal,
    load_qualified_pairs,
    load_pair_by_result_id,
)

__all__ = [
    "get_persistent_connection",
    "create_screener_run",
    "complete_screener_run",
    "fail_screener_run",
    "create_screener_result",
    "update_screener_result_alligator",
    "delete_signal_scans_for_run",
    "create_signal_scan",
    "update_signal_scan",
    "create_signal",
    "load_qualified_pairs",
    "load_pair_by_result_id",
]
