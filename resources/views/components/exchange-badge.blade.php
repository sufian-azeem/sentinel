@props(['exchange' => null])
@php
    $colors = [
        'hyperliquid' => 'bg-blue-500/20 text-blue-400 border-blue-500/30',
        'binance'     => 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    ];
    $classes = $colors[$exchange ?? ''] ?? 'bg-gray-800 text-gray-400 border-gray-700';
@endphp
<span class="{{ $classes }} border px-1.5 py-px rounded text-[10px] font-semibold uppercase">
    {{ $exchange ?? '—' }}
</span>
