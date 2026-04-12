"""
Alligator V4 — BTC/USDT Cycle-Aware Strategy

What's improved over V1/V2/V3:
─────────────────────────────────────────────────────────────────────────────
1. Three-timeframe confluence (1h + 4h + Daily)
   V1/V2 used two timeframes (1h + 4h). V4 adds a macro (daily) alligator
   as a cycle gate. When the daily alligator is bearish, no longs are taken
   regardless of what the 1h or 4h show. This directly targets BTC's 4-year
   bull/bear cycle — during 2022-type bear markets the strategy stays flat.

2. ADX filter (trending market confirmation)
   ADX(14) ≥ threshold confirms the 1h is in a trending phase, not ranging.
   Ranging markets produce false pullback signals on the alligator — ADX
   filters most of them out. Borrowed from V3 where it proved effective.

3. Macro spread kill switch
   Even when macro_htf_bull=True, if the daily alligator mouth is nearly
   closed (spread < macro_spread_min), the trend is weak — skip the trade.

4. Keeps V1's proven 2-bar pullback entry
   Bar 1: low touches Lips zone, closes above Teeth (bounce confirmed), green.
   Bar 2: green candle, low stays at or above Lips (zone holds).
   This pattern is the highest-probability entry in the alligator framework.
   V3's scoring system added complexity without improving results — removed.

5. LTF spread filter
   Requires the 1h alligator mouth to be open (min_ltf_spread_pct). Prevents
   entries when the 1h alligator is tangled — the key lesson from 5m testing.

Target pair: BTC/USDT
Target TF:   1h
─────────────────────────────────────────────────────────────────────────────
"""

import pandas as pd
from typing import Optional

from models import Signal, SignalAction, ParamDef, Position, IndicatorRequest
from strategies.base import BaseStrategy


class AlligatorV4Strategy(BaseStrategy):
    """
    BTC-optimised Alligator with 3-timeframe confluence and cycle awareness.
    Uses daily alligator as a macro bull/bear regime filter.
    """

    def name(self) -> str:
        return "Alligator V4 (Cycle-Aware)"

    def default_params(self) -> dict[str, ParamDef]:
        return {
            # ── Alligator periods (classic Bill Williams) ──────────────────
            "jaw_period":   ParamDef(default=13, min=5,  max=30, step=1, description="Jaw SMMA period"),
            "jaw_shift":    ParamDef(default=8,  min=1,  max=15, step=1, description="Jaw forward-shift"),
            "teeth_period": ParamDef(default=8,  min=3,  max=20, step=1, description="Teeth SMMA period"),
            "teeth_shift":  ParamDef(default=5,  min=1,  max=10, step=1, description="Teeth forward-shift"),
            "lips_period":  ParamDef(default=5,  min=2,  max=15, step=1, description="Lips SMMA period"),
            "lips_shift":   ParamDef(default=3,  min=1,  max=8,  step=1, description="Lips forward-shift"),

            # ── Timeframe multipliers ──────────────────────────────────────
            "htf_multiplier":       ParamDef(default=4,  min=1, max=30, step=1,
                                             description="Intermediate HTF: 4 on 1h = 4h; 7 on 1d = weekly"),
            "macro_htf_multiplier": ParamDef(default=24, min=2, max=60, step=1,
                                             description="Macro HTF: 24 on 1h = daily; 7 on 1d = weekly"),

            # ── ADX filter ─────────────────────────────────────────────────
            "adx_threshold": ParamDef(default=20.0, min=0.0, max=50.0, step=1.0,
                                      description="Minimum ADX(14) — below = ranging (0=disabled)"),
            "adx_max":       ParamDef(default=45.0, min=0.0, max=100.0, step=1.0,
                                      description="Maximum ADX(14) — above = parabolic (0=disabled)"),

            # ── Trend freshness filter ──────────────────────────────────────
            "streak_max":    ParamDef(default=40, min=0, max=80, step=10,
                                      description="Max LTF bull alignment streak — above = exhausted trend (0=disabled)"),

            # ── Spread filters ─────────────────────────────────────────────
            "min_ltf_spread_pct":   ParamDef(default=0.2,  min=0.0, max=1.0, step=0.1,
                                             description="Min 1h alligator spread — mouth must be open"),
            "macro_spread_min":     ParamDef(default=0.5,  min=0.0, max=2.0, step=0.25,
                                             description="Min daily alligator spread — weak daily trend = skip"),

            # ── Entry quality filters ──────────────────────────────────────
            "min_prev_body_ratio":  ParamDef(default=0.3, min=0.0, max=0.8, step=0.1,
                                             description="Min body ratio of Bar 1 (bounce candle)"),
            "min_bounce_pct":       ParamDef(default=0.5, min=0.0, max=1.0, step=0.25,
                                             description="Min % Bar 1 close must be above Teeth"),
            "require_body_in_zone": ParamDef(default=0, min=0, max=1, step=1,
                                             description="1=Bar1 open must be ≤ Teeth (body in zone, no wick-only)"),
            "disable_tp":           ParamDef(default=0, min=0, max=1, step=1,
                                             description="1=No TP1/TP2 targets; trailing stop manages exit"),

            # ── Risk management ────────────────────────────────────────────
            "sl_buffer_pct":  ParamDef(default=0.001, min=0.0, max=0.005, step=0.001,
                                       description="Extra buffer below Jaw for SL"),
            "max_sl_pct":     ParamDef(default=0.03,  min=0.0, max=0.40, step=0.005,
                                       description="Max SL distance as fraction of entry (0=disabled, use 0.15+ for daily)"),
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
        return [
            IndicatorRequest(name="alligator", params=alligator_params),
            IndicatorRequest(name="alligator_htf", params={
                "htf_multiplier": int(self.params["htf_multiplier"]),
                **alligator_params,
            }),
            IndicatorRequest(name="alligator_macro", params={
                "macro_htf_multiplier": int(self.params["macro_htf_multiplier"]),
                **alligator_params,
            }),
            IndicatorRequest(name="adx", params={
                "adx_period": 14,
                "adx_lookback": 3,
            }),
        ]

    def signal(self, row: pd.Series, position: Optional[Position] = None) -> Signal:
        """3-TF confluence Alligator entry with cycle and ADX filters."""

        if position is not None:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="In position")

        # ── Read indicator values ─────────────────────────────────────────
        jaw   = row.get("jaw")
        teeth = row.get("teeth")
        lips  = row.get("lips")
        if jaw is None or teeth is None or lips is None:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="Alligator NaN")

        jaw   = float(jaw)
        teeth = float(teeth)
        lips  = float(lips)

        ltf_bull        = bool(row.get("ltf_bull", False))
        ltf_spread_pct  = float(row.get("ltf_spread_pct", 0.0) or 0.0)
        htf_bull        = bool(row.get("htf_bull", True))
        macro_htf_bull  = bool(row.get("macro_htf_bull", True))
        macro_spread    = float(row.get("macro_htf_spread_pct", 0.0) or 0.0)
        adx             = float(row.get("adx", 0.0) or 0.0)
        streak          = int(row.get("alignment_streak", 0) or 0)

        prev_low              = float(row.get("prev_low", 0.0) or 0.0)
        prev_close            = float(row.get("prev_close", 0.0) or 0.0)
        prev_open             = float(row.get("prev_open", 0.0) or 0.0)
        prev_body_ratio       = float(row.get("prev_body_ratio", 0.0) or 0.0)
        prev_close_vs_teeth   = float(row.get("prev_close_vs_teeth_pct", 0.0) or 0.0)

        close = float(row["close"])
        open_ = float(row["open"])
        low   = float(row["low"])

        # ── Filter 1: Macro cycle — daily alligator must be bullish ──────
        if not macro_htf_bull:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="Macro bearish (bear cycle)")

        macro_min = float(self.params["macro_spread_min"])
        if macro_min > 0 and macro_spread < macro_min:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"Macro spread {macro_spread:.2f}% < {macro_min}%")

        # ── Filter 2: Intermediate HTF (4h) must be bullish ──────────────
        if not htf_bull:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="HTF bearish")

        # ── Filter 3: LTF (1h) alligator must be bullish and open ────────
        if not ltf_bull:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="LTF not bullish")

        ltf_min = float(self.params["min_ltf_spread_pct"])
        if ltf_min > 0 and ltf_spread_pct < ltf_min:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"LTF spread {ltf_spread_pct:.3f}% < {ltf_min}%")

        # ── Filter 4: ADX — trending but not parabolic ───────────────────
        adx_thr = float(self.params["adx_threshold"])
        if adx_thr > 0 and adx < adx_thr:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"ADX {adx:.1f} < {adx_thr}")

        adx_max = float(self.params["adx_max"])
        if adx_max > 0 and adx > adx_max:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"ADX {adx:.1f} > {adx_max} (parabolic)")

        # ── Filter 5: Alignment streak — trend not exhausted ─────────────
        streak_max = int(self.params["streak_max"])
        if streak_max > 0 and streak > streak_max:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"Streak {streak} > {streak_max} (exhausted)")

        # ── Bar 1: pullback touch of Lips + bounce above Teeth ────────────
        bar1_touched_lips   = prev_low <= lips
        bar1_above_teeth    = prev_close > teeth
        bar1_green          = prev_close > prev_open
        bar1_body_in_zone   = prev_open <= teeth  # body entered alligator zone (not just wick)

        if not (bar1_touched_lips and bar1_above_teeth and bar1_green):
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="Bar1 pattern fail")

        require_body = bool(self.params.get("require_body_in_zone", False))
        if require_body and not bar1_body_in_zone:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason="Bar1 body above zone (wick-only touch)")

        min_body = float(self.params["min_prev_body_ratio"])
        if min_body > 0 and prev_body_ratio < min_body:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"Bar1 body {prev_body_ratio:.2f} < {min_body}")

        min_bounce = float(self.params["min_bounce_pct"])
        if min_bounce > 0 and prev_close_vs_teeth < min_bounce:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"Bounce {prev_close_vs_teeth:.2f}% < {min_bounce}%")

        # ── Bar 2: entry candle — green, low at or above Lips ────────────
        bar2_green          = close > open_
        bar2_low_above_lips = low >= lips

        if not (bar2_green and bar2_low_above_lips):
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="Bar2 pattern fail")

        # ── SL / TP ───────────────────────────────────────────────────────
        sl_buffer = float(self.params["sl_buffer_pct"])
        sl_price  = jaw * (1.0 - sl_buffer)
        risk      = close - sl_price

        if risk <= 0:
            return Signal(action=SignalAction.HOLD, strength=0.0, reason="Zero risk")

        max_sl = float(self.params["max_sl_pct"])
        if max_sl > 0 and risk / close > max_sl:
            return Signal(action=SignalAction.HOLD, strength=0.0,
                          reason=f"SL too wide: {risk/close:.3f} > {max_sl}")

        disable_tp = bool(self.params.get("disable_tp", False))
        tp1_price  = None if disable_tp else close + risk        # 1:1
        tp2_price  = None if disable_tp else close + 2.0 * risk  # 2:1

        return Signal(
            action=SignalAction.BUY,
            strength=1.0,
            reason=(f"V4 BUY | macro={macro_spread:.1f}% ltf={ltf_spread_pct:.2f}% "
                    f"adx={adx:.1f}"),
            sl_price=sl_price,
            tp1_price=tp1_price,
            tp2_price=tp2_price,
        )
