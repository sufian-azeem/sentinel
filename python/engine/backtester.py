import pandas as pd
from datetime import datetime
from typing import Optional

from config import settings
from models import BacktestResult, RiskRules, SignalAction, Side, ExitReason, OrderType, Position
from strategies.base import BaseStrategy
from engine.clock import MarketClock
from engine.portfolio import Portfolio
from engine.metrics import calculate_all


class Backtester:
    def __init__(
        self,
        starting_capital: Optional[float] = None,
        fee_rate: Optional[float] = None,
        slippage_pct: Optional[float] = None,
    ):
        self.starting_capital = starting_capital or settings.STARTING_CAPITAL
        self.fee_rate = fee_rate or settings.DEFAULT_FEE_RATE
        self.slippage_pct = slippage_pct or settings.DEFAULT_SLIPPAGE_PCT

    def run(
        self,
        strategy: BaseStrategy,
        df: pd.DataFrame,
        pair: str,
        timeframe: str,
        risk_rules: Optional[RiskRules] = None,
    ) -> BacktestResult:
        df = df.copy()

        original_cols = set(df.columns)
        df = strategy.indicators(df)
        indicator_cols = list(set(df.columns) - original_cols)

        df = df.dropna()
        df = df.reset_index(drop=True)

        if risk_rules is None:
            risk_rules = RiskRules()

        portfolio = Portfolio(
            starting_capital=self.starting_capital,
            fee_rate=self.fee_rate,
            slippage_pct=self.slippage_pct,
            risk_rules=risk_rules,
        )

        clock = MarketClock(df)

        while clock.has_next():
            row = clock.next()
            row_dict = row.to_dict()
            candle = pd.Series(row_dict)

            for position in list(portfolio.positions):
                exit_reason = portfolio.check_stops(candle, position)
                if exit_reason:
                    if exit_reason == ExitReason.TAKE_PROFIT_1:
                        exit_price = position.tp1_price
                        portfolio.close_position(
                            position, exit_price, int(row["timestamp"]),
                            exit_reason, quantity=position.quantity * 0.5,
                        )
                        # Engine moves SL to breakeven; position still open
                        position.sl_price = position.entry_price
                        position.tp1_hit  = True
                        continue

                    elif exit_reason == ExitReason.TAKE_PROFIT_2:
                        exit_price = position.tp2_price
                    elif exit_reason == ExitReason.STOP_LOSS:
                        if position.sl_price:
                            exit_price = position.sl_price
                        elif position.side == Side.BUY:
                            exit_price = position.entry_price * (
                                1 - risk_rules.stop_loss_pct
                            )
                        else:
                            exit_price = position.entry_price * (
                                1 + risk_rules.stop_loss_pct
                            )
                    elif exit_reason == ExitReason.TAKE_PROFIT:
                        if position.side == Side.BUY:
                            exit_price = position.entry_price * (
                                1 + risk_rules.take_profit_pct
                            )
                        else:
                            exit_price = position.entry_price * (
                                1 - risk_rules.take_profit_pct
                            )
                    elif exit_reason == ExitReason.TRAILING_STOP:
                        if position.side == Side.BUY:
                            exit_price = position.highest_price_since_entry * (
                                1 - risk_rules.trailing_stop_pct
                            )
                        else:
                            exit_price = position.lowest_price_since_entry * (
                                1 + risk_rules.trailing_stop_pct
                            )
                    elif exit_reason == ExitReason.LIQUIDATION:
                        exit_price = position.liquidation_price
                    else:
                        exit_price = row["close"]

                    portfolio.close_position(
                        position, exit_price, int(row["timestamp"]), exit_reason
                    )

            filled_pending = portfolio.order_manager.process_pending_orders(candle)
            for order in filled_pending:
                portfolio.open_position(order)

            current_position = portfolio.get_position_for_pair(pair)
            signal = strategy.signal(row, current_position)

            if signal.action == SignalAction.BUY and current_position is None:
                quantity = portfolio.calculate_position_size(signal, row["close"])
                if quantity > 0:
                    if signal.order_type == OrderType.MARKET:
                        order = portfolio.order_manager.create_order(
                            side=Side.BUY,
                            pair=pair,
                            quantity=quantity,
                            price=row["close"],
                            timestamp=int(row["timestamp"]),
                            leverage=risk_rules.max_leverage,
                            sl_price=signal.sl_price,
                            tp1_price=signal.tp1_price,
                            tp2_price=signal.tp2_price,
                        )
                        portfolio.open_position(order)
                    elif signal.order_type == OrderType.LIMIT and signal.limit_price:
                        portfolio.order_manager.create_order(
                            side=Side.BUY,
                            pair=pair,
                            quantity=quantity,
                            price=signal.limit_price,
                            timestamp=int(row["timestamp"]),
                            order_type=OrderType.LIMIT,
                            leverage=risk_rules.max_leverage,
                            sl_price=signal.sl_price,
                            tp1_price=signal.tp1_price,
                            tp2_price=signal.tp2_price,
                        )

            elif signal.action == SignalAction.CLOSE and current_position is not None:
                qty        = current_position.quantity * max(0.0, min(1.0, signal.close_pct))
                exit_price = signal.limit_price if signal.limit_price else row["close"]
                portfolio.close_position(
                    current_position,
                    exit_price,
                    int(row["timestamp"]),
                    ExitReason.SIGNAL,
                    quantity=qty,
                )

            elif signal.action == SignalAction.SELL:
                if current_position is not None and current_position.side == Side.BUY:
                    portfolio.close_position(
                        current_position,
                        row["close"],
                        int(row["timestamp"]),
                        ExitReason.SIGNAL,
                    )
                elif current_position is None and risk_rules.max_leverage > 1.0:
                    quantity = portfolio.calculate_position_size(signal, row["close"])
                    if quantity > 0:
                        order = portfolio.order_manager.create_order(
                            side=Side.SELL,
                            pair=pair,
                            quantity=quantity,
                            price=row["close"],
                            timestamp=int(row["timestamp"]),
                            leverage=risk_rules.max_leverage,
                            sl_price=signal.sl_price,
                            tp1_price=signal.tp1_price,
                            tp2_price=signal.tp2_price,
                        )
                        portfolio.open_position(order)

            portfolio.update_equity(row["close"], int(row["timestamp"]))

        if portfolio.positions:
            last_row = df.iloc[-1]
            for position in list(portfolio.positions):
                portfolio.close_position(
                    position,
                    last_row["close"],
                    int(last_row["timestamp"]),
                    ExitReason.END_OF_DATA,
                )

        metrics = calculate_all(
            trades=portfolio.trades,
            equity_curve=portfolio.equity_curve,
            starting_capital=self.starting_capital,
            timeframe=timeframe,
        )

        start_date = datetime.utcfromtimestamp(df["timestamp"].iloc[0] / 1000).strftime(
            "%Y-%m-%d"
        )
        end_date = datetime.utcfromtimestamp(df["timestamp"].iloc[-1] / 1000).strftime(
            "%Y-%m-%d"
        )

        return BacktestResult(
            strategy_id=strategy.name().lower().replace(" ", "_"),
            strategy_name=strategy.name(),
            params_used=strategy.params,
            pair=pair,
            timeframe=timeframe,
            start_date=start_date,
            end_date=end_date,
            starting_capital=self.starting_capital,
            final_equity=portfolio.equity_curve[-1]
            if portfolio.equity_curve
            else self.starting_capital,
            total_trades=len(portfolio.trades),
            trades=portfolio.trades,
            equity_curve=portfolio.equity_curve,
            equity_timestamps=portfolio.equity_timestamps,
            metrics=metrics,
            metadata={
                "fee_rate": self.fee_rate,
                "slippage_pct": self.slippage_pct,
                "candles_processed": len(df),
                "indicators_added": indicator_cols,
            },
        )
