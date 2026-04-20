@props([
    'pair',
    'exchange' => 'binance',
    'tfData'   => null,
    'interval' => '60',
    'class'    => 'text-white hover:text-emerald-400',
])

@php
    $baseAsset  = Str::before($pair, '/');
    $usedMexc   = $tfData && collect($tfData)->contains(fn($td) => ($td['exchange'] ?? null) === 'mexc');
    $tvExchange = $usedMexc ? 'MEXC' : 'BINANCE';
    $url = $exchange === 'hyperliquid'
        ? 'https://app.hyperliquid.xyz/trade/' . $baseAsset
        : 'https://www.tradingview.com/chart/?symbol=' . $tvExchange . ':' . rawurlencode(str_replace(['/', '币安人生'], ['', 'BIANRENSHENG'], $pair)) . '&interval=' . $interval;
@endphp

<a href="{{ $url }}" target="_blank" {{ $attributes->class([$class]) }}>{{ $pair }}</a>
