import numpy as np
import pandas as pd

from models import Order, OrderType, OrderStatus, Side


class OrderManager:
    def __init__(self, fee_rate: float, slippage_pct: float):
        self.fee_rate = fee_rate
        self.slippage_pct = slippage_pct
        self.pending_orders: list[Order] = []

    def create_order(
        self,
        side: Side,
        pair: str,
        quantity: float,
        price: float,
        timestamp: int,
        order_type: OrderType = OrderType.MARKET,
        leverage: float = 1.0,
        limit_price: float | None = None,
        sl_price: float | None = None,
        tp1_price: float | None = None,
        tp2_price: float | None = None,
    ) -> Order:
        order = Order(
            timestamp=timestamp,
            side=side,
            order_type=order_type,
            pair=pair,
            quantity=quantity,
            price=price,
            leverage=leverage,
            sl_price=sl_price,
            tp1_price=tp1_price,
            tp2_price=tp2_price,
        )

        if order_type == OrderType.MARKET:
            order = self.fill_order(order, price, timestamp)

        return order

    def fill_order(self, order: Order, fill_price: float, timestamp: int) -> Order:
        filled_price = self.apply_slippage(fill_price, order.side)
        fee = self.calculate_fee(order.quantity, filled_price)

        order.filled_price = filled_price
        order.fee = fee
        order.slippage = abs(filled_price - order.price)
        order.status = OrderStatus.FILLED

        return order

    def process_pending_orders(self, candle: pd.Series) -> list[Order]:
        filled = []
        still_pending = []

        for order in self.pending_orders:
            triggered = False
            trigger_price = order.limit_price or order.price

            if order.side == Side.BUY:
                if candle["low"] <= trigger_price:
                    triggered = True
                    fill_price = trigger_price
            else:
                if candle["high"] >= trigger_price:
                    triggered = True
                    fill_price = trigger_price

            if triggered:
                filled_order = self.fill_order(
                    order, fill_price, int(candle["timestamp"])
                )
                filled.append(filled_order)
            else:
                still_pending.append(order)

        self.pending_orders = still_pending
        return filled

    def apply_slippage(self, price: float, side: Side) -> float:
        slippage = np.random.uniform(0, self.slippage_pct)
        if side == Side.BUY:
            return price * (1 + slippage)
        else:
            return price * (1 - slippage)

    def calculate_fee(self, quantity: float, price: float) -> float:
        return quantity * price * self.fee_rate

    def cancel_all_pending(self) -> int:
        count = len(self.pending_orders)
        self.pending_orders = []
        return count
