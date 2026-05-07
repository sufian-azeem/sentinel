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
                <th class="text-right px-4 py-3">Ago</th>
                <th class="text-left px-4 py-3">Status</th>
                <th class="text-left px-4 py-3"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($signals as $signal)
            @php
                $tvInterval = \App\Enums\Timeframe::tryFrom($signal->timeframe)?->tvInterval() ?? '60';
            @endphp
            <tr class="border-b border-gray-800/50 hover:bg-gray-800/30">
                <td class="px-4 py-2 font-semibold">
                    <x-pair-link :pair="$signal->pair" :interval="$tvInterval" :exchange="$signal->pairScan?->screenerPair?->filters_json['exchange'] ?? 'binance'" :tfData="$signal->pairScan?->screenerPair?->tf_data_json" />
                </td>
                <td class="px-4 py-2 text-gray-400">
                    {{ $signal->timeframe }}
                    <div class="text-gray-600 text-[10px]"><x-timestamp :value="$signal->candle_time" format="M d, g:i A" /></div>
                </td>
                <td class="px-4 py-2 text-yellow-400">{{ $signal->entry_type }}</td>
                <td class="px-4 py-2 text-right text-gray-300">{{ number_format($signal->entry_price, 4) }}</td>
                <td class="px-4 py-2 text-right text-red-400">{{ $signal->sl_price ? number_format($signal->sl_price, 4) : '—' }}</td>
                <td class="px-4 py-2 text-right text-emerald-400">{{ $signal->tp1_price ? number_format($signal->tp1_price, 4) : '—' }}</td>
                <td class="px-4 py-2 text-right text-emerald-300">{{ $signal->tp2_price ? number_format($signal->tp2_price, 4) : '—' }}</td>
                <td class="px-4 py-2 text-right text-gray-400">{{ number_format($signal->risk_pct, 2) }}%</td>
                <td class="px-4 py-2 text-right text-gray-500">-{{ $signal->candles_ago }}</td>
                <td class="px-4 py-2"><x-signal-status :status="$signal->status" /></td>
                <td class="px-4 py-2">
                    <a href="{{ route('signals.show', $signal) }}" class="text-emerald-600 hover:text-emerald-400">detail →</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="11" class="px-4 py-8 text-center text-gray-600">No signals found</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
