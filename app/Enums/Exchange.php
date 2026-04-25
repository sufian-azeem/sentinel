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
}
