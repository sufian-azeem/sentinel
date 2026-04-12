from typing import Optional, List
import pandas as pd

from data import storage
from strategies import loader
from engine.backtester import Backtester
from models import BacktestResult, RiskRules
from db import get_run, compare_runs as db_compare_runs
from config import settings
import json


class BacktestRunner:
    def __init__(self, capital: float = 10_000.0):
        self.capital = capital

    def _load_risk_rules(self, strategy_name: str) -> RiskRules:
        """Load RiskRules from the strategy's JSON config, falling back to defaults."""
        config_path = settings.CONFIGS_DIR / f"{strategy_name}.json"
        if config_path.exists():
            with open(config_path) as f:
                data = json.load(f)
            risk_data = data.get("risk_rules", {})
            return RiskRules(**risk_data)
        return RiskRules()

    def run_backtest(
        self,
        strategy_name: str,
        pair: str,
        timeframe: str,
        params: Optional[dict] = None,
        capital: Optional[float] = None,
        risk_rules: Optional[RiskRules] = None,
    ) -> BacktestResult:
        df = storage.load_candles(pair, timeframe)

        strategy = loader.load_strategy(strategy_name, params)

        resolved_rules = risk_rules or self._load_risk_rules(strategy_name)

        backtester = Backtester(starting_capital=capital or self.capital)

        result = backtester.run(
            strategy=strategy, df=df, pair=pair, timeframe=timeframe,
            risk_rules=resolved_rules,
        )

        return result

    def run_sweep(
        self,
        strategy_name: str,
        pair: str,
        timeframe: str,
        param_ranges: dict[str, tuple[float, float, float]],
    ) -> List[BacktestResult]:
        import itertools

        results = []

        keys = list(param_ranges.keys())
        ranges = []
        for k in keys:
            min_val, max_val, step = param_ranges[k]
            values = list(range(int(min_val), int(max_val) + 1, int(step)))
            ranges.append(values)

        for combo in itertools.product(*ranges):
            params = dict(zip(keys, combo))

            try:
                result = self.run_backtest(strategy_name, pair, timeframe, params)
                results.append(result)
            except Exception:
                continue

        results.sort(key=lambda x: x.metrics.get("sharpe_ratio", 0), reverse=True)

        return results

    def get_run(self, run_id: str) -> Optional[BacktestResult]:
        return get_run(run_id)

    def compare_runs(self, run_ids: List[str]) -> pd.DataFrame:
        results = db_compare_runs(run_ids)

        rows = []
        for run_id, data in results.items():
            row = {
                "run_id": run_id,
                "strategy_name": data["strategy_name"],
                "pair": data["pair"],
                "timeframe": data["timeframe"],
                "total_trades": data["total_trades"],
                "final_equity": data["final_equity"],
            }
            row.update(data["metrics"])
            rows.append(row)

        return pd.DataFrame(rows)

    def list_strategies(self) -> List[dict]:
        return loader.list_strategies()

    def list_data(self) -> List[dict]:
        return storage.list_available()
