<?php

namespace App\Enums;

enum Exchange: string
{
    case Hyperliquid = 'hyperliquid';
    case Binance = 'binance';
    case Mexc = 'mexc';
    case Bybit = 'bybit';

    public function tradingViewName(): string
    {
        return match ($this) {
            self::Mexc => 'MEXC',
            self::Bybit => 'BYBIT',
            default => 'BINANCE',
        };
    }

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::Binance => env('BINANCE_API_URL', 'https://api.binance.com'),
            self::Mexc => env('MEXC_API_URL', 'https://api.mexc.com'),
            self::Hyperliquid => env('HYPERLIQUID_API_URL', 'https://api.hyperliquid.xyz'),
            self::Bybit => env('BYBIT_API_URL', 'https://api.bybit.com'),
        };
    }
}
