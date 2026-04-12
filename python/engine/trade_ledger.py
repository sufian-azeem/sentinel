from models import Trade


class TradeLedger:
    def __init__(self):
        self.trades: list[Trade] = []

    def record_trade(self, trade: Trade) -> None:
        self.trades.append(trade)

    def all_trades(self) -> list[Trade]:
        return self.trades

    def trades_for_pair(self, pair: str) -> list[Trade]:
        return [t for t in self.trades if t.pair == pair]
