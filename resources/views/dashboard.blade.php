<x-layouts.app title="Dashboard" :autoRefresh="true">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-bold text-gray-200 tracking-wide">Dashboard</h1>
        <span class="text-xs text-gray-600">Auto-refreshes every 60s</span>
    </div>

    {{-- Stats Row --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
            <div class="text-xs text-gray-500 mb-1">Active Signals</div>
            <div class="text-2xl font-bold text-emerald-400">{{ $activeSignals }}</div>
        </div>
        @if($latestRun)
        <a href="{{ route('screener.show', $latestRun) }}" class="bg-gray-900 border border-gray-800 rounded-lg p-4 hover:border-gray-600 block">
        @else
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
        @endif
            <div class="text-xs text-gray-500 mb-1">Latest Run</div>
            <div class="text-2xl font-bold text-gray-200">
                {{ $latestRun ? $latestRun->total_matched : '—' }}
                <span class="text-sm text-gray-500">/ {{ $latestRun ? $latestRun->total_scanned : '—' }}</span>
            </div>
        @if($latestRun) </a> @else </div> @endif
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
            <div class="text-xs text-gray-500 mb-1">Last Run Status</div>
            <div class="mt-1">
                @if($latestRun)
                    <x-run-status :status="$latestRun->status" />
                @else
                    <span class="text-gray-600 text-sm">No runs yet</span>
                @endif
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
            <div class="text-xs text-gray-500 mb-1">Last Run At</div>
            <div class="text-sm text-gray-300">
                @if($latestRun)<x-timestamp :value="$latestRun->started_at" /> {{ now(config('app.timezone'))->format('T') }}@else—@endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Recent Signals --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
                <h2 class="text-sm font-semibold text-gray-300">Recent Signals</h2>
                <a href="{{ route('signals.index') }}" class="text-xs text-emerald-500 hover:text-emerald-400">View all →</a>
            </div>
            @if($recentSignals->isEmpty())
                <div class="px-4 py-6 text-center text-xs text-gray-600">No signals yet</div>
            @else
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-600 border-b border-gray-800">
                        <th class="text-left px-4 py-2">Pair</th>
                        <th class="text-left px-4 py-2">TF</th>
                        <th class="text-left px-4 py-2">Type</th>
                        <th class="text-right px-4 py-2">Entry</th>
                        <th class="text-left px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentSignals as $signal)
                    <tr class="border-b border-gray-800/50 hover:bg-gray-800/30">
                        <td class="px-4 py-2">
                            <a href="{{ route('signals.show', $signal) }}" class="text-emerald-400 hover:text-emerald-300">
                                {{ $signal->pair }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-gray-400">{{ $signal->timeframe }}</td>
                        <td class="px-4 py-2 text-yellow-400">{{ $signal->entry_type }}</td>
                        <td class="px-4 py-2 text-right text-gray-300">{{ number_format($signal->entry_price, 4) }}</td>
                        <td class="px-4 py-2"><x-signal-status :status="$signal->status" /></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>

        {{-- Recent Runs --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
                <h2 class="text-sm font-semibold text-gray-300">Recent Screener Runs</h2>
                <a href="{{ route('screener.history') }}" class="text-xs text-emerald-500 hover:text-emerald-400">View all →</a>
            </div>
            @if($recentRuns->isEmpty())
                <div class="px-4 py-6 text-center text-xs text-gray-600">No runs yet</div>
            @else
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-600 border-b border-gray-800">
                        <th class="text-left px-4 py-2">Started</th>
                        <th class="text-left px-4 py-2">Exchange</th>
                        <th class="text-left px-4 py-2">Source</th>
                        <th class="text-right px-4 py-2">Scanned</th>
                        <th class="text-right px-4 py-2">Matched</th>
                        <th class="text-left px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentRuns as $run)
                    <tr class="border-b border-gray-800/50 hover:bg-gray-800/30 cursor-pointer"
                        onclick="window.location='{{ route('screener.show', $run) }}'">
                        <td class="px-4 py-2 text-gray-400"><x-timestamp :value="$run->started_at" /></td>
                        <td class="px-4 py-2"><x-exchange-badge :exchange="$run->filters_json['exchange'] ?? null" /></td>
                        <td class="px-4 py-2 text-gray-500">{{ $run->data_source }}</td>
                        <td class="px-4 py-2 text-right text-gray-400">{{ $run->total_scanned }}</td>
                        <td class="px-4 py-2 text-right text-emerald-400">{{ $run->total_matched }}</td>
                        <td class="px-4 py-2"><x-run-status :status="$run->status" /></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>

    </div>

</x-layouts.app>
