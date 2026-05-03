<?php

namespace App\Services;

class TradeCalculatorService
{
    /**
     * Calculate trade sizing from a risk amount in USD.
     *
     * @return array{quantity: float, notional_usd: float, risk_usd: float, tp1_qty: float, tp2_qty: float|null, tp1_profit_usd: float|null, tp2_profit_usd: float|null, rr_tp1: float|null, rr_tp2: float|null}
     */
    public function calculate(
        float $entry,
        float $sl,
        ?float $tp1,
        ?float $tp2,
        float $riskUsd,
    ): array {
        $slDistance = abs($entry - $sl);

        $quantity = $riskUsd / $slDistance;
        $notional = $quantity * $entry;

        $tp1Qty = $tp2 !== null ? $quantity * 0.70 : $quantity;
        $tp2Qty = $tp2 !== null ? $quantity * 0.30 : null;

        $tp1Profit = $tp1 !== null ? $tp1Qty * abs($tp1 - $entry) : null;
        $tp2Profit = ($tp2 !== null && $tp2Qty !== null) ? $tp2Qty * abs($tp2 - $entry) : null;

        $rrTp1 = $tp1 !== null ? round(abs($tp1 - $entry) / $slDistance, 2) : null;
        $rrTp2 = $tp2 !== null ? round(abs($tp2 - $entry) / $slDistance, 2) : null;

        return [
            'quantity' => $quantity,
            'notional_usd' => $notional,
            'risk_usd' => $riskUsd,
            'tp1_qty' => $tp1Qty,
            'tp2_qty' => $tp2Qty,
            'tp1_profit_usd' => $tp1Profit,
            'tp2_profit_usd' => $tp2Profit,
            'rr_tp1' => $rrTp1,
            'rr_tp2' => $rrTp2,
        ];
    }
}
