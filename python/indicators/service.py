"""
Central indicator service.

Strategies call IndicatorService.compute() from their indicators() method,
passing a list of IndicatorRequest objects.  The service delegates each request
to the IndicatorRegistry, which routes it to the registered compute function.
"""

import pandas as pd

from models import IndicatorRequest
from indicators.registry import IndicatorRegistry


class IndicatorService:
    """Orchestrates indicator computation for a strategy."""

    @staticmethod
    def compute(df: pd.DataFrame, requests: list[IndicatorRequest]) -> pd.DataFrame:
        """
        Apply each requested indicator to df in declaration order.

        Each compute function adds its columns to df and returns the updated
        DataFrame.  Columns added by earlier indicators are visible to later ones.
        """
        for req in requests:
            df = IndicatorRegistry.compute(df, req.name, req.params)
        return df
