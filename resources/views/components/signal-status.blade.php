@props(['status'])
@php
$colors = [
    'active'   => 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    'tp1_hit'  => 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    'tp2_hit'  => 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
    'sl_hit'   => 'bg-red-500/20 text-red-400 border-red-500/30',
    'expired'  => 'bg-gray-500/20 text-gray-500 border-gray-500/30',
];
$cls = $colors[$status] ?? 'bg-gray-500/20 text-gray-500 border-gray-500/30';
@endphp
<span class="inline-block px-2 py-0.5 rounded border text-xs {{ $cls }}">{{ $status }}</span>
