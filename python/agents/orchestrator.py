import os
import json
from datetime import datetime
from typing import Dict, Any, List, Optional

from config import settings
from agents.runner import BacktestRunner
from agents.strategy_agent import StrategyAgent
from agents.refinement_agent import RefinementAgent


class Orchestrator:
    def __init__(self, api_key: str = None):
        self.runner = BacktestRunner()
        self.strategy_agent = StrategyAgent(api_key)
        self.refinement_agent = RefinementAgent(api_key)

    def run_full_cycle(
        self,
        pairs: List[str],
        timeframes: List[str],
        strategy_names: Optional[List[str]] = None,
        generate_new: int = 0,
        cycles: int = 1,
    ) -> Dict[str, Any]:
        all_results = []

        strategies = strategy_names or []

        available = self.runner.list_strategies()
        strategies = [
            s["name"] for s in available if s["name"] not in ["base", "loader"]
        ]

        for cycle in range(cycles):
            print(f"\n=== Cycle {cycle + 1}/{cycles} ===")

            if generate_new > 0 and cycle < generate_new:
                for i in range(generate_new):
                    strategy_types = ["momentum", "mean_reversion", "breakout"]
                    strategy_type = strategy_types[i % len(strategy_types)]

                    result = self.strategy_agent.run(
                        strategy_type=strategy_type,
                        pairs=pairs,
                        timeframe=timeframes[0],
                    )

                    if "strategy_name" in result:
                        strategies.append(result["strategy_name"])

            for strategy in strategies:
                for pair in pairs:
                    for timeframe in timeframes:
                        try:
                            baseline = self.runner.run_backtest(
                                strategy, pair, timeframe
                            )

                            refinement = self.refinement_agent.run(
                                strategy_name=strategy,
                                pair=pair,
                                timeframe=timeframe,
                                iterations=1,
                            )

                            all_results.append(
                                {
                                    "strategy": strategy,
                                    "pair": pair,
                                    "timeframe": timeframe,
                                    "run_id": baseline.run_id,
                                    "sharpe_ratio": baseline.metrics.get(
                                        "sharpe_ratio", 0
                                    ),
                                    "max_drawdown_pct": baseline.metrics.get(
                                        "max_drawdown_pct", 0
                                    ),
                                    "win_rate_pct": baseline.metrics.get(
                                        "win_rate_pct", 0
                                    ),
                                    "profit_factor": baseline.metrics.get(
                                        "profit_factor", 0
                                    ),
                                    "total_trades": baseline.total_trades,
                                    "best_params": refinement.get("best_params", {}),
                                }
                            )
                        except Exception as e:
                            print(f"Error with {strategy} {pair} {timeframe}: {e}")

            rankings = self._rank_strategies(all_results)

            settings.RESULTS_DIR.mkdir(parents=True, exist_ok=True)
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            report_path = settings.RESULTS_DIR / f"agent_report_{timestamp}.json"

            with open(report_path, "w") as f:
                json.dump(
                    {
                        "timestamp": timestamp,
                        "cycles": cycles,
                        "pairs": pairs,
                        "timeframes": timeframes,
                        "rankings": rankings,
                        "all_results": all_results,
                    },
                    f,
                    indent=2,
                )

            print(f"\nReport saved to {report_path}")

        return {"rankings": rankings, "report_path": str(report_path)}

    def _rank_strategies(self, results: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        scored = []

        for r in results:
            sharpe = r.get("sharpe_ratio", 0)
            drawdown = r.get("max_drawdown_pct", 0)
            win_rate = r.get("win_rate_pct", 0)
            profit_factor = r.get("profit_factor", 0)
            trades = r.get("total_trades", 0)

            score = (
                sharpe * 0.35
                + (1 - drawdown / 100) * 0.25
                + win_rate / 100 * 0.20
                + min(profit_factor / 5, 1.0) * 0.10
                + min(trades / 200, 1.0) * 0.10
            )

            scored.append({**r, "score": score})

        scored.sort(key=lambda x: x["score"], reverse=True)

        return scored
