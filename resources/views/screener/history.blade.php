<x-layouts.app title="Screener History">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-bold text-gray-200 tracking-wide">Screener History</h1>
        <a href="{{ route('screener.index') }}" class="text-xs text-emerald-500 hover:text-emerald-400">← Latest Run</a>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg">
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-gray-600 border-b border-gray-800">
                    <th class="text-left px-4 py-3">#</th>
                    <th class="text-left px-4 py-3">Started</th>
                    <th class="text-left px-4 py-3">Finished</th>
                    <th class="text-left px-4 py-3">Exchange</th>
                    <th class="text-left px-4 py-3">Source</th>
                    <th class="text-right px-4 py-3">Scanned</th>
                    <th class="text-right px-4 py-3">Matched</th>
                    <th class="text-right px-4 py-3">Duration</th>
                    <th class="text-left px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($runs as $run)
                <tr class="border-b border-gray-800/50 hover:bg-gray-800/30 cursor-pointer"
                    onclick="window.location='{{ route('screener.show', $run) }}'">
                    <td class="px-4 py-2 text-gray-600">{{ $run->id }}</td>
                    <td class="px-4 py-2 text-gray-400"><x-timestamp :value="$run->started_at" format="M d, Y g:i A" /></td>
                    <td class="px-4 py-2 text-gray-500"><x-timestamp :value="$run->finished_at" format="g:i A" /></td>
                    <td class="px-4 py-2"><x-exchange-badge :exchange="$run->filters_json['exchange'] ?? null" /></td>
                    <td class="px-4 py-2 text-gray-500">{{ $run->data_source }}</td>
                    <td class="px-4 py-2 text-right text-gray-400">{{ $run->total_scanned }}</td>
                    <td class="px-4 py-2 text-right text-emerald-400">{{ $run->total_matched }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">
                        @if($run->finished_at)
                            {{ $run->started_at->diffInSeconds($run->finished_at) }}s
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2"><x-run-status :status="$run->status" /></td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-600">No runs yet</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($runs->hasPages())
        <div class="px-4 py-3 border-t border-gray-800">
            {{ $runs->links() }}
        </div>
        @endif
    </div>

</x-layouts.app>
