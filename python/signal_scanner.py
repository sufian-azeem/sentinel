"""
signal_scanner.py — legacy entrypoint, kept for backward compatibility.

All logic has moved to the scanner/ package. Use run_scanner.py for new workflows.
"""

from scanner import check_signal, check_signal_direct, print_signals  # noqa: F401
from scanner.runner import main

if __name__ == "__main__":
    main()
