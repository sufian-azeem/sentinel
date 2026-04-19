<?php

namespace App\Enums;

enum Exchange: string
{
    case Hyperliquid = 'hyperliquid';
    case Binance = 'binance';
    case Mexc = 'mexc';
    case Bybit = 'bybit';
}
