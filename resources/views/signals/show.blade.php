<x-layouts.app title="Signal #{{ $signal->id }}">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-lg font-bold text-gray-200 tracking-wide">
                {{ $signal->pair }} · {{ $signal->timeframe }} · {{ $signal->entry_type }}
            </h1>
            <p class="text-xs text-gray-600 mt-0.5">Signal #{{ $signal->id }} · <x-timestamp :value="$signal->candle_time" format="M d, Y g:i A" /> {{ now(config('app.timezone'))->format('T') }}</p>
        </div>
        <a href="{{ route('signals.index') }}" class="text-xs text-emerald-500 hover:text-emerald-400">← All Signals</a>
    </div>

    @if(session('success'))
    <div class="border border-emerald-500/20 bg-emerald-500/5 rounded px-3 py-2 mb-4 text-emerald-400 text-xs">
        ✓ {{ session('success') }}
    </div>
    @endif

    @if(in_array($signal->status, ['active', 'tp1_hit']))
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-4 mb-6"
         x-data="{
            selected: '',
            price: '',
            presets: {
                tp1_hit: '{{ $signal->tp1_price ? number_format((float)$signal->tp1_price, 8, '.', '') : '' }}',
                tp2_hit: '{{ $signal->tp2_price ? number_format((float)$signal->tp2_price, 8, '.', '') : '' }}',
                sl_hit:  '{{ $signal->sl_price  ? number_format((float)$signal->sl_price,  8, '.', '') : '' }}',
            },
            select(val) { this.selected = val; this.price = this.presets[val] || ''; }
         }">
        <h2 class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wider">Manual Close</h2>
        <form method="POST" action="{{ route('signals.close', $signal) }}">
            @csrf
            <input type="hidden" name="status" :value="selected">

            <div class="flex flex-wrap gap-2 mb-3">
                @if($signal->status === 'active' && $signal->tp1_price)
                <button type="button" @click="select('tp1_hit')"
                        :class="selected === 'tp1_hit' ? 'border-emerald-500/60 bg-emerald-500/15 text-emerald-400' : 'border-gray-700 text-gray-500 hover:border-gray-600 hover:text-gray-300'"
                        class="px-3 py-1.5 rounded border text-xs font-medium transition-colors">
                    TP1 Hit @ {{ number_format((float)$signal->tp1_price, 6) }}
                </button>
                @endif
                @if($signal->tp2_price && in_array($signal->status, ['active', 'tp1_hit']))
                <button type="button" @click="select('tp2_hit')"
                        :class="selected === 'tp2_hit' ? 'border-emerald-500/60 bg-emerald-500/15 text-emerald-400' : 'border-gray-700 text-gray-500 hover:border-gray-600 hover:text-gray-300'"
                        class="px-3 py-1.5 rounded border text-xs font-medium transition-colors">
                    TP2 Hit @ {{ number_format((float)$signal->tp2_price, 6) }}
                </button>
                @endif
                @if($signal->sl_price)
                <button type="button" @click="select('sl_hit')"
                        :class="selected === 'sl_hit' ? 'border-red-500/60 bg-red-500/10 text-red-400' : 'border-gray-700 text-gray-500 hover:border-gray-600 hover:text-gray-300'"
                        class="px-3 py-1.5 rounded border text-xs font-medium transition-colors">
                    SL Hit @ {{ number_format((float)$signal->sl_price, 6) }}
                </button>
                @endif
            </div>

            <div x-show="selected" x-cloak class="flex items-center gap-2">
                <input type="number" name="exit_price" x-model="price"
                       step="any" placeholder="Exit price"
                       class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-200 focus:outline-none focus:border-gray-500 w-44">
                <button type="submit"
                        :disabled="!price"
                        :class="!price ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-700'"
                        class="bg-gray-800 border border-gray-700 text-gray-300 px-4 py-1.5 rounded text-xs font-medium transition-colors">
                    Confirm
                </button>
                <button type="button" @click="selected = ''; price = ''"
                        class="text-xs text-gray-600 hover:text-gray-400 transition-colors">Cancel</button>
            </div>

            @error('exit_price')
            <p class="text-red-400 text-[10px] mt-1">{{ $message }}</p>
            @enderror
        </form>
    </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-lg mb-6 overflow-hidden">
        <div class="px-4 py-2.5 border-b border-gray-800 flex items-center justify-between">
            <span class="text-xs text-gray-500 font-semibold uppercase tracking-wider">{{ $signal->timeframe }} Chart · Alligator</span>
            <div class="flex items-center gap-3">
                <button id="chart-log-toggle"
                        class="text-[10px] px-2 py-0.5 rounded border border-gray-700 text-gray-600 hover:border-gray-500 hover:text-gray-400 transition-colors">
                    Log
                </button>
                <span class="text-[10px] text-gray-700">{{ $signal->pairScan?->exchange }}</span>
            </div>
        </div>
        <div id="signal-chart" class="w-full relative" style="height:380px;">
            <div id="chart-loading" class="absolute inset-0 flex items-center justify-center text-xs text-gray-600">
                Loading chart…
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        {{-- Entry Details --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
            <h2 class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wider">Entry</h2>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Status</span>
                    <x-signal-status :status="$signal->status" />
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Entry Price</span>
                    <span class="text-white font-semibold">{{ number_format($signal->entry_price, 6) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Stop Loss</span>
                    <span class="text-red-400">{{ $signal->sl_price ? number_format($signal->sl_price, 6) : '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">TP1</span>
                    <span class="text-emerald-400">{{ $signal->tp1_price ? number_format($signal->tp1_price, 6) : '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">TP2</span>
                    <span class="text-emerald-300">{{ $signal->tp2_price ? number_format($signal->tp2_price, 6) : '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Risk %</span>
                    <span class="text-yellow-400">{{ number_format($signal->risk_pct, 3) }}%</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Candles Ago</span>
                    <span class="text-gray-400">{{ $signal->candles_ago }}</span>
                </div>
            </div>
        </div>

        {{-- Screener Context --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
            <h2 class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wider">Screener Context</h2>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Strategy</span>
                    <span class="text-gray-300">{{ $signal->strategy }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Screener Score</span>
                    <span class="text-yellow-400">{{ number_format($signal->screener_score, 4) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-500">Confluence</span>
                    <div class="flex gap-0.5">
                        @foreach(explode(' ', $signal->confluence) as $tf)
                            @if($tf)
                            <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-1.5 py-0.5 rounded text-xs">{{ $tf }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
                @if($signal->pairScan?->screenerRun)
                <div class="flex justify-between">
                    <span class="text-gray-500">Run #</span>
                    <span class="text-gray-400">{{ $signal->pairScan->screenerRun->id }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Exchange</span>
                    <span class="text-gray-400">{{ $signal->pairScan->exchange }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Outcome --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
            <h2 class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wider">Outcome</h2>
            @if($signal->outcome)
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Result</span>
                    <x-signal-status :status="$signal->outcome->status" />
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Exit Price</span>
                    <span class="text-gray-300">{{ $signal->outcome->exit_price ? number_format($signal->outcome->exit_price, 6) : '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">PnL %</span>
                    @php $pnl = $signal->outcome->pnl_pct; @endphp
                    <span class="{{ $pnl > 0 ? 'text-emerald-400' : ($pnl < 0 ? 'text-red-400' : 'text-gray-400') }}">
                        {{ $pnl !== null ? ($pnl > 0 ? '+' : '') . number_format($pnl, 2) . '%' : '—' }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">PnL R</span>
                    <span class="text-gray-400">{{ $signal->outcome->pnl_r ? number_format($signal->outcome->pnl_r, 2).'R' : '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Candles to Exit</span>
                    <span class="text-gray-400">{{ $signal->outcome->candles_to_exit ?? '—' }}</span>
                </div>
            </div>
            @else
            <p class="text-xs text-gray-600">No outcome recorded yet.</p>
            @endif
        </div>

    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
    <script>
    (function () {
        var el = document.getElementById('signal-chart');
        var loading = document.getElementById('chart-loading');
        if (!el) return;

        fetch('{{ route('signals.candles', $signal) }}')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.candles || !data.candles.length) {
                    loading.textContent = 'No candle data available.';
                    return;
                }
                loading.remove();

                var chart = LightweightCharts.createChart(el, {
                    autoSize: true,
                    layout: { background: { color: '#111827' }, textColor: '#9ca3af' },
                    grid: { vertLines: { color: '#1f2937' }, horzLines: { color: '#1f2937' } },
                    crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
                    rightPriceScale: { borderColor: '#374151' },
                    timeScale: { borderColor: '#374151', timeVisible: true, secondsVisible: false },
                    handleScroll: true,
                    handleScale: true,
                });

                var cs = chart.addCandlestickSeries({
                    upColor: '#10b981', downColor: '#ef4444',
                    borderUpColor: '#10b981', borderDownColor: '#ef4444',
                    wickUpColor: '#10b981', wickDownColor: '#ef4444',
                });
                cs.setData(data.candles.map(function (c) {
                    return { time: c.t, open: c.o, high: c.h, low: c.l, close: c.c };
                }));

                if (data.jaw && data.jaw.length)
                    chart.addLineSeries({ color: '#3b82f6', lineWidth: 1.5, title: 'Jaw', lastValueVisible: false, priceLineVisible: false })
                        .setData(data.jaw.map(function (p) { return { time: p.t, value: p.v }; }));
                if (data.teeth && data.teeth.length)
                    chart.addLineSeries({ color: '#ef4444', lineWidth: 1.5, title: 'Teeth', lastValueVisible: false, priceLineVisible: false })
                        .setData(data.teeth.map(function (p) { return { time: p.t, value: p.v }; }));
                if (data.lips && data.lips.length)
                    chart.addLineSeries({ color: '#22c55e', lineWidth: 1.5, title: 'Lips', lastValueVisible: false, priceLineVisible: false })
                        .setData(data.lips.map(function (p) { return { time: p.t, value: p.v }; }));

                var sig = data.signal || {};
                if (sig.entry) cs.createPriceLine({ price: sig.entry, color: '#d1d5db', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'Entry', axisLabelVisible: true });
                if (sig.sl)    cs.createPriceLine({ price: sig.sl,    color: '#ef4444', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'SL',    axisLabelVisible: true });
                if (sig.tp1)   cs.createPriceLine({ price: sig.tp1,   color: '#10b981', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'TP1',   axisLabelVisible: true });
                if (sig.tp2)   cs.createPriceLine({ price: sig.tp2,   color: '#34d399', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'TP2',   axisLabelVisible: true });

                var markers = [];
                if (sig.candle_time) {
                    markers.push({ time: sig.candle_time, position: 'belowBar', color: '#fbbf24', shape: 'arrowUp', text: sig.entry_type || 'Entry' });
                }
                if (sig.exit_time) {
                    var isWin = sig.status === 'tp1_hit' || sig.status === 'tp2_hit';
                    markers.push({ time: sig.exit_time, position: 'aboveBar', color: isWin ? '#10b981' : '#ef4444', shape: 'arrowDown', text: isWin ? 'TP' : 'SL' });
                }
                if (markers.length) cs.setMarkers(markers);

                chart.timeScale().fitContent();
                chart.timeScale().applyOptions({ rightOffset: 3 });

                var logBtn = document.getElementById('chart-log-toggle');
                var logMode = false;
                logBtn.addEventListener('click', function () {
                    logMode = !logMode;
                    chart.priceScale('right').applyOptions({
                        mode: logMode ? 1 : 0,
                    });
                    logBtn.classList.toggle('border-blue-500', logMode);
                    logBtn.classList.toggle('text-blue-400', logMode);
                    logBtn.classList.toggle('border-gray-700', !logMode);
                    logBtn.classList.toggle('text-gray-600', !logMode);
                });
            })
            .catch(function () { loading.textContent = 'Failed to load chart.'; });
    })();
    </script>
    @endpush

    {{-- Conditions Breakdown --}}
    @if($signal->conditions_json && count($signal->conditions_json) > 0)
    <div class="bg-gray-900 border border-gray-800 rounded-lg">
        <div class="px-4 py-3 border-b border-gray-800">
            <h2 class="text-sm font-semibold text-gray-300">Condition Breakdown</h2>
        </div>

        @foreach($signal->conditions_json as $candle)
        <div class="border-b border-gray-800/50 last:border-0">
            <div class="px-4 py-2 bg-gray-800/30 flex items-center gap-4 text-xs">
                <span class="text-gray-400">{{ $candle['candle_time'] ?? '' }}</span>
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

                {{-- Pullback Conditions --}}
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

                {{-- Awakening Conditions --}}
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

            {{-- LTF / HTF Values --}}
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
    </div>
    @endif

</x-layouts.app>
