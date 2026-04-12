import importlib
import json
from pathlib import Path
from typing import Optional

from config import settings
from models import StrategyConfig
from strategies.base import BaseStrategy


def load_strategy(name: str, params: Optional[dict] = None) -> BaseStrategy:
    module = importlib.import_module(f"strategies.{name}")

    strategy_class = None
    for attr_name in dir(module):
        attr = getattr(module, attr_name)
        if (
            isinstance(attr, type)
            and issubclass(attr, BaseStrategy)
            and attr != BaseStrategy
        ):
            strategy_class = attr
            break

    if strategy_class is None:
        raise ValueError(f"No BaseStrategy subclass found in strategies/{name}.py")

    config_path = settings.CONFIGS_DIR / f"{name}.json"
    strategy_params = params.copy() if params else {}

    if config_path.exists():
        with open(config_path) as f:
            config_data = json.load(f)
            for key, value in config_data.get("parameters", {}).items():
                if key not in strategy_params:
                    strategy_params[key] = value.get("default")

    return strategy_class(params=strategy_params)


def list_strategies() -> list[dict]:
    results = []

    for path in settings.STRATEGIES_DIR.glob("*.py"):
        if path.name in ["__init__.py", "base.py", "loader.py"]:
            continue

        name = path.stem
        config_path = settings.CONFIGS_DIR / f"{name}.json"

        results.append({"name": name, "has_config": config_path.exists()})

    return results


def load_config(name: str) -> StrategyConfig:
    config_path = settings.CONFIGS_DIR / f"{name}.json"

    if not config_path.exists():
        raise FileNotFoundError(f"Config not found: {config_path}")

    with open(config_path) as f:
        data = json.load(f)

    return StrategyConfig(**data)
