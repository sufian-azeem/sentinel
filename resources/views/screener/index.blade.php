<x-layouts.app title="Screener">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-lg font-bold text-gray-200 tracking-wide">Screener</h1>
            @if($run)
            <div class="flex items-center gap-2 mt-1.5">
                @if($run->filters_json['exchange'] ?? null)
                <x-exchange-badge :exchange="$run->filters_json['exchange']" />
                @endif
                <span class="text-xs text-gray-600">
                    Run #{{ $run->id }} · {{ $run->started_at->format('M d, Y g:i A') }} PKT ·
                    {{ $run->total_matched }} matched / {{ $run->total_scanned }} scanned
                </span>
            </div>
            @endif
        </div>
        <div class="flex items-center gap-4">
            @if($run)
            <a href="{{ route('scans.index', ['run' => $run->id]) }}" class="text-xs text-gray-500 hover:text-gray-300">Scans →</a>
            @endif
            <a href="{{ route('screener.history') }}" class="text-xs text-emerald-500 hover:text-emerald-400">History →</a>
        </div>
    </div>

    @if($results->isEmpty())
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-8 text-center text-gray-600 text-sm">
            No screener data yet. Run: <code class="text-emerald-500">php artisan trading:run-screener --file=python/data.json</code>
        </div>
    @else

    {{-- Qualified Pairs --}}
    @php $qualified = $results->where('qualified', true); @endphp
    @if($qualified->isNotEmpty())
    <div class="bg-gray-900 border border-gray-800 rounded-lg mb-6">
        <div class="px-4 py-3 border-b border-gray-800">
            <h2 class="text-sm font-semibold text-emerald-400">✓ Qualified Pairs ({{ $qualified->count() }})</h2>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-gray-600 border-b border-gray-800">
                    <th class="text-left px-4 py-2">#</th>
                    <th class="text-left px-4 py-2">Pair</th>
                    <th class="text-right px-4 py-2">Price</th>
                    <th class="text-right px-4 py-2">Score</th>
                    <th class="text-right px-4 py-2">rVol</th>
                    <th class="text-right px-4 py-2">Vol 1H</th>
                    <th class="text-left px-4 py-2">TF Breakdown</th>
                    <th class="text-left px-4 py-2">Scan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($qualified as $i => $r)
                <tr class="border-b border-gray-800/50 hover:bg-gray-800/30">
                    <td class="px-4 py-2 text-gray-600">{{ $i + 1 }}</td>
                    <td class="px-4 py-2 font-semibold">
                        @php
                            $exchange = $run->filters_json['exchange'] ?? 'binance';
                            $baseAsset = Str::before($r->pair, '/');
                            $usedMexc = collect($r->tf_data_json ?? [])->contains(fn($td) => ($td['exchange'] ?? null) === 'mexc');
                            $tvExchange = $usedMexc ? 'MEXC' : 'BINANCE';
                            $pairUrl = $exchange === 'hyperliquid'
                                ? 'https://app.hyperliquid.xyz/trade/'.$baseAsset
                                : 'https://www.tradingview.com/chart/?symbol='.$tvExchange.':'.str_replace('/', '', $r->pair);
                        @endphp
                        <a href="{{ $pairUrl }}" target="_blank" class="text-white hover:text-emerald-400">{{ $r->pair }}</a>
                    </td>
                    <td class="px-4 py-2 text-right text-gray-300">{{ number_format($r->price, 4) }}</td>
                    <td class="px-4 py-2 text-right text-yellow-400">{{ number_format($r->score, 3) }}</td>
                    <td class="px-4 py-2 text-right text-gray-300">{{ number_format($r->rvol, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-400">
                        @php $vol = $r->tf_data_json['1H']['volume_usd'] ?? 0; @endphp
                        {{ $vol >= 1_000_000 ? '$'.number_format($vol/1_000_000,1).'M' : '$'.number_format($vol/1000,0).'K' }}
                    </td>
                    <td class="px-4 py-2">
                        <div class="flex gap-0.5">
                        @foreach(['5M','15M','1H','4H','8H','12H','1D'] as $tf)
                            @php $tfData = $r->tf_data_json[$tf] ?? null; @endphp
                            @if($tfData)
                            <span class="px-1 py-0.5 rounded text-xs {{ $tfData['bullish'] ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-800 text-gray-600' }}"
                                  title="{{ $tf }}: {{ number_format($tfData['change_pct'], 2) }}%">
                                {{ $tf }}
                            </span>
                            @endif
                        @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-2">
                        @php $scans = $r->signalScans->keyBy('timeframe'); @endphp
                        @if($scans->isEmpty())
                            <span class="text-gray-700">—</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                            @foreach(['15M','1H','4H'] as $tf)
                                @php $scan = $scans->get($tf); @endphp
                                @if($scan)
                                    @php
                                        $alligator = $r->tf_data_json[$tf]['alligator'] ?? null;
                                        $alligatorBullish = $alligator['bullish'] ?? null;
                                        $bestCandle = collect($scan->conditions_json ?? [])
                                            ->map(fn($c) => [
                                                'pb_pass'  => collect($c['conditions']['pullback'] ?? [])->where('pass', true)->count(),
                                                'pb_total' => count($c['conditions']['pullback'] ?? []),
                                                'aw_pass'  => collect($c['conditions']['awakening'] ?? [])->where('pass', true)->count(),
                                                'aw_total' => count($c['conditions']['awakening'] ?? []),
                                            ])
                                            ->sortByDesc(fn($x) => $x['pb_pass'] + $x['aw_pass'])
                                            ->first();
                                        $alligatorLabel = $alligator
                                            ? ($alligatorBullish ? '▲' : '▼')
                                            : '';
                                        $alligatorTooltip = $alligator
                                            ? ($alligatorBullish ? 'Alligator bullish' : 'Alligator bearish').' · jaw='.number_format($alligator['jaw'], 6).' teeth='.number_format($alligator['teeth'], 6).' lips='.number_format($alligator['lips'], 6)
                                            : '';
                                        $conditionsTooltip = $bestCandle
                                            ? "PB {$bestCandle['pb_pass']}/{$bestCandle['pb_total']} · AW {$bestCandle['aw_pass']}/{$bestCandle['aw_total']}"
                                            : '';
                                        $tooltip = implode(' | ', array_filter([$alligatorTooltip, $conditionsTooltip, $scan->error_message]));
                                    @endphp
                                    @if($scan->signals->isNotEmpty())
                                        <a href="{{ route('signals.show', $scan->signals->first()) }}"
                                           class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-1.5 py-0.5 rounded text-xs hover:bg-emerald-500/30"
                                           title="{{ $tf }}: signal found{{ $alligatorTooltip ? ' · '.$alligatorTooltip : '' }}">
                                            ✓ {{ $tf }}
                                        </a>
                                    @elseif($scan->status === 'error')
                                        <span class="bg-red-500/20 text-red-400 border border-red-500/30 px-1.5 py-0.5 rounded text-xs"
                                              title="{{ $tf }}: {{ Str::before($scan->error_message ?? '', ':') }}">
                                            {{ $tf }}
                                        </span>
                                    @elseif($scan->status === 'skipped')
                                        <span class="bg-yellow-500/20 text-yellow-500 border border-yellow-500/30 px-1.5 py-0.5 rounded text-xs"
                                              title="{{ $tf }}: {{ Str::before($scan->error_message ?? 'skipped', ':') }}">
                                            {{ $tf }}
                                        </span>
                                    @elseif($alligatorBullish)
                                        <span class="bg-emerald-500/10 text-emerald-500 border border-emerald-500/40 px-1.5 py-0.5 rounded text-xs"
                                              title="{{ $tf }}: {{ $tooltip }}">
                                            {{ $tf }} {{ $alligatorLabel }}
                                        </span>
                                    @else
                                        <span class="bg-gray-800 text-gray-600 border border-gray-700/50 px-1.5 py-0.5 rounded text-xs"
                                              title="{{ $tf }}: {{ $tooltip }}">
                                            {{ $tf }} {{ $alligatorLabel }}
                                        </span>
                                    @endif
                                @endif
                            @endforeach
                            </div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif

    {{-- Disqualified Pairs --}}
    @php $disqualified = $results->where('qualified', false); @endphp
    @if($disqualified->isNotEmpty())
    <div class="bg-gray-900 border border-gray-800 rounded-lg" x-data="{ open: false }">
        <button @click="open = !open"
                class="w-full flex items-center justify-between px-4 py-3 border-b border-gray-800 hover:bg-gray-800/30">
            <h2 class="text-sm font-semibold text-gray-500">✗ Disqualified Pairs ({{ $disqualified->count() }})</h2>
            <span class="text-gray-600 text-xs" x-text="open ? '▲ hide' : '▼ show'">▼ show</span>
        </button>
        <div x-show="open" x-cloak>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-gray-600 border-b border-gray-800">
                    <th class="text-left px-4 py-2">Pair</th>
                    <th class="text-right px-4 py-2">Price</th>
                    <th class="text-right px-4 py-2">Vol 1H</th>
                    <th class="text-right px-4 py-2">rVol</th>
                    <th class="text-right px-4 py-2">BTC Corr</th>
                    <th class="text-right px-4 py-2">Bullish TFs</th>
                    <th class="text-left px-4 py-2">Reason</th>
                </tr>
            </thead>
            <tbody>
                @foreach($disqualified as $r)
                <tr class="border-b border-gray-800/30 hover:bg-gray-800/20">
                    <td class="px-4 py-2 text-gray-500">{{ $r->pair }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">{{ number_format($r->price, 4) }}</td>
                    <td class="px-4 py-2 text-right {{ ($r->filters_json['volume_1h']['pass'] ?? true) ? 'text-gray-500' : 'text-red-500' }}">
                        @php $vol = $r->filters_json['volume_1h']['value'] ?? 0; @endphp
                        {{ $vol >= 1_000_000 ? '$'.number_format($vol/1_000_000,1).'M' : '$'.number_format($vol/1000,0).'K' }}
                    </td>
                    <td class="px-4 py-2 text-right {{ ($r->filters_json['rvol_15m']['pass'] ?? true) ? 'text-gray-500' : 'text-red-500' }}">
                        {{ number_format($r->filters_json['rvol_15m']['value'] ?? 0, 2) }}
                    </td>
                    <td class="px-4 py-2 text-right {{ ($r->filters_json['btc_corr']['pass'] ?? true) ? 'text-gray-500' : 'text-red-500' }}">
                        {{ number_format($r->filters_json['btc_corr']['value'] ?? 0, 2) }}
                    </td>
                    <td class="px-4 py-2 text-right {{ ($r->filters_json['bullish_tfs']['pass'] ?? true) ? 'text-gray-500' : 'text-red-500' }}">
                        {{ $r->filters_json['bullish_tfs']['value'] ?? 0 }}
                        / {{ $r->filters_json['bullish_tfs']['threshold'] ?? 3 }}
                    </td>
                    <td class="px-4 py-2">
                        <span class="bg-red-500/20 text-red-400 border border-red-500/30 px-2 py-0.5 rounded text-xs">
                            {{ $r->disqualify_reason }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        </div>
    </div>
    @endif

    @endif

</x-layouts.app>
