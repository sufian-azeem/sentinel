<?php

namespace App\Services;

use App\Models\ExecutedTrade;
use App\Models\Signal;
use ccxt\mexc;
use RuntimeException;

class MexcSpotService
{
    private mexc $exchange;

    public function __construct()
    {
        $apiKey = config('services.mexc.api_key');
        $apiSecret = config('services.mexc.api_secret');

        if (! $apiKey || ! $apiSecret) {
            throw new RuntimeException('MEXC API credentials are not configured.');
        }

        $this->exchange = new mexc([
            'apiKey' => $apiKey,
            'secret' => $apiSecret,
        ]);
        $this->exchange->load_markets();
    }

    public function executeSignal(Signal $signal, float $riskUsd, float $sl): ExecutedTrade
    {
        $pair = $signal->pair;
        $entryEstimate = (float) $signal->entry_price;
        $sl = (float) $this->exchange->price_to_precision($pair, $sl);
        $quantity = $riskUsd / ($entryEstimate - $sl);

        $trade = ExecutedTrade::create([
            'signal_id' => $signal->id,
            'exchange' => 'mexc',
            'pair' => $pair,
            'side' => 'long',
            'order_type' => 'market',
            'leverage' => 1,
            'quantity' => $quantity,
            'notional_usd' => $quantity * $entryEstimate,
            'entry_price' => $entryEstimate,
            'sl_price' => $sl,
            'status' => 'pending',
        ]);

        // Phase 1: market buy — if this fails, nothing was purchased; delete the record.
        try {
            $order = $this->exchange->create_order($pair, 'market', 'buy', $quantity);
        } catch (\Throwable $e) {
            $trade->delete();
            throw $e;
        }

        $fillPrice = (float) ($order['average'] ?? $order['price'] ?? $entryEstimate);
        $execQty = (float) ($order['filled'] ?? $quantity);

        $slDist = $fillPrice - $sl;
        $tp1 = $fillPrice + $slDist;
        $tp2 = $fillPrice + 2 * $slDist;

        $trade->update([
            'exchange_order_id' => (string) ($order['id'] ?? ''),
            'entry_fill_status' => ($order['status'] ?? '') === 'closed' ? 'filled' : 'pending',
            'entry_price' => $fillPrice,
            'quantity' => $execQty,
            'notional_usd' => $fillPrice * $execQty,
            'tp1_price' => $tp1,
            'tp2_price' => $tp2,
        ]);

        // Phase 2: OCO orders — if this fails, we own the asset; keep the record as cancelled.
        try {
            $tp1Qty = $execQty * 0.70;
            $tp2Qty = $execQty * 0.30;

            $oco1 = $this->placeOco($pair, $tp1Qty, $tp1, $sl);
            $oco2 = $this->placeOco($pair, $tp2Qty, $tp2, $sl);

            $trade->update([
                'tp1_order_id' => $oco1['tp_order_id'],
                'sl_order_id' => $oco1['sl_order_id'],
                'tp2_order_id' => $oco2['tp_order_id'],
                'trailing_tp_json' => [
                    'oco2_list_id' => $oco2['order_list_id'],
                    'oco2_sl_id' => $oco2['sl_order_id'],
                    'oco2_tp_id' => $oco2['tp_order_id'],
                ],
                'status' => 'open',
            ]);
        } catch (\Throwable $e) {
            $trade->update(['status' => 'cancelled', 'notes' => $e->getMessage()]);
            throw $e;
        }

        return $trade;
    }

    public function moveBreakeven(ExecutedTrade $trade): void
    {
        $pair = $trade->pair;
        $meta = $trade->trailing_tp_json ?? [];

        foreach (array_filter([$meta['oco2_sl_id'] ?? null, $meta['oco2_tp_id'] ?? null]) as $orderId) {
            try {
                $this->exchange->cancel_order($orderId, $pair);
            } catch (\Throwable) {
                // best-effort cancel
            }
        }

        $newOco2 = $this->placeOco(
            $pair,
            (float) $trade->quantity * 0.30,
            (float) $trade->tp2_price,
            (float) $trade->entry_price,
        );

        $trade->update([
            'tp2_order_id' => $newOco2['tp_order_id'],
            'sl_price' => $trade->entry_price,
            'trailing_tp_json' => [
                'oco2_list_id' => $newOco2['order_list_id'],
                'oco2_sl_id' => $newOco2['sl_order_id'],
                'oco2_tp_id' => $newOco2['tp_order_id'],
            ],
            'breakeven_moved_at' => now(),
        ]);
    }

    /**
     * @return array{order_list_id: string, tp_order_id: string, sl_order_id: string}
     */
    private function placeOco(string $pair, float $quantity, float $tpPrice, float $slPrice): array
    {
        $symbol = $this->exchange->market_id($pair);

        $response = $this->exchange->spotPostOrderOco([
            'symbol' => $symbol,
            'side' => 'SELL',
            'quantity' => $this->exchange->amount_to_precision($pair, $quantity),
            'price' => $this->exchange->price_to_precision($pair, $tpPrice),
            'stopPrice' => $this->exchange->price_to_precision($pair, $slPrice),
            'stopLimitPrice' => $this->exchange->price_to_precision($pair, $slPrice),
            'stopLimitTimeInForce' => 'GTC',
        ]);

        return $this->parseOcoResponse($response);
    }

    /**
     * @return array{order_list_id: string, tp_order_id: string, sl_order_id: string}
     */
    private function parseOcoResponse(array $response): array
    {
        $orders = $response['orderReports'] ?? $response['orders'] ?? [];
        $tpOrderId = null;
        $slOrderId = null;

        foreach ($orders as $order) {
            $type = $order['type'] ?? '';
            if ($type === 'LIMIT_MAKER' || $type === 'LIMIT') {
                $tpOrderId = (string) $order['orderId'];
            } else {
                $slOrderId = (string) $order['orderId'];
            }
        }

        // Positional fallback: [0] LIMIT (TP), [1] STOP_LOSS_LIMIT (SL)
        if (! $tpOrderId || ! $slOrderId) {
            $tpOrderId = (string) ($orders[0]['orderId'] ?? '');
            $slOrderId = (string) ($orders[1]['orderId'] ?? '');
        }

        return [
            'order_list_id' => (string) ($response['orderListId'] ?? ''),
            'tp_order_id' => $tpOrderId,
            'sl_order_id' => $slOrderId,
        ];
    }
}
