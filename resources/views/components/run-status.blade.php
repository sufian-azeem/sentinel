@props(['status'])
@php
$colors = [
    'running'   => 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    'completed' => 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
    'failed'    => 'bg-red-500/20 text-red-400 border-red-500/30',
];
$cls = $colors[$status] ?? 'bg-gray-500/20 text-gray-500 border-gray-500/30';
@endphp
<span class="inline-block px-1.5 py-px rounded border text-[10px] {{ $cls }}">{{ $status }}</span>
