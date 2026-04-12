#!/usr/bin/env python3
"""PreToolUse hook: intercept Read calls on .json files and return TOON output instead."""
import sys
import json
import subprocess
from pathlib import Path

data = json.load(sys.stdin)
file_path = data.get("file_path", "")

if not file_path.endswith(".json"):
    sys.exit(0)  # not JSON — let Read proceed normally

compact_script = Path(__file__).parent.parent / "compact_json.py"

result = subprocess.run(
    ["python3", str(compact_script), file_path],
    capture_output=True,
    text=True,
)

if result.returncode != 0:
    sys.exit(0)  # compact failed — fall back to normal Read

print(result.stdout)
sys.exit(2)  # block Read, Claude sees TOON output instead
