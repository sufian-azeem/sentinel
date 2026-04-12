from abc import ABC, abstractmethod
from typing import Optional

import pandas as pd

from models import Signal, ParamDef, Position, IndicatorRequest
from indicators.service import IndicatorService


class BaseStrategy(ABC):
    """
    Abstract base class for all strategies.

    Strategies must implement:
        name()                 → human-readable strategy name
        default_params()       → parameter definitions with bounds
        required_indicators()  → list of IndicatorRequest objects
        signal()               → trading signal for the current candle

    Strategies must NOT implement indicators() — it is handled centrally by
    IndicatorService, which routes each IndicatorRequest to the registered
    compute function in indicators/registry.py.
    """

    def __init__(self, params: dict | None = None) -> None:
        defaults = self.default_params()
        self.params: dict = {}
        for key, param_def in defaults.items():
            if params and key in params:
                value = float(params[key])
                value = max(param_def.min, min(param_def.max, value))
                self.params[key] = value
            else:
                self.params[key] = param_def.default

    # ── Abstract interface ────────────────────────────────────────────────────

    @abstractmethod
    def name(self) -> str:
        """Return the human-readable strategy name."""

    @abstractmethod
    def default_params(self) -> dict[str, ParamDef]:
        """Return parameter definitions with min/max/step bounds."""

    @abstractmethod
    def required_indicators(self) -> list[IndicatorRequest]:
        """
        Declare which indicators this strategy needs before the backtest loop.

        The central IndicatorService will compute each request in order and
        add the resulting columns to the candle DataFrame.

        Example:
            return [
                IndicatorRequest(name="rsi", params={"rsi_period": 14}),
            ]
        """

    @abstractmethod
    def signal(self, row: pd.Series, position: Optional[Position] = None) -> Signal:
        """
        Return a trading signal for the current candle row.

        Rules:
        - row contains OHLCV columns + all indicator columns declared above.
        - position is None if no open position exists, otherwise a Position object.
        - Must ALWAYS return a Signal — never None, never raise.
        - Must NOT access future data (only the current row).
        """

    # ── Concrete — do not override ────────────────────────────────────────────

    def indicators(self, df: pd.DataFrame) -> pd.DataFrame:
        """
        Compute all required indicators via the central IndicatorService.

        Called once by the Backtester before the main loop.
        Strategies must NOT override this method.
        """
        return IndicatorService.compute(df, self.required_indicators())

    def validate_params(self, params: dict) -> dict:
        """Validate and clamp params against bounds, filling missing with defaults."""
        defaults = self.default_params()
        validated: dict = {}
        for key, param_def in defaults.items():
            if key in params:
                value = float(params[key])
                value = max(param_def.min, min(param_def.max, value))
                validated[key] = value
            else:
                validated[key] = param_def.default
        return validated
