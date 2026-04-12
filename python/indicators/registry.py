"""
Central indicator registry.

Indicator functions register themselves here at import time.
Each function receives a DataFrame and keyword params, adds columns, and returns the DataFrame.

Function signature contract:
    def compute_xyz(df: pd.DataFrame, **params) -> pd.DataFrame
"""

import logging
from typing import Callable
import pandas as pd

logger = logging.getLogger(__name__)


class IndicatorRegistry:
    """Holds all registered indicator compute functions keyed by name."""

    _registry: dict[str, Callable] = {}

    @classmethod
    def register(cls, name: str, func: Callable) -> None:
        """Register an indicator function under the given name."""
        if name in cls._registry:
            logger.warning("Indicator '%s' is already registered — overwriting.", name)
        cls._registry[name] = func
        logger.debug("Registered indicator: '%s'", name)

    @classmethod
    def compute(cls, df: pd.DataFrame, name: str, params: dict) -> pd.DataFrame:
        """Run the named indicator function on df with the provided params."""
        if name not in cls._registry:
            registered = list(cls._registry.keys())
            raise ValueError(
                f"Indicator '{name}' is not registered. "
                f"Available indicators: {registered}"
            )
        return cls._registry[name](df, **params)

    @classmethod
    def available(cls) -> list[str]:
        """Return sorted list of all registered indicator names."""
        return sorted(cls._registry.keys())
