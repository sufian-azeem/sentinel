import os
import json
from typing import Dict, Any, List
import anthropic

from agents.runner import BacktestRunner
from agents.tools import execute_tool, AGENT_TOOLS


REFINEMENT_SYSTEM_PROMPT = """You are a quantitative trading strategy optimizer. Your goal is to improve
risk-adjusted returns (Sharpe ratio) while maintaining:
- Max drawdown below 25%
- At least 50 trades (statistical validity)
- Profit factor > 1.0

Rules:
1. Start with baseline backtest at default params
2. Identify the single weakest metric first
3. Run focused sweeps (1-2 parameters at a time, not everything)
4. Validate final params before reporting
5. Flag overfit if: Sharpe > 3.0 on periods < 6 months, or < 30 trades

Available tools: run_backtest, run_sweep, compare_runs, get_run_details"""


class RefinementAgent:
    def __init__(self, api_key: str = None):
        self.client = anthropic.Anthropic(
            api_key=api_key or os.getenv("ANTHROPIC_API_KEY")
        )
        self.runner = BacktestRunner()

    def run(
        self, strategy_name: str, pair: str, timeframe: str, iterations: int = 3
    ) -> Dict[str, Any]:
        baseline = self.runner.run_backtest(strategy_name, pair, timeframe)
        baseline_sharpe = baseline.metrics.get("sharpe_ratio", 0)

        best_params = baseline.params_used.copy()
        best_sharpe = baseline_sharpe
        run_ids = [baseline.run_id]

        for i in range(iterations):
            prompt = f"""Current best params: {best_params}
Current best Sharpe: {best_sharpe:.2f}
Strategy: {strategy_name} on {pair} {timeframe}

Analyze the current metrics and identify the weakest metric that could be improved.
Suggest parameter sweep ranges (min, max, step) for 1-2 parameters to improve this metric.
Respond with JSON: {{"param_name": "value", "min": 0, "max": 100, "step": 1}}"""

            response = self.client.messages.create(
                model="claude-haiku-4-5-20251001",
                max_tokens=500,
                system=REFINEMENT_SYSTEM_PROMPT,
                messages=[{"role": "user", "content": prompt}],
            )

            try:
                sweep_params = json.loads(response.content[0].text)
            except:
                continue

            ranges = {
                k: (v["min"], v["max"], v["step"]) for k, v in sweep_params.items()
            }

            sweep_results = self.runner.run_sweep(
                strategy_name, pair, timeframe, ranges
            )

            if sweep_results:
                best_result = sweep_results[0]
                if best_result.metrics.get("sharpe_ratio", 0) > best_sharpe:
                    best_params = best_result.params_used.copy()
                    best_sharpe = best_result.metrics.get("sharpe_ratio", 0)
                    run_ids.append(best_result.run_id)

        improvement_pct = (
            ((best_sharpe - baseline_sharpe) / baseline_sharpe * 100)
            if baseline_sharpe > 0
            else 0
        )

        return {
            "best_params": best_params,
            "baseline_sharpe": baseline_sharpe,
            "final_sharpe": best_sharpe,
            "improvement_pct": improvement_pct,
            "run_ids": run_ids,
            "summary": f"Improved Sharpe from {baseline_sharpe:.2f} to {best_sharpe:.2f} ({improvement_pct:.1f}% improvement)",
        }
