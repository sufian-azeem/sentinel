import json
import re
from pathlib import Path
from typing import Any, Dict, List

from config import settings
from models import BacktestResult
from agents.runner import BacktestRunner


runner = BacktestRunner()


def run_backtest(
    strategy_name: str,
    pair: str,
    timeframe: str,
    params: Dict[str, Any] = None,
    capital: float = 10000.0,
) -> Dict[str, Any]:
    try:
        result = runner.run_backtest(strategy_name, pair, timeframe, params, capital)
        return {
            "success": True,
            "run_id": result.run_id,
            "total_trades": result.total_trades,
            "final_equity": result.final_equity,
            "metrics": result.metrics,
            "params_used": result.params_used,
        }
    except Exception as e:
        return {"success": False, "error": str(e)}


def run_sweep(
    strategy_name: str,
    pair: str,
    timeframe: str,
    param_ranges: Dict[str, Dict[str, float]],
) -> Dict[str, Any]:
    try:
        ranges = {}
        for name, config in param_ranges.items():
            ranges[name] = (config["min"], config["max"], config.get("step", 1))

        results = runner.run_sweep(strategy_name, pair, timeframe, ranges)

        return {
            "success": True,
            "results": [
                {
                    "run_id": r.run_id,
                    "params": r.params_used,
                    "sharpe_ratio": r.metrics.get("sharpe_ratio", 0),
                    "total_return_pct": r.metrics.get("total_return_pct", 0),
                    "max_drawdown_pct": r.metrics.get("max_drawdown_pct", 0),
                    "total_trades": r.total_trades,
                }
                for r in results[:10]
            ],
        }
    except Exception as e:
        return {"success": False, "error": str(e)}


def compare_runs(run_ids: List[str]) -> Dict[str, Any]:
    try:
        df = runner.compare_runs(run_ids)
        return {"success": True, "comparison": df.to_dict(orient="records")}
    except Exception as e:
        return {"success": False, "error": str(e)}


def get_run_details(run_id: str) -> Dict[str, Any]:
    try:
        result = runner.get_run(run_id)
        if not result:
            return {"success": False, "error": "Run not found"}

        return {
            "success": True,
            "run_id": result.run_id,
            "strategy_name": result.strategy_name,
            "pair": result.pair,
            "timeframe": result.timeframe,
            "params_used": result.params_used,
            "metrics": result.metrics,
            "total_trades": result.total_trades,
        }
    except Exception as e:
        return {"success": False, "error": str(e)}


def list_strategies() -> Dict[str, Any]:
    try:
        strategies = runner.list_strategies()
        return {"success": True, "strategies": strategies}
    except Exception as e:
        return {"success": False, "error": str(e)}


def write_strategy(
    strategy_name: str, python_code: str, config_json: Dict[str, Any]
) -> Dict[str, Any]:
    try:
        if not re.match(r"^[a-z][a-z0-9_]+$", strategy_name):
            return {"success": False, "error": "Invalid strategy name"}

        if strategy_name in ["base", "loader", "__init__"]:
            return {"success": False, "error": "Reserved name"}

        strategy_path = settings.STRATEGIES_DIR / f"{strategy_name}.py"
        config_path = settings.CONFIGS_DIR / f"{strategy_name}.json"

        with open(strategy_path, "w") as f:
            f.write(python_code)

        with open(config_path, "w") as f:
            json.dump(config_json, f, indent=2)

        return {
            "success": True,
            "file_path": str(strategy_path),
            "config_path": str(config_path),
        }
    except Exception as e:
        return {"success": False, "error": str(e)}


AGENT_TOOLS = [
    {
        "name": "run_backtest",
        "description": "Run a single backtest for a strategy on a pair/timeframe.",
        "input_schema": {
            "type": "object",
            "properties": {
                "strategy_name": {"type": "string"},
                "pair": {"type": "string"},
                "timeframe": {"type": "string"},
                "params": {"type": "object"},
                "capital": {"type": "number", "default": 10000.0},
            },
            "required": ["strategy_name", "pair", "timeframe"],
        },
    },
    {
        "name": "run_sweep",
        "description": "Grid search over parameter ranges. Returns top results sorted by Sharpe.",
        "input_schema": {
            "type": "object",
            "properties": {
                "strategy_name": {"type": "string"},
                "pair": {"type": "string"},
                "timeframe": {"type": "string"},
                "param_ranges": {"type": "object"},
            },
            "required": ["strategy_name", "pair", "timeframe", "param_ranges"],
        },
    },
    {
        "name": "compare_runs",
        "description": "Compare multiple backtest runs side-by-side by run_id.",
        "input_schema": {
            "type": "object",
            "properties": {"run_ids": {"type": "array", "items": {"type": "string"}}},
            "required": ["run_ids"],
        },
    },
    {
        "name": "get_run_details",
        "description": "Get full metrics and trade summary for a specific run_id.",
        "input_schema": {
            "type": "object",
            "properties": {"run_id": {"type": "string"}},
            "required": ["run_id"],
        },
    },
    {
        "name": "list_strategies",
        "description": "List all available strategy files and their config status.",
        "input_schema": {"type": "object", "properties": {}},
    },
    {
        "name": "write_strategy",
        "description": "Write a new strategy Python file and its JSON config to the strategies/ directory.",
        "input_schema": {
            "type": "object",
            "properties": {
                "strategy_name": {"type": "string"},
                "python_code": {"type": "string"},
                "config_json": {"type": "object"},
            },
            "required": ["strategy_name", "python_code", "config_json"],
        },
    },
]


def execute_tool(name: str, input_data: Dict[str, Any]) -> Dict[str, Any]:
    if name == "run_backtest":
        return run_backtest(**input_data)
    elif name == "run_sweep":
        return run_sweep(**input_data)
    elif name == "compare_runs":
        return compare_runs(**input_data)
    elif name == "get_run_details":
        return get_run_details(**input_data)
    elif name == "list_strategies":
        return list_strategies()
    elif name == "write_strategy":
        return write_strategy(**input_data)
    else:
        return {"success": False, "error": f"Unknown tool: {name}"}
