<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exchange Configuration
    |--------------------------------------------------------------------------
    |
    | Minimum thresholds for each exchange used by the scanner to filter
    | trading pairs. Volume is in USD, rvol is the relative volume ratio,
    | and bullish_tfs is the minimum number of bullish timeframes.
    |
    */

    'exchanges' => [

        'hyperliquid' => [
            'min_volume' => env('TRADING_HYPERLIQUID_MIN_VOLUME', 100_000),
            'min_rvol' => env('TRADING_HYPERLIQUID_MIN_RVOL', 0.4),
            'min_bullish_tfs' => env('TRADING_HYPERLIQUID_MIN_BULLISH_TFS', 3),
        ],

        'binance' => [
            'min_volume' => env('TRADING_BINANCE_MIN_VOLUME', 1_000_000),
            'min_rvol' => env('TRADING_BINANCE_MIN_RVOL', 0.4),
            'min_bullish_tfs' => env('TRADING_BINANCE_MIN_BULLISH_TFS', 3),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner Settings
    |--------------------------------------------------------------------------
    |
    | Controls how many results the scanner returns and how far back (in
    | days) it looks when evaluating trading pairs.
    |
    */

    'scanner' => [
        'top' => env('TRADING_SCANNER_TOP', 20),
        'lookback' => env('TRADING_SCANNER_LOOKBACK', 1),
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

];
