"""
scanner — Alligator signal scanner package.

Re-exports public API so existing code using
`from scanner import check_signal, ...` continues to work.
"""

from scanner.checker import check_signal, check_signal_direct
from scanner.display import print_signals

__all__ = [
    "check_signal",
    "check_signal_direct",
    "print_signals",
]
