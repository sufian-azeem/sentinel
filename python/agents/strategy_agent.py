import os
import json
from typing import Dict, Any, List
import anthropic

from agents.tools import execute_tool, AGENT_TOOLS
from config import settings


STRATEGY_SYSTEM_PROMPT = """You are a quantitative trading strategy developer for crypto markets.
You write Python strategy classes that inherit from BaseStrategy.

The BaseStrategy interface (strategies/base.py):
    def name(self) -> str
    def default_params(self) -> dict[str, ParamDef]
    def indicators(self, df: pd.DataFrame) -> pd.DataFrame
    def signal(self, row: pd.Series, position: Position | None) -> Signal

Rules you MUST follow:
- Import only: from models import Signal, SignalAction, ParamDef, Position
- Import ta for indicators: import ta
- signal() must ALWAYS return a Signal object — never None, never raise
- Use self.params["param_name"] for ALL configurable values
- Define all params in default_params() with realistic min/max bounds
- Never access future data — only use values from the current `row`
- indicators() adds new columns to df and returns it — never drops existing columns

Signal actions: SignalAction.BUY, SELL, CLOSE, HOLD
Signal strength: 0.0 to 1.0 (used for position sizing)

Available ta indicators: RSI, MACD, Bollinger Bands, EMA, SMA, ADX,
Stochastic, ATR, Williams %R, OBV, and more. See ta-lib docs."""


class StrategyAgent:
    def __init__(self, api_key: str = None):
        self.client = anthropic.Anthropic(
            api_key=api_key or os.getenv("ANTHROPIC_API_KEY")
        )

    def run(
        self, strategy_type: str, pairs: List[str], timeframe: str
    ) -> Dict[str, Any]:
        prompt = f"""Create a {strategy_type} trading strategy for crypto markets.
Pairs: {pairs}
Timeframe: {timeframe}

Generate:
1. A Python strategy class (snake_case filename without .py, e.g. 'bollinger_reversion')
2. A JSON config file with parameters, risk_rules, etc.

Return JSON with:
{{"strategy_name": "...", "python_code": "...", "config_json": {{...}}}}"""

        max_retries = 3
        for attempt in range(max_retries):
            response = self.client.messages.create(
                model="claude-opus-4-6",
                max_tokens=4000,
                system=STRATEGY_SYSTEM_PROMPT,
                messages=[{"role": "user", "content": prompt}],
            )

            try:
                result = json.loads(response.content[0].text)

                write_result = execute_tool(
                    "write_strategy",
                    {
                        "strategy_name": result["strategy_name"],
                        "python_code": result["python_code"],
                        "config_json": result["config_json"],
                    },
                )

                if write_result["success"]:
                    from agents.runner import BacktestRunner

                    runner = BacktestRunner()

                    validation = runner.run_backtest(
                        result["strategy_name"], pairs[0], timeframe
                    )

                    return {
                        "strategy_name": result["strategy_name"],
                        "validation_result": {
                            "run_id": validation.run_id,
                            "total_trades": validation.total_trades,
                            "sharpe_ratio": validation.metrics.get("sharpe_ratio", 0),
                        },
                        "file_path": write_result["file_path"],
                        "notes": f"Created and validated successfully",
                    }
            except Exception as e:
                if attempt < max_retries - 1:
                    continue
                return {"error": str(e)}

        return {"error": "Failed after max retries"}
