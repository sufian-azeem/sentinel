import pandas as pd


class MarketClock:
    def __init__(self, candles: pd.DataFrame):
        self.candles = candles
        self.index = 0

    def has_next(self) -> bool:
        return self.index < len(self.candles)

    def next(self) -> pd.Series:
        candle = self.candles.iloc[self.index]
        self.index += 1
        return candle

    def current_index(self) -> int:
        return self.index
