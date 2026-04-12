"""
CWT Strategy — Crocodile Wave Trend, Multi-Timeframe Edition

Two entry types:

  Type 1 — Pullback
    HTF bullish + LTF bullish
    Bar 1 HA: touched alligator zone, closed back above lips
    Bar 2 HA: fully above lips, expanding body

  Type 2 — Awakening
    HTF bullish + alignment_streak 2–8 + displaced lips > teeth
    Both HA bars green, bar1 closed above lips, bar2 fully above lips

Stop Loss:  jaw × (1 − sl_buffer)
Take Profit: TP1 = 1:1 RR (50% close), TP2 = 1:2 RR (remainder)
"""

import pandas as pd
from typing import Optional

from indicators.library.alligator import Alligator, AlligatorValues
from models import Candle, Signal, SignalAction, ParamDef, Position, IndicatorRequest
from strategies.base import BaseStrategy


class CWTStrategy(BaseStrategy):
    """
    CWT (Crocodile Wave Trend) — Alligator pullback and awakening strategy.

    SL/TP prices are declared on the entry Signal and managed by the engine:
      - SL is anchored to the Jaw line at entry
      - TP1 is 1:1 RR (engine closes 50%, moves SL to breakeven)
      - TP2 is 1:2 RR (engine closes remainder)

    Config must have stop_loss_pct = null and take_profit_pct = null.
    """

    def name(self) -> str:
        return "CWT"

    def default_params(self) -> dict[str, ParamDef]:
        return {
            "jaw_period": ParamDef(
                default=13, min=5, max=30, step=1,
                description="Jaw SMMA period (classic: 13)",
            ),
            "jaw_shift": ParamDef(
                default=8, min=1, max=15, step=1,
                description="Jaw forward-shift in bars (classic: 8)",
            ),
            "teeth_period": ParamDef(
                default=8, min=3, max=20, step=1,
                description="Teeth SMMA period (classic: 8)",
            ),
            "teeth_shift": ParamDef(
                default=5, min=1, max=10, step=1,
                description="Teeth forward-shift in bars (classic: 5)",
            ),
            "lips_period": ParamDef(
                default=5, min=2, max=15, step=1,
                description="Lips SMMA period (classic: 5)",
            ),
            "lips_shift": ParamDef(
                default=3, min=1, max=8, step=1,
                description="Lips forward-shift in bars (classic: 3)",
            ),
            "htf_multiplier": ParamDef(
                default=4, min=1, max=16, step=1,
                description="HTF = LTF × this. 4 on 1H = 4H, 4 on 15m = 1H. Set 1 = single-TF mode.",
            ),
            "sl_buffer_pct": ParamDef(
                default=0.001, min=0.0, max=0.01, step=0.001,
                description="Extra buffer beyond Jaw for SL (0.001 = 0.1%)",
            ),
            "min_htf_spread_pct": ParamDef(
                default=0.0, min=0.0, max=1.0, step=0.05,
                description="Minimum HTF alligator spread (lips-jaw)/jaw * 100. 0 = disabled.",
            ),
            "min_prev_body_ratio": ParamDef(
                default=0.0, min=0.0, max=1.0, step=0.05,
                description="Minimum Bar 1 body ratio |close-open|/(high-low). 0 = disabled.",
            ),
            "min_bounce_pct": ParamDef(
                default=0.0, min=0.0, max=1.0, step=0.05,
                description="Minimum % Bar 1 close must be above Teeth. 0 = disabled.",
            ),
            "min_ltf_spread_pct": ParamDef(
                default=0.0, min=0.0, max=1.0, step=0.05,
                description="Minimum LTF alligator spread (lips-jaw)/jaw * 100. 0 = disabled.",
            ),
            "require_bar2_above_prev_close": ParamDef(
                default=0, min=0, max=1, step=1,
                description="1 = Bar 2 must close above Bar 1 close. 0 = disabled.",
            ),
        }

    def required_indicators(self) -> list[IndicatorRequest]:
        alligator_params = {
            "jaw_period":   int(self.params["jaw_period"]),
            "jaw_shift":    int(self.params["jaw_shift"]),
            "teeth_period": int(self.params["teeth_period"]),
            "teeth_shift":  int(self.params["teeth_shift"]),
            "lips_period":  int(self.params["lips_period"]),
            "lips_shift":   int(self.params["lips_shift"]),
        }

        requests = [
            IndicatorRequest(name="alligator", params=alligator_params),
            IndicatorRequest(name="heikin_ashi", params={}),
        ]

        if int(self.params["htf_multiplier"]) >= 2:
            requests.append(
                IndicatorRequest(
                    name="alligator_htf",
                    params={
                        "htf_multiplier": int(self.params["htf_multiplier"]),
                        **alligator_params,
                    },
                )
            )

        return requests

    def signal(self, row: pd.Series, position: Optional[Position] = None) -> Signal:
        jaw   = row.get("jaw")
        teeth = row.get("teeth")
        lips  = row.get("lips")

        if jaw is None or teeth is None or lips is None:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="Alligator NaN")

        close = float(row["close"])

        if position is not None:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="Holding position")

        # ── Build alligator value objects ─────────────────────────────────────
        ltf  = AlligatorValues(float(row["jaw_off"]),  float(row["teeth_off"]),  float(row["lips_off"]))
        disp = AlligatorValues(float(jaw),             float(teeth),             float(lips))
        htf  = AlligatorValues(
            float(row.get("htf_jaw_off",   0) or 0),
            float(row.get("htf_teeth_off", 0) or 0),
            float(row.get("htf_lips_off",  0) or 0),
        )
        # Single-TF mode (htf_multiplier=1): no HTF columns — treat as bullish
        htf_bullish = Alligator.is_bullish(htf) if htf.jaw else True

        # ── Build HA candle objects ───────────────────────────────────────────
        bar1 = Candle(
            open  = float(row.get("prev_ha_open",  0) or 0),
            high  = float(row.get("prev_ha_high",  0) or 0),
            low   = float(row.get("prev_ha_low",   float("inf"))),
            close = float(row.get("prev_ha_close", 0) or 0),
        )
        bar2 = Candle(
            open  = float(row.get("ha_open",  0) or 0),
            high  = float(row.get("ha_high",  0) or 0),
            low   = float(row.get("ha_low",   0) or 0),
            close = float(row.get("ha_close", 0) or 0),
        )

        alignment_streak = int(row.get("alignment_streak", 0) or 0)
        prev_close       = float(row.get("prev_close", 0.0))

        # ── Entry Type 1: Pullback ────────────────────────────────────────────
        # Bar1: dipped into zone, closed back above lips
        # Bar2: fully above lips with expanding body
        pullback = (
            htf_bullish
            and Alligator.is_bullish(ltf)
            and Alligator.is_candle_touching(bar1, disp)
            and Alligator.is_candle_above(bar2, disp)
            and bar2.body > bar1.body
        )

        # ── Entry Type 2: Awakening ───────────────────────────────────────────
        # Alligator woke up (streak 2–8), lips crossed teeth,
        # both HA bars green and above lips, bar2 fully clear
        awakening = (
            htf_bullish
            and 2 <= alignment_streak <= 8
            and Alligator.lips_crossed_teeth(disp)
            and (Alligator.is_candle_touching(bar1, disp) or Alligator.is_candle_above(bar1, disp))
            and Alligator.is_candle_above(bar2, disp)
            and bar2.close > bar2.open
            and bar1.close > bar1.open
        )

        if pullback or awakening:
            sl_price = float(jaw) * (1.0 - float(self.params["sl_buffer_pct"]))
            sl_dist  = close - sl_price
            if sl_dist <= 0:
                return Signal(action=SignalAction.HOLD, strength=0.0, reason="SL distance ≤ 0")

            min_htf_spread = float(self.params["min_htf_spread_pct"])
            if min_htf_spread > 0:
                htf_spread_pct = float(row.get("htf_spread_pct", 0.0) or 0.0)
                if htf_spread_pct < min_htf_spread:
                    return Signal(action=SignalAction.HOLD, strength=0.0,
                                  reason=f"HTF spread {htf_spread_pct:.3f}% < min {min_htf_spread:.2f}%")

            min_prev_body_ratio = float(self.params["min_prev_body_ratio"])
            if min_prev_body_ratio > 0:
                prev_body_ratio = float(row.get("prev_body_ratio", 1.0) or 1.0)
                if prev_body_ratio < min_prev_body_ratio:
                    return Signal(action=SignalAction.HOLD, strength=0.0,
                                  reason=f"Bar1 body ratio {prev_body_ratio:.3f} < {min_prev_body_ratio:.2f}")

            min_bounce_pct = float(self.params["min_bounce_pct"])
            if min_bounce_pct > 0:
                prev_close_vs_teeth = float(row.get("prev_close_vs_teeth_pct", 0.0) or 0.0)
                if prev_close_vs_teeth < min_bounce_pct:
                    return Signal(action=SignalAction.HOLD, strength=0.0,
                                  reason=f"Bounce {prev_close_vs_teeth:.3f}% < min {min_bounce_pct:.2f}%")

            min_ltf_spread = float(self.params["min_ltf_spread_pct"])
            if min_ltf_spread > 0:
                ltf_spread_pct = float(row.get("ltf_spread_pct", 0.0) or 0.0)
                if ltf_spread_pct < min_ltf_spread:
                    return Signal(action=SignalAction.HOLD, strength=0.0,
                                  reason=f"LTF spread {ltf_spread_pct:.3f}% < min {min_ltf_spread:.2f}%")

            if int(self.params["require_bar2_above_prev_close"]) and close <= prev_close:
                return Signal(action=SignalAction.HOLD, strength=0.0,
                              reason=f"Bar2 close {close:.2f} ≤ Bar1 close {prev_close:.2f}")

            entry_type = "Awakening" if awakening else "Pullback"
            tp1_price  = close + sl_dist
            tp2_price  = close + sl_dist * 2
            return Signal(
                action=SignalAction.BUY,
                strength=1.0,
                sl_price=sl_price,
                tp1_price=tp1_price,
                tp2_price=tp2_price,
                reason=f"CWT {entry_type} | entry={close:.4f}  SL={sl_price:.4f}  TP1={tp1_price:.4f}  TP2={tp2_price:.4f}",
            )

        return Signal(action=SignalAction.HOLD, strength=0.0, reason="No setup")
