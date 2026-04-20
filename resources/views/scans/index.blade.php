<x-layouts.app title="Signal Scans">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-bold text-gray-200 tracking-wide">Signal Scans</h1>
    </div>

    {{-- ── Active Monitoring ────────────────────────────────────────────── --}}
    @if(!empty($monitoringData))
    <div class="mb-6 space-y-4">
        <div class="text-[10px] font-semibold text-gray-600 uppercase tracking-widest">Active Monitoring</div>

        @foreach($monitoringData as $run)
        <div class="bg-gray-900 border border-gray-800 rounded-lg overflow-hidden">
            {{-- Run header --}}
            <div class="px-4 py-2.5 border-b border-gray-800 flex items-center gap-2 text-xs">
                <span class="text-gray-300 font-semibold">Run #{{ $run['id'] }}</span>
                <x-exchange-badge :exchange="$run['exchange']" />
                <span class="text-gray-700 ml-1">{{ $run['expiresLabel'] }}</span>
                <span class="text-gray-700 ml-auto">{{ count($run['pairs']) }} pairs</span>
            </div>

            {{-- Pair × TF grid --}}
            @if(!empty($run['pairs']))
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-600 border-b border-gray-800/60">
                        <th class="text-left px-4 py-1.5 w-36">Pair</th>
                        <th class="text-center px-2 py-1.5">15M</th>
                        <th class="text-center px-2 py-1.5">1H</th>
                        <th class="text-center px-2 py-1.5">4H</th>
                        <th class="text-right px-4 py-1.5 w-20"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($run['pairs'] as $result)
                    <tr class="border-b border-gray-800/30 last:border-0 hover:bg-gray-800/20">
                        <td class="px-4 py-2 font-semibold text-gray-200">{{ $result['pair'] }}</td>
                        @foreach(['15M', '1H', '4H'] as $tf)
                        @php $tfData = $result['tfs'][$tf]; @endphp
                        <td class="text-center px-2 py-2">
                            @if($tfData['signal'])
                                <span class="inline-block bg-emerald-500/15 text-emerald-400 border border-emerald-500/25 px-1.5 py-px rounded text-[10px] font-medium">signal</span>
                            @elseif($tfData['status'] === 'scanned')
                                <span class="text-gray-600 text-[10px]">no setup</span>
                            @elseif($tfData['status'] === 'error')
                                <span class="inline-block bg-red-500/10 text-red-400 border border-red-500/20 px-1.5 py-px rounded text-[10px]">error</span>
                            @elseif($tfData['status'] === 'skipped')
                                <span class="text-yellow-500/70 text-[10px]">skipped</span>
                            @else
                                <span class="text-gray-800 text-[10px]">—</span>
                            @endif
                        </td>
                        @endforeach
                        <td class="text-right px-4 py-2">
                            <a href="{{ route('scans.index', ['run' => $run['id'], 'pair' => $result['pair']]) }}"
                               class="text-[10px] text-gray-700 hover:text-emerald-400 transition-colors">details →</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="px-4 py-4 text-center text-gray-700 text-xs">No qualified pairs.</div>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    {{-- Filters --}}
    <form method="GET" class="flex gap-3 mb-6 flex-wrap">
        <input type="text" name="pair" value="{{ request('pair') }}"
               placeholder="Pair (e.g. BTC)"
               class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 placeholder-gray-600 focus:outline-none focus:border-emerald-500 w-36">
        <input type="number" name="run" value="{{ request('run') }}"
               placeholder="Run #"
               class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 placeholder-gray-600 focus:outline-none focus:border-emerald-500 w-24">
        <select name="status" class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 focus:outline-none focus:border-emerald-500">
            <option value="">All Statuses</option>
            @foreach(['scanned','skipped','error'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-1.5 rounded text-xs">Filter</button>
        @if(request()->hasAny(['pair','status','run']))
        <a href="{{ route('scans.index') }}" class="px-4 py-1.5 rounded text-xs text-gray-400 hover:text-gray-200 border border-gray-700">Clear</a>
        @endif
    </form>

    <div class="space-y-3">
        @forelse($scans as $scan)
        <div class="bg-gray-900 border border-gray-800 rounded-lg" x-data="{ open: false }">

            {{-- Scan Header --}}
            <button @click="open = !open"
                    class="w-full flex items-center gap-4 px-4 py-3 hover:bg-gray-800/30 text-left">
                <span class="font-semibold text-white text-sm w-28">{{ $scan->pair }}</span>
                @php
                    $htfMap = ['15M' => '1H', '1H' => '4H', '4H' => '1D'];
                    $htf = $htfMap[$scan->timeframe] ?? '—';
                @endphp
                <span class="flex items-center gap-0.5 text-xs font-mono">
                    <span class="bg-gray-800 text-gray-300 border border-gray-700 px-1.5 py-0.5 rounded">{{ $scan->timeframe }}</span>
                    <span class="text-gray-700">→</span>
                    <span class="bg-gray-800 text-gray-500 border border-gray-700 px-1.5 py-0.5 rounded">{{ $htf }}</span>
                </span>
                <span class="text-gray-500 text-xs">{{ $scan->exchange }}</span>
                <span class="text-gray-500 text-xs">
                    @if($scan->screenerRun)
                        Run #{{ $scan->screenerRun->id }} ·
                    @endif
                    <x-timestamp :value="$scan->created_at" />
                </span>
                <span class="ml-auto flex items-center gap-3">
                    @if($scan->error_message)
                    <span class="text-gray-600 text-xs font-mono">{{ $scan->error_message }}</span>
                    @endif
                    @if($scan->signals->isNotEmpty())
                    <span class="text-emerald-400 text-xs">{{ $scan->signals->count() }} signal(s)</span>
                    @endif
                    @if($scan->status === 'scanned' && $scan->signals->isNotEmpty())
                        <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2 py-0.5 rounded text-xs">signal found</span>
                    @elseif($scan->status === 'scanned')
                        <span class="bg-gray-800 text-gray-500 border border-gray-700 px-2 py-0.5 rounded text-xs">no signal</span>
                    @elseif($scan->status === 'skipped')
                        <span class="bg-yellow-500/20 text-yellow-500 border border-yellow-500/30 px-2 py-0.5 rounded text-xs">skipped</span>
                    @elseif($scan->status === 'error')
                        <span class="bg-red-500/20 text-red-400 border border-red-500/30 px-2 py-0.5 rounded text-xs">error</span>
                    @else
                        <span class="bg-gray-800 text-gray-600 border border-gray-700 px-2 py-0.5 rounded text-xs">{{ $scan->status }}</span>
                    @endif
                    <span class="text-gray-600 text-xs" x-text="open ? '▲' : '▼'">▼</span>
                </span>
            </button>

            {{-- Conditions Breakdown --}}
            <div x-show="open" x-cloak class="border-t border-gray-800">

                @if($scan->signals->isNotEmpty())
                <div class="px-4 py-3 border-b border-gray-800/50">
                    <div class="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wider">Signals Found</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($scan->signals as $signal)
                        <a href="{{ route('signals.show', $signal) }}"
                           class="bg-emerald-500/10 border border-emerald-500/30 rounded px-3 py-1.5 text-xs hover:bg-emerald-500/20">
                            <span class="text-emerald-400 font-semibold">{{ $signal->entry_type }}</span>
                            <span class="text-gray-400 ml-2">{{ $signal->timeframe }}</span>
                            <span class="text-gray-300 ml-2">@ {{ number_format($signal->entry_price, 4) }}</span>
                            <span class="text-emerald-600 ml-2">detail →</span>
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif

                @if($scan->conditions_json && count($scan->conditions_json) > 0)
                @foreach($scan->conditions_json as $candle)
                <div class="border-b border-gray-800/30 last:border-0">
                    <div class="px-4 py-2 bg-gray-800/20 flex items-center gap-4 text-xs">
                        <span class="text-gray-400"><x-timestamp :value="$candle['candle_time'] ?? null" format="Y-m-d H:i" /></span>
                        <span class="text-gray-600">{{ $candle['candles_ago'] ?? 0 }} candle(s) ago</span>
                        @if(isset($candle['signal']))
                            <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2 py-0.5 rounded">
                                ✓ {{ $candle['signal'] }}
                            </span>
                        @else
                            <span class="bg-gray-800 text-gray-500 border border-gray-700 px-2 py-0.5 rounded">No Setup</span>
                        @endif
                        @if(isset($candle['sl_dist_pct']))
                        <span class="text-gray-600">SL dist: {{ number_format($candle['sl_dist_pct'], 2) }}%</span>
                        @endif
                    </div>

                    <div class="px-4 py-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if(isset($candle['pullback']))
                        <div>
                            <div class="text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">Pullback</div>
                            <div class="space-y-1">
                                @foreach($candle['pullback'] as $name => $cond)
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="w-4 h-4 rounded flex items-center justify-center text-xs font-bold
                                        {{ $cond['pass'] ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ $cond['pass'] ? '✓' : '✗' }}
                                    </span>
                                    <span class="text-gray-400">{{ str_replace('_', ' ', $name) }}</span>
                                    @if(isset($cond['value']))
                                    <span class="text-gray-600 ml-auto">{{ $cond['value'] }}</span>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        @if(isset($candle['awakening']))
                        <div>
                            <div class="text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">Awakening</div>
                            <div class="space-y-1">
                                @foreach($candle['awakening'] as $name => $cond)
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="w-4 h-4 rounded flex items-center justify-center text-xs font-bold
                                        {{ $cond['pass'] ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ $cond['pass'] ? '✓' : '✗' }}
                                    </span>
                                    <span class="text-gray-400">{{ str_replace('_', ' ', $name) }}</span>
                                    @if(isset($cond['value']))
                                    <span class="text-gray-600 ml-auto">{{ $cond['value'] }}</span>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </div>

                    @if(isset($candle['ltf']) || isset($candle['htf']))
                    <div class="px-4 pb-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if(isset($candle['ltf']))
                        <div class="bg-gray-800/50 rounded p-3 text-xs">
                            <div class="text-gray-600 mb-2 font-semibold">LTF Alligator</div>
                            <div class="grid grid-cols-3 gap-1 text-gray-400">
                                <div>Jaw: <span class="text-gray-300">{{ number_format($candle['ltf']['jaw_off'], 6) }}</span></div>
                                <div>Teeth: <span class="text-gray-300">{{ number_format($candle['ltf']['teeth_off'], 6) }}</span></div>
                                <div>Lips: <span class="text-gray-300">{{ number_format($candle['ltf']['lips_off'], 6) }}</span></div>
                            </div>
                        </div>
                        @endif
                        @if(isset($candle['htf']))
                        <div class="bg-gray-800/50 rounded p-3 text-xs">
                            <div class="text-gray-600 mb-2 font-semibold">HTF Alligator</div>
                            <div class="grid grid-cols-3 gap-1 text-gray-400">
                                <div>Jaw: <span class="text-gray-300">{{ number_format($candle['htf']['jaw_off'], 6) }}</span></div>
                                <div>Teeth: <span class="text-gray-300">{{ number_format($candle['htf']['teeth_off'], 6) }}</span></div>
                                <div>Lips: <span class="text-gray-300">{{ number_format($candle['htf']['lips_off'], 6) }}</span></div>
                            </div>
                        </div>
                        @endif
                    </div>
                    @endif

                </div>
                @endforeach
                @else
                <div class="px-4 py-4 text-xs text-gray-600">No condition data recorded for this scan.</div>
                @endif

            </div>
        </div>
        @empty
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-8 text-center text-gray-600 text-sm">
            No scans found.
        </div>
        @endforelse
    </div>

    @if($scans->hasPages())
    <div class="mt-4">
        {{ $scans->links() }}
    </div>
    @endif

</x-layouts.app>
