@props([
    'pair',
    'exchange' => 'binance',
    'tfData'   => null,
    'interval' => '60',
    'class'    => 'text-white hover:text-emerald-400',
])

@php
    use App\Enums\Exchange;
    $baseAsset    = Str::before($pair, '/');
    $exchangeEnum = Exchange::tryFrom($exchange) ?? Exchange::Binance;
    $url = $exchangeEnum === Exchange::Hyperliquid
        ? 'https://app.hyperliquid.xyz/trade/' . $baseAsset
        : 'https://www.tradingview.com/chart/?symbol=' . $exchangeEnum->tradingViewName() . ':' . rawurlencode(str_replace('/', '', $pair)) . '&interval=' . $interval;
@endphp

<a href="{{ $url }}" target="_blank" {{ $attributes->class([$class]) }}>{{ $pair }}</a>
