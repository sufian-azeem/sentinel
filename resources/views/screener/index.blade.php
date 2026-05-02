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
                    Run #{{ $run->id }} · <x-timestamp :value="$run->started_at" format="M d, Y g:i A" /> {{ now(config('app.timezone'))->format('T') }} ·
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
    <script>
    window._fav = {
        url: '{{ url('screener/favorites') }}',
        csrf: '{{ csrf_token() }}',
        initial: @json(array_keys($favorites)),
    };
    document.addEventListener('alpine:init', () => {
        Alpine.data('screenerFav', () => ({
            favorites: window._fav.initial,
            favOnly: false,
            viewMode: localStorage.getItem('screener_view') || 'table',
            chartTf: localStorage.getItem('screener_chart_tf') || 'auto',
            setView(m) {
                this.viewMode = m;
                localStorage.setItem('screener_view', m);
                if (m === 'grid') this.$nextTick(() => window._initCharts && window._initCharts());
            },
            setChartTf(tf) {
                this.chartTf = tf;
                localStorage.setItem('screener_chart_tf', tf);
                this.$nextTick(() => {
                    document.querySelectorAll('.chart-mini').forEach(el => {
                        if (el._lwChart) { el._lwChart.remove(); el._lwChart = null; }
                        el.removeAttribute('data-ready');
                    });
                    window._initCharts && window._initCharts(tf);
                });
            },
            async toggle(pair) {
                const r = await fetch(window._fav.url + '/' + encodeURIComponent(pair), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': window._fav.csrf, 'Accept': 'application/json' },
                });
                const d = await r.json();
                this.favorites = d.favorited
                    ? [...this.favorites, pair]
                    : this.favorites.filter(p => p !== pair);
            },
            isFav(pair) { return this.favorites.includes(pair); },
        }));
    });
    </script>

    <div class="bg-gray-900 border border-gray-800 rounded-lg mb-6" x-data="screenerFav()">
        <div class="px-4 py-3 border-b border-gray-800 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-emerald-400">✓ Qualified Pairs ({{ $qualified->count() }})</h2>
            <div class="flex items-center gap-2">
                <button @click="favOnly = !favOnly"
                        :class="favOnly ? 'text-yellow-400 border-yellow-500/40 bg-yellow-500/10' : 'text-gray-600 border-gray-700 hover:text-gray-400'"
                        class="flex items-center gap-1 border rounded px-2 py-1 text-[10px] transition-colors">
                    <span>★</span>
                    <span x-text="favOnly ? 'Favorites' : 'All'"></span>
                </button>
                <div x-show="viewMode === 'grid'" class="flex border border-gray-700 rounded overflow-hidden text-[10px]">
                    @foreach(['auto' => 'Auto', '15M' => '15M', '1H' => '1H', '4H' => '4H'] as $tf => $label)
                    <button @click="setChartTf('{{ $tf }}')"
                            :class="chartTf === '{{ $tf }}' ? 'bg-gray-700 text-gray-200' : 'text-gray-600 hover:text-gray-400'"
                            class="px-2 py-1 {{ !$loop->first ? 'border-l border-gray-700' : '' }} transition-colors">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex border border-gray-700 rounded overflow-hidden text-[10px]">
                    <button @click="setView('table')"
                            :class="viewMode === 'table' ? 'bg-gray-700 text-gray-200' : 'text-gray-600 hover:text-gray-400'"
                            class="px-2 py-1 transition-colors">⊞ Table</button>
                    <button @click="setView('grid')"
                            :class="viewMode === 'grid' ? 'bg-gray-700 text-gray-200' : 'text-gray-600 hover:text-gray-400'"
                            class="px-2 py-1 border-l border-gray-700 transition-colors">⊟ Grid</button>
                </div>
            </div>
        </div>
        {{-- Table view --}}
        <div x-show="viewMode === 'table'" x-cloak class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-gray-600 border-b border-gray-800">
                    <th class="text-left px-4 py-2 w-8"></th>
                    <th class="text-left px-4 py-2">#</th>
                    <th class="text-left px-4 py-2">Pair</th>
                    <th class="text-right px-4 py-2">Price</th>
                    <th class="text-right px-4 py-2">Score</th>
                    <th class="text-right px-4 py-2">rVol</th>
                    <th class="text-right px-4 py-2">Vol 1H</th>
                    <th class="text-left px-4 py-2">TF Breakdown</th>
                    <th class="text-left px-4 py-2">Scan</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($qualified as $i => $r)
                <tr x-show="!favOnly || isFav('{{ $r->pair }}')"
                    :class="isFav('{{ $r->pair }}') ? 'border-b border-gray-800/50 bg-yellow-500/[0.03] hover:bg-yellow-500/[0.06]' : 'border-b border-gray-800/50 hover:bg-gray-800/30'">
                    <td class="px-4 py-2">
                        <button @click="toggle('{{ $r->pair }}')" type="button"
                                :class="isFav('{{ $r->pair }}') ? 'text-yellow-400' : 'text-gray-700 hover:text-gray-500'"
                                class="transition-colors leading-none text-sm">
                            <span x-text="isFav('{{ $r->pair }}') ? '★' : '☆'">☆</span>
                        </button>
                    </td>
                    <td class="px-4 py-2 text-gray-600">{{ $i + 1 }}</td>
                    <td class="px-4 py-2 font-semibold">
                        @php
                            $exchange = $run->filters_json['exchange'] ?? 'binance';
                        @endphp
                        <x-pair-link :pair="$r->pair" :exchange="$exchange" :tf-data="$r->tf_data_json" />
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
                        @php $scans = $r->pairScans->keyBy('timeframe'); @endphp
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
                    <td class="px-4 py-2">
                        <form method="POST" action="{{ route('scans.pairs.remove', $r) }}" onsubmit="return confirm('Remove {{ $r->pair }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="border border-red-800/60 text-red-700 hover:bg-red-500/20 hover:text-red-400 hover:border-red-600 transition-colors text-xs px-1.5 py-0.5 rounded" title="Remove pair">✕</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        {{-- Grid view --}}
        <div x-show="viewMode === 'grid'" x-cloak class="p-4">
            <div class="grid grid-cols-3 gap-3">
                @foreach($qualified as $r)
                @php
                    $exchange   = $run->filters_json['exchange'] ?? 'binance';
                    $scans      = $r->pairScans->keyBy('timeframe');
                    $autoTf     = $r->alligator_tf ?? '1H';
                    // Build a map of all available snapshots keyed by TF
                    $snapshots  = [];
                    foreach (['15M', '1H', '4H'] as $tf) {
                        $snap = $r->pairScans->where('timeframe', $tf)->whereNotNull('chart_snapshot_json')->sortByDesc('id')->first()?->chart_snapshot_json;
                        if ($snap) $snapshots[$tf] = $snap;
                    }
                    $snapshots['auto'] = $snapshots[$autoTf] ?? reset($snapshots) ?: null;
                    $alligator  = $r->tf_data_json[$r->alligator_tf]['alligator'] ?? null;
                    $isBullish  = $alligator['bullish'] ?? false;
                    $hasSignal  = $scans->contains(fn($s) => $s->signals->isNotEmpty());
                    $signalScan = $scans->first(fn($s) => $s->signals->isNotEmpty());
                @endphp
                <div x-show="!favOnly || isFav('{{ $r->pair }}')"
                     class="bg-gray-800/50 border border-gray-700/50 rounded-lg overflow-hidden hover:border-gray-600/70 transition-colors">
                    {{-- Card header --}}
                    <div class="px-3 py-2 flex items-center justify-between">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <button @click="toggle('{{ $r->pair }}')" type="button"
                                    :class="isFav('{{ $r->pair }}') ? 'text-yellow-400' : 'text-gray-700 hover:text-gray-500'"
                                    class="transition-colors leading-none shrink-0">★</button>
                            <x-pair-link :pair="$r->pair" :exchange="$exchange" :tf-data="$r->tf_data_json"
                                         class="font-semibold text-xs text-white hover:text-emerald-400 truncate" />
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            @if($hasSignal && $signalScan)
                            <a href="{{ route('signals.show', $signalScan->signals->first()) }}"
                               class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-1 py-0.5 rounded text-[9px] hover:bg-emerald-500/30">
                                ✓ {{ $signalScan->timeframe }}
                            </a>
                            @elseif($isBullish)
                            <span class="bg-emerald-500/10 text-emerald-500 border border-emerald-500/30 px-1 py-0.5 rounded text-[9px]">
                                ▲ {{ $autoTf }}
                            </span>
                            @else
                            <span class="text-gray-600 text-[9px]">{{ $r->alligator_tf }}</span>
                            @endif
                            <form method="POST" action="{{ route('scans.pairs.remove', $r) }}" onsubmit="return confirm('Remove {{ $r->pair }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-gray-700 hover:text-red-500 transition-colors leading-none text-[10px]" title="Remove">✕</button>
                            </form>
                        </div>
                    </div>

                    {{-- Mini chart --}}
                    @if(!empty($snapshots))
                    <div class="chart-mini" style="height:220px;"
                         data-snapshots='@json($snapshots)'
                         data-auto-tf="{{ $autoTf }}"></div>
                    @else
                    <div style="height:220px;" class="flex items-center justify-center text-gray-700 text-[10px]">no chart data</div>
                    @endif

                    {{-- Card footer --}}
                    <div class="px-3 py-1.5 border-t border-gray-700/50 flex items-center justify-between text-[10px]">
                        <span class="text-yellow-400">{{ number_format($r->score, 3) }}</span>
                        <span class="text-gray-600">rVol {{ number_format($r->rvol, 1) }}</span>
                        <span class="text-gray-500">{{ number_format($r->price, 4) }}</span>
                    </div>
                </div>
                @endforeach
            </div>
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

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
function _buildChart(el, data) {
    var chart = LightweightCharts.createChart(el, {
        autoSize: true,
        layout: { background: { color: 'transparent' }, textColor: '#4b5563' },
        grid: { vertLines: { visible: false }, horzLines: { visible: false } },
        crosshair: { mode: LightweightCharts.CrosshairMode.Hidden },
        rightPriceScale: { visible: false },
        leftPriceScale: { visible: false },
        timeScale: { visible: false, borderVisible: false },
        handleScroll: false,
        handleScale: false,
    });
    var cs = chart.addCandlestickSeries({
        upColor: '#10b981', downColor: '#ef4444',
        borderUpColor: '#10b981', borderDownColor: '#ef4444',
        wickUpColor: '#10b981', wickDownColor: '#ef4444',
    });
    cs.setData(data.candles.map(function (c) { return { time: c.t, open: c.o, high: c.h, low: c.l, close: c.c }; }));
    if (data.jaw && data.jaw.length)
        chart.addLineSeries({ color: '#3b82f6', lineWidth: 1, lastValueVisible: false, priceLineVisible: false })
            .setData(data.jaw.map(function (p) { return { time: p.t, value: p.v }; }));
    if (data.teeth && data.teeth.length)
        chart.addLineSeries({ color: '#ef4444', lineWidth: 1, lastValueVisible: false, priceLineVisible: false })
            .setData(data.teeth.map(function (p) { return { time: p.t, value: p.v }; }));
    if (data.lips && data.lips.length)
        chart.addLineSeries({ color: '#22c55e', lineWidth: 1, lastValueVisible: false, priceLineVisible: false })
            .setData(data.lips.map(function (p) { return { time: p.t, value: p.v }; }));
    chart.timeScale().fitContent();
    chart.timeScale().applyOptions({ rightOffset: 3 });
    el._lwChart = chart;
}

window._initCharts = function (selectedTf) {
    selectedTf = selectedTf || localStorage.getItem('screener_chart_tf') || 'auto';

    document.querySelectorAll('.chart-mini[data-snapshots]:not([data-ready])').forEach(function (el) {
        var snapshots;
        try { snapshots = JSON.parse(el.dataset.snapshots); } catch (e) { return; }

        var tf = selectedTf;
        var data = snapshots[tf] || snapshots['auto'] || snapshots['15M'] || snapshots['1H'] || snapshots['4H'];
        if (!data || !data.candles || !data.candles.length) return;

        el.setAttribute('data-ready', '1');
        _buildChart(el, data);
    });
};

// Auto-init if starting in grid mode
document.addEventListener('DOMContentLoaded', function () {
    if (localStorage.getItem('screener_view') === 'grid') {
        setTimeout(window._initCharts, 200);
    }
});
</script>
@endpush

</x-layouts.app>
