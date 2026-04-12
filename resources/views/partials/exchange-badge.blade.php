@php
    $colors = [
        'hyperliquid' => 'bg-blue-500/20 text-blue-400 border-blue-500/30',
        'binance'     => 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    ];
    $classes = $colors[$exchange ?? ''] ?? 'bg-gray-800 text-gray-400 border-gray-700';
@endphp
<span class="{{ $classes }} border px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wide">
    {{ $exchange ?? '—' }}
</span>
