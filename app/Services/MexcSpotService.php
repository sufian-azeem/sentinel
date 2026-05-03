<?php

namespace App\Services;

use App\Models\ExecutedTrade;
use App\Models\Signal;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MexcSpotService
{
    private const BASE_URL = 'https://api.mexc.com';

    public function executeSignal(Signal $signal, float $riskUsd): ExecutedTrade
    {
        $calc = (new TradeCalculatorService)->calculate(
            entry: (float) $signal->entry_price,
            sl: (float) $signal->sl_price,
            tp1: $signal->tp1_price ? (float) $signal->tp1_price : null,
            tp2: $signal->tp2_price ? (float) $signal->tp2_price : null,
            riskUsd: $riskUsd,
        );

        $symbol = $this->toSymbol($signal->pair);

        $trade = ExecutedTrade::create([
            'signal_id' => $signal->id,
            'exchange' => 'mexc',
            'pair' => $signal->pair,
            'side' => 'long',
            'order_type' => 'market',
            'leverage' => 1,
            'quantity' => $calc['quantity'],
            'notional_usd' => $calc['notional_usd'],
            'entry_price' => $signal->entry_price,
            'sl_price' => $signal->sl_price,
            'tp1_price' => $signal->tp1_price,
            'tp2_price' => $signal->tp2_price,
            'status' => 'pending',
        ]);

        try {
            $entry = $this->placeMarketBuy($symbol, $calc['quantity']);
            $trade->update([
                'exchange_order_id' => (string) $entry['orderId'],
                'entry_fill_status' => 'pending',
            ]);

            if ($signal->tp2_price) {
                // OCO #1: 70% at TP1 + SL
                $oco1 = $this->placeOco(
                    $symbol,
                    $calc['tp1_qty'],
                    (float) $signal->tp1_price,
                    (float) $signal->sl_price,
                );
                // OCO #2: 30% at TP2 + SL
                $oco2 = $this->placeOco(
                    $symbol,
                    $calc['tp2_qty'],
                    (float) $signal->tp2_price,
                    (float) $signal->sl_price,
                );

                $trade->update([
                    'tp1_order_id' => (string) $oco1['tp_order_id'],
                    'sl_order_id' => (string) $oco1['sl_order_id'],
                    'tp2_order_id' => (string) $oco2['tp_order_id'],
                    'trailing_tp_json' => [
                        'oco2_list_id' => (string) $oco2['order_list_id'],
                        'oco2_sl_id' => (string) $oco2['sl_order_id'],
                        'oco2_tp_id' => (string) $oco2['tp_order_id'],
                    ],
                    'status' => 'open',
                ]);
            } else {
                // Single OCO: 100% at TP1 + SL
                $oco = $this->placeOco(
                    $symbol,
                    $calc['tp1_qty'],
                    (float) $signal->tp1_price,
                    (float) $signal->sl_price,
                );

                $trade->update([
                    'tp1_order_id' => (string) $oco['tp_order_id'],
                    'sl_order_id' => (string) $oco['sl_order_id'],
                    'status' => 'open',
                ]);
            }
        } catch (\Throwable $e) {
            $trade->update(['status' => 'cancelled', 'notes' => $e->getMessage()]);
            throw $e;
        }

        return $trade->fresh();
    }

    public function moveBreakeven(ExecutedTrade $trade): void
    {
        $symbol = $this->toSymbol($trade->pair);
        $meta = $trade->trailing_tp_json ?? [];

        $oco2SlId = $meta['oco2_sl_id'] ?? null;
        $oco2TpId = $meta['oco2_tp_id'] ?? null;

        // Cancel the existing OCO #2 legs individually
        if ($oco2SlId) {
            $this->cancelOrder($symbol, $oco2SlId);
        }
        if ($oco2TpId && $oco2TpId !== $oco2SlId) {
            $this->cancelOrder($symbol, $oco2TpId);
        }

        // Place new OCO #2: TP2 limit + break-even SL at entry price
        $tp2Qty = (float) $trade->quantity * 0.30;

        $newOco2 = $this->placeOco(
            $symbol,
            $tp2Qty,
            (float) $trade->tp2_price,
            (float) $trade->entry_price,
        );

        $trade->update([
            'tp2_order_id' => (string) $newOco2['tp_order_id'],
            'sl_price' => $trade->entry_price,
            'trailing_tp_json' => [
                'oco2_list_id' => (string) $newOco2['order_list_id'],
                'oco2_sl_id' => (string) $newOco2['sl_order_id'],
                'oco2_tp_id' => (string) $newOco2['tp_order_id'],
            ],
            'breakeven_moved_at' => now(),
        ]);
    }

    private function placeMarketBuy(string $symbol, float $quantity): array
    {
        return $this->request('POST', '/api/v3/order', [
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quantity' => $this->formatQty($quantity),
        ]);
    }

    /**
     * Place an OCO order (limit sell at tpPrice + stop-loss sell at slPrice).
     *
     * @return array{order_list_id: string, tp_order_id: string, sl_order_id: string}
     */
    private function placeOco(string $symbol, float $quantity, float $tpPrice, float $slPrice): array
    {
        $response = $this->request('POST', '/api/v3/order/oco', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'quantity' => $this->formatQty($quantity),
            'price' => $this->formatPrice($tpPrice),
            'stopPrice' => $this->formatPrice($slPrice),
            'stopLimitPrice' => $this->formatPrice($slPrice),
            'stopLimitTimeInForce' => 'GTC',
        ]);

        $orders = $response['orderReports'] ?? $response['orders'] ?? [];

        // MEXC OCO returns orders: [0] = LIMIT (TP), [1] = STOP_LOSS_LIMIT (SL)
        $tpOrderId = null;
        $slOrderId = null;

        foreach ($orders as $order) {
            if (($order['type'] ?? '') === 'LIMIT_MAKER' || ($order['type'] ?? '') === 'LIMIT') {
                $tpOrderId = (string) $order['orderId'];
            } else {
                $slOrderId = (string) $order['orderId'];
            }
        }

        if (! $tpOrderId || ! $slOrderId) {
            // Fallback: positional
            $tpOrderId = (string) ($orders[0]['orderId'] ?? '');
            $slOrderId = (string) ($orders[1]['orderId'] ?? '');
        }

        return [
            'order_list_id' => (string) ($response['orderListId'] ?? ''),
            'tp_order_id' => $tpOrderId,
            'sl_order_id' => $slOrderId,
        ];
    }

    private function cancelOrder(string $symbol, string $orderId): bool
    {
        try {
            $this->request('DELETE', '/api/v3/order', [
                'symbol' => $symbol,
                'orderId' => $orderId,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function request(string $method, string $path, array $params = []): array
    {
        $apiKey = config('services.mexc.api_key');
        $apiSecret = config('services.mexc.api_secret');

        if (! $apiKey || ! $apiSecret) {
            throw new RuntimeException('MEXC API credentials are not configured.');
        }

        $params['timestamp'] = (string) (int) (microtime(true) * 1000);
        $params['recvWindow'] = '5000';

        $queryString = http_build_query($params);
        $signature = hash_hmac('sha256', $queryString, $apiSecret);
        $queryString .= '&signature='.$signature;

        $url = self::BASE_URL.$path.'?'.$queryString;
        $response = Http::timeout(10)
            ->withHeaders(['X-MEXC-APIKEY' => $apiKey])
            ->$method($url);

        if (! $response->successful()) {
            $body = $response->json();
            $msg = $body['msg'] ?? $body['message'] ?? $response->body();
            throw new RuntimeException("MEXC API error ({$response->status()}): {$msg}");
        }

        return $response->json();
    }

    private function toSymbol(string $pair): string
    {
        return str_replace('/', '', $pair);
    }

    private function formatQty(float $qty): string
    {
        return rtrim(number_format($qty, 8, '.', ''), '0');
    }

    private function formatPrice(float $price): string
    {
        return rtrim(number_format($price, 8, '.', ''), '0');
    }
}
