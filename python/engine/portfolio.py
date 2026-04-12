from typing import Optional
import pandas as pd

from models import (
    Order,
    Position,
    Trade,
    Side,
    RiskRules,
    Signal,
    ExitReason,
    OrderType,
)
from engine.order import OrderManager


class Portfolio:
    def __init__(
        self,
        starting_capital: float,
        fee_rate: float,
        slippage_pct: float,
        risk_rules: RiskRules,
    ):
        self.starting_capital = starting_capital
        self.balance = starting_capital
        self.fee_rate = fee_rate
        self.slippage_pct = slippage_pct
        self.risk_rules = risk_rules
        self.positions: list[Position] = []
        self.trades: list[Trade] = []
        self.equity_curve: list[float] = []
        self.equity_timestamps: list[int] = []
        self.order_manager = OrderManager(fee_rate, slippage_pct)

    def open_position(self, order: Order) -> Position:
        cost = order.quantity * order.filled_price

        if order.leverage == 1.0:
            self.balance -= cost + order.fee
        else:
            margin = cost / order.leverage
            self.balance -= margin + order.fee

        position = Position(
            pair=order.pair,
            side=order.side,
            entry_price=order.filled_price,
            quantity=order.quantity,
            entry_time=order.timestamp,
            leverage=order.leverage,
            highest_price_since_entry=order.filled_price,
            lowest_price_since_entry=order.filled_price,
        )

        if order.leverage > 1.0:
            if order.side == Side.BUY:
                position.liquidation_price = order.filled_price * (
                    1 - 1 / order.leverage + self.fee_rate
                )
            else:
                position.liquidation_price = order.filled_price * (
                    1 + 1 / order.leverage - self.fee_rate
                )

        position.sl_price  = order.sl_price
        position.tp1_price = order.tp1_price
        position.tp2_price = order.tp2_price

        self.positions.append(position)
        return position

    def close_position(
        self,
        position: Position,
        exit_price: float,
        exit_time: int,
        reason: ExitReason,
        quantity: Optional[float] = None,
    ) -> Trade:
        # quantity=None means close the full position
        qty = quantity if quantity is not None else position.quantity
        qty = min(qty, position.quantity)   # never exceed what's open

        entry_fee = position.entry_price * qty * position.leverage * self.fee_rate
        exit_fee  = qty * exit_price * self.fee_rate

        if position.side == Side.BUY:
            pnl = (exit_price - position.entry_price) * qty * position.leverage
        else:
            pnl = (position.entry_price - exit_price) * qty * position.leverage
        pnl -= entry_fee + exit_fee

        pnl_pct = (pnl / (position.entry_price * qty)) * 100

        if position.leverage == 1.0:
            self.balance += (qty * exit_price) - exit_fee
        else:
            margin = (position.entry_price * qty) / position.leverage
            self.balance += margin + pnl

        trade = Trade(
            pair=position.pair,
            side=position.side,
            entry_price=position.entry_price,
            exit_price=exit_price,
            quantity=qty,
            entry_time=position.entry_time,
            exit_time=exit_time,
            pnl=pnl,
            pnl_pct=pnl_pct,
            fee_total=entry_fee + exit_fee,
            leverage=position.leverage,
            exit_reason=reason,
            sl_price=position.sl_price,
        )
        self.trades.append(trade)

        remaining = position.quantity - qty
        if remaining <= 1e-10:
            # full close — remove position
            self.positions = [
                p for p in self.positions if p.position_id != position.position_id
            ]
        else:
            # partial close — reduce position quantity
            position.quantity = remaining

        return trade

    def check_stops(self, candle: pd.Series, position: Position) -> ExitReason | None:
        if position.leverage > 1.0:
            if position.side == Side.BUY:
                if candle.get("low", 0) <= position.liquidation_price:
                    return ExitReason.LIQUIDATION
            else:
                if candle.get("high", float("inf")) >= position.liquidation_price:
                    return ExitReason.LIQUIDATION

        # Strategy-defined exits (position-level prices, highest priority after liquidation)
        if position.sl_price:
            if position.side == Side.BUY and candle.get("low", 0) <= position.sl_price:
                return ExitReason.STOP_LOSS
            if position.side == Side.SELL and candle.get("high", float("inf")) >= position.sl_price:
                return ExitReason.STOP_LOSS

        if position.tp2_price:
            if position.side == Side.BUY and candle.get("high", 0) >= position.tp2_price:
                return ExitReason.TAKE_PROFIT_2
            if position.side == Side.SELL and candle.get("low", float("inf")) <= position.tp2_price:
                return ExitReason.TAKE_PROFIT_2

        if position.tp1_price and not position.tp1_hit:
            if position.side == Side.BUY and candle.get("high", 0) >= position.tp1_price:
                return ExitReason.TAKE_PROFIT_1
            if position.side == Side.SELL and candle.get("low", float("inf")) <= position.tp1_price:
                return ExitReason.TAKE_PROFIT_1

        if self.risk_rules.stop_loss_pct:
            if position.side == Side.BUY:
                stop_price = position.entry_price * (1 - self.risk_rules.stop_loss_pct)
                if candle.get("low", 0) <= stop_price:
                    return ExitReason.STOP_LOSS
            else:
                stop_price = position.entry_price * (1 + self.risk_rules.stop_loss_pct)
                if candle.get("high", float("inf")) >= stop_price:
                    return ExitReason.STOP_LOSS

        if self.risk_rules.trailing_stop_pct:
            current_price = candle.get("close", position.entry_price)
            if position.side == Side.BUY:
                position.highest_price_since_entry = max(
                    position.highest_price_since_entry, current_price
                )
                trailing_stop_price = position.highest_price_since_entry * (
                    1 - self.risk_rules.trailing_stop_pct
                )
                if candle.get("low", 0) <= trailing_stop_price:
                    return ExitReason.TRAILING_STOP
            else:
                position.lowest_price_since_entry = min(
                    position.lowest_price_since_entry, current_price
                )
                trailing_stop_price = position.lowest_price_since_entry * (
                    1 + self.risk_rules.trailing_stop_pct
                )
                if candle.get("high", float("inf")) >= trailing_stop_price:
                    return ExitReason.TRAILING_STOP

        if self.risk_rules.take_profit_pct:
            if position.side == Side.BUY:
                tp_price = position.entry_price * (1 + self.risk_rules.take_profit_pct)
                if candle.get("high", 0) >= tp_price:
                    return ExitReason.TAKE_PROFIT
            else:
                tp_price = position.entry_price * (1 - self.risk_rules.take_profit_pct)
                if candle.get("high", float("inf")) <= tp_price:
                    return ExitReason.TAKE_PROFIT

        return None

    def calculate_position_size(self, signal: Signal, current_price: float) -> float:
        max_spend = self.balance * self.risk_rules.max_position_pct
        spend = max_spend * signal.strength

        if spend < 1.0:
            return 0.0

        quantity = spend / current_price

        if self.risk_rules.max_leverage > 1.0:
            quantity = quantity * self.risk_rules.max_leverage

        return quantity

    def update_equity(self, current_price: float, timestamp: int) -> float:
        equity = self.balance

        for pos in self.positions:
            if pos.side == Side.BUY:
                unrealized = (
                    (current_price - pos.entry_price) * pos.quantity * pos.leverage
                )
            else:
                unrealized = (
                    (pos.entry_price - current_price) * pos.quantity * pos.leverage
                )

            if pos.leverage == 1.0:
                equity += pos.quantity * current_price
            else:
                margin = (pos.entry_price * pos.quantity) / pos.leverage
                equity += margin + unrealized

        self.equity_curve.append(equity)
        self.equity_timestamps.append(timestamp)

        return equity

    def get_position_for_pair(self, pair: str) -> Position | None:
        for pos in self.positions:
            if pos.pair == pair:
                return pos
        return None

    def has_open_position(self, pair: str) -> bool:
        return self.get_position_for_pair(pair) is not None
