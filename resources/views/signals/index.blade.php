<x-layouts.app title="Signals">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-bold text-gray-200 tracking-wide">Signals</h1>
    </div>

    {{-- Filters --}}
    <form method="GET" class="flex gap-3 mb-6 flex-wrap">
        <input type="text" name="pair" value="{{ request('pair') }}"
               placeholder="Pair (e.g. BTC)"
               class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 placeholder-gray-600 focus:outline-none focus:border-emerald-500 w-36">
        <select name="tf" class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 focus:outline-none focus:border-emerald-500">
            <option value="">All TFs</option>
            @foreach(['5M','15M','1H','4H','8H','12H','1D'] as $tf)
            <option value="{{ $tf }}" {{ request('tf') === $tf ? 'selected' : '' }}>{{ $tf }}</option>
            @endforeach
        </select>
        <select name="status" class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 focus:outline-none focus:border-emerald-500">
            <option value="">All Statuses</option>
            @foreach(['active','tp1_hit','tp2_hit','sl_hit','expired'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-1.5 rounded text-xs">Filter</button>
        @if(request()->hasAny(['pair','tf','status']))
        <a href="{{ route('signals.index') }}" class="px-4 py-1.5 rounded text-xs text-gray-400 hover:text-gray-200 border border-gray-700">Clear</a>
        @endif
    </form>

    <div class="bg-gray-900 border border-gray-800 rounded-lg">
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-gray-600 border-b border-gray-800">
                    <th class="text-left px-4 py-3">Pair</th>
                    <th class="text-left px-4 py-3">TF</th>
                    <th class="text-left px-4 py-3">Type</th>
                    <th class="text-right px-4 py-3">Entry</th>
                    <th class="text-right px-4 py-3">SL</th>
                    <th class="text-right px-4 py-3">TP1</th>
                    <th class="text-right px-4 py-3">TP2</th>
                    <th class="text-right px-4 py-3">Risk%</th>
                    <th class="text-left px-4 py-3">Candle Time</th>
                    <th class="text-right px-4 py-3">Ago</th>
                    <th class="text-left px-4 py-3">Status</th>
                    <th class="text-left px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($signals as $signal)
                <tr class="border-b border-gray-800/50 hover:bg-gray-800/30">
                    <td class="px-4 py-2 font-semibold text-white">{{ $signal->pair }}</td>
                    <td class="px-4 py-2 text-gray-400">{{ $signal->timeframe }}</td>
                    <td class="px-4 py-2 text-yellow-400">{{ $signal->entry_type }}</td>
                    <td class="px-4 py-2 text-right text-gray-300">{{ number_format($signal->entry_price, 4) }}</td>
                    <td class="px-4 py-2 text-right text-red-400">{{ $signal->sl_price ? number_format($signal->sl_price, 4) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-emerald-400">{{ $signal->tp1_price ? number_format($signal->tp1_price, 4) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-emerald-300">{{ $signal->tp2_price ? number_format($signal->tp2_price, 4) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-gray-400">{{ number_format($signal->risk_pct, 2) }}%</td>
                    <td class="px-4 py-2 text-gray-500">{{ $signal->candle_time->format('M d g:i A') }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">-{{ $signal->candles_ago }}</td>
                    <td class="px-4 py-2"><x-signal-status :status="$signal->status" /></td>
                    <td class="px-4 py-2">
                        <a href="{{ route('signals.show', $signal) }}" class="text-emerald-600 hover:text-emerald-400">detail →</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="12" class="px-4 py-8 text-center text-gray-600">No signals found</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($signals->hasPages())
        <div class="px-4 py-3 border-t border-gray-800">
            {{ $signals->links() }}
        </div>
        @endif
    </div>

</x-layouts.app>
