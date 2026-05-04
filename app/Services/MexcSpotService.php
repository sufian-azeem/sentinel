<?php

namespace App\Services;

use App\Models\ExecutedTrade;
use App\Models\Signal;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MexcSpotService
{
    private const BASE_URL = 'https://api.mexc.com';

    private float $qtyStep = 0.00000001;

    private float $priceTick = 0.00000001;

    public function executeSignal(Signal $signal, float $riskUsd, float $sl): ExecutedTrade
    {
        $symbol = $this->toSymbol($signal->pair);
        $this->loadSymbolFilters($symbol);

        $entryEstimate = (float) $signal->entry_price;
        $sl = (float) $this->formatPrice($sl);
        $quantity = $riskUsd / ($entryEstimate - $sl);

        $trade = ExecutedTrade::create([
            'signal_id' => $signal->id,
            'exchange' => 'mexc',
            'pair' => $signal->pair,
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
            $entry = $this->placeMarketBuy($symbol, $quantity);
        } catch (\Throwable $e) {
            $trade->delete();
            throw $e;
        }

        $execQty = (float) ($entry['executedQty'] ?? 0);
        $quoteQty = (float) ($entry['cummulativeQuoteQty'] ?? 0);
        $fillPrice = ($execQty > 0 && $quoteQty > 0)
            ? $quoteQty / $execQty
            : $entryEstimate;

        $slDist = $fillPrice - $sl;
        $tp1 = $fillPrice + $slDist;
        $tp2 = $fillPrice + 2 * $slDist;

        $trade->update([
            'exchange_order_id' => (string) $entry['orderId'],
            'entry_fill_status' => ($entry['status'] ?? '') === 'FILLED' ? 'filled' : 'pending',
            'entry_price' => $fillPrice,
            'quantity' => $execQty ?: $quantity,
            'notional_usd' => $quoteQty ?: $fillPrice * $quantity,
            'tp1_price' => $tp1,
            'tp2_price' => $tp2,
        ]);

        $execQty = (float) $trade->quantity;

        // Phase 2: place TP + SL orders for each tranche — if this fails, keep record as cancelled.
        try {
            $tp1Qty = $execQty * 0.70;
            $tp2Qty = $execQty * 0.30;

            $tp1Order = $this->placeLimitSell($symbol, $tp1Qty, $tp1);
            $tp2Order = $this->placeLimitSell($symbol, $tp2Qty, $tp2);
            $sl1Order = $this->placeStopLossLimit($symbol, $tp1Qty, $sl);
            $sl2Order = $this->placeStopLossLimit($symbol, $tp2Qty, $sl);

            $trade->update([
                'tp1_order_id' => (string) $tp1Order['orderId'],
                'tp2_order_id' => (string) $tp2Order['orderId'],
                'sl_order_id' => (string) $sl1Order['orderId'],
                'trailing_tp_json' => [
                    'sl2_id' => (string) $sl2Order['orderId'],
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
        $symbol = $this->toSymbol($trade->pair);
        $this->loadSymbolFilters($symbol);

        $meta = $trade->trailing_tp_json ?? [];

        // Cancel the original SL orders for both tranches
        foreach (array_filter([(string) ($trade->sl_order_id ?? ''), (string) ($meta['sl2_id'] ?? '')]) as $orderId) {
            $this->cancelOrder($symbol, $orderId);
        }

        // Place new 30% SL at entry price (breakeven)
        $tp2Qty = (float) $trade->quantity * 0.30;
        $breakeven = (float) $trade->entry_price;
        $newSl = $this->placeStopLossLimit($symbol, $tp2Qty, $breakeven);

        $trade->update([
            'sl_order_id' => (string) $newSl['orderId'],
            'sl_price' => $breakeven,
            'trailing_tp_json' => [
                'sl2_id' => null,
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

    private function placeLimitSell(string $symbol, float $quantity, float $price): array
    {
        return $this->request('POST', '/api/v3/order', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'LIMIT',
            'quantity' => $this->formatQty($quantity),
            'price' => $this->formatPrice($price),
            'timeInForce' => 'GTC',
        ]);
    }

    private function placeStopLossLimit(string $symbol, float $quantity, float $stopPrice): array
    {
        // Limit price set 0.5% below stop to ensure fill on fast moves
        $limitPrice = $stopPrice * 0.995;

        return $this->request('POST', '/api/v3/order', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'STOP_LOSS_LIMIT',
            'quantity' => $this->formatQty($quantity),
            'price' => $this->formatPrice($limitPrice),
            'stopPrice' => $this->formatPrice($stopPrice),
            'timeInForce' => 'GTC',
        ]);
    }

    private function cancelOrder(string $symbol, string $orderId): bool
    {
        if (! $orderId) {
            return false;
        }

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

    private function loadSymbolFilters(string $symbol): void
    {
        $response = Http::timeout(10)->get(self::BASE_URL.'/api/v3/exchangeInfo', ['symbol' => $symbol]);

        if (! $response->successful()) {
            return;
        }

        $info = $response->json('symbols.0', []);

        if (isset($info['baseAssetPrecision'])) {
            $this->qtyStep = 10 ** -(int) $info['baseAssetPrecision'];
        }
        if (isset($info['quotePrecision'])) {
            $this->priceTick = 10 ** -(int) $info['quotePrecision'];
        }

        foreach ($info['filters'] ?? [] as $filter) {
            if ($filter['filterType'] === 'LOT_SIZE' && (float) ($filter['stepSize'] ?? 0) > 0) {
                $this->qtyStep = (float) $filter['stepSize'];
            }
            if ($filter['filterType'] === 'PRICE_FILTER' && (float) ($filter['tickSize'] ?? 0) > 0) {
                $this->priceTick = (float) $filter['tickSize'];
            }
        }
    }

    private function toSymbol(string $pair): string
    {
        return str_replace('/', '', $pair);
    }

    private function formatQty(float $qty): string
    {
        $step = $this->qtyStep;
        $decimals = max(0, (int) ceil(-log10($step)));
        $floored = floor($qty / $step) * $step;

        return number_format($floored, $decimals, '.', '');
    }

    private function formatPrice(float $price): string
    {
        $tick = $this->priceTick;
        $decimals = max(0, (int) ceil(-log10($tick)));
        $rounded = round($price / $tick) * $tick;

        return number_format($rounded, $decimals, '.', '');
    }
}
