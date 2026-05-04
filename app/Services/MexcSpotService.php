<?php

namespace App\Services;

use App\Models\ExecutedTrade;
use App\Models\Signal;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MexcSpotService
{
    private const BASE_URL = 'https://api.mexc.com';

    public function __construct(private TradeCalculatorService $calculator) {}

    public function executeSignal(Signal $signal, float $riskUsd, float $sl, ?float $tp1, ?float $tp2): ExecutedTrade
    {
        $calc = $this->calculator->calculate(
            entry: (float) $signal->entry_price,
            sl: $sl,
            tp1: $tp1,
            tp2: $tp2,
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
            'sl_price' => $sl,
            'tp1_price' => $tp1,
            'tp2_price' => $tp2,
            'status' => 'pending',
        ]);

        try {
            $entry = $this->placeMarketBuy($symbol, $calc['quantity']);
            $trade->update([
                'exchange_order_id' => (string) $entry['orderId'],
                'entry_fill_status' => 'pending',
            ]);

            if ($tp2 && $tp1) {
                $oco1 = $this->placeOco($symbol, $calc['tp1_qty'], $tp1, $sl);
                $oco2 = $this->placeOco($symbol, $calc['tp2_qty'], $tp2, $sl);

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
            } elseif ($tp1) {
                $oco = $this->placeOco($symbol, $calc['tp1_qty'], $tp1, $sl);

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

        return $trade;
    }

    public function moveBreakeven(ExecutedTrade $trade): void
    {
        $symbol = $this->toSymbol($trade->pair);
        $meta = $trade->trailing_tp_json ?? [];

        foreach (array_filter([$meta['oco2_sl_id'] ?? null, $meta['oco2_tp_id'] ?? null]) as $orderId) {
            $this->cancelOrder($symbol, $orderId);
        }

        $newOco2 = $this->placeOco(
            $symbol,
            (float) $trade->quantity * 0.30,
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

        // MEXC OCO: positional fallback — [0] LIMIT (TP), [1] STOP_LOSS_LIMIT (SL)
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

    private function cancelOrder(string $symbol, string $orderId): bool
    {
        try {
            $this->request('DELETE', '/api/v3/order', ['symbol' => $symbol, 'orderId' => $orderId]);

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
        $queryString .= '&signature='.hash_hmac('sha256', $queryString, $apiSecret);

        $response = Http::timeout(10)
            ->withHeaders(['X-MEXC-APIKEY' => $apiKey])
            ->$method(self::BASE_URL.$path.'?'.$queryString);

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
