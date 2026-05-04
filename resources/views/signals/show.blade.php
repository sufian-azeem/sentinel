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

    @if($signal->isActive())
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
                @if($signal->tp2_price && $signal->isActive())
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


    @foreach($signal->executedTrades->sortByDesc('id') as $trade)
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-4 mb-6 text-xs">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-400 uppercase tracking-wider">MEXC Trade</h2>
            <div class="flex items-center gap-2">
                @if($trade->breakeven_moved_at)
                <span class="px-2 py-0.5 rounded border border-amber-600/40 bg-amber-500/10 text-amber-400">BE Moved</span>
                @endif
                @php
                    $statusColors = match($trade->status) {
                        'open'      => 'border-emerald-600/40 bg-emerald-500/10 text-emerald-400',
                        'pending'   => 'border-yellow-600/40 bg-yellow-500/10 text-yellow-400',
                        'cancelled' => 'border-red-600/40 bg-red-500/10 text-red-400',
                        default     => 'border-gray-600/40 bg-gray-500/10 text-gray-400',
                    };
                @endphp
                <span class="px-2 py-0.5 rounded border {{ $statusColors }}">{{ strtoupper($trade->status) }}</span>
            </div>
        </div>

        {{-- Prices row --}}
        <div class="grid grid-cols-4 gap-3 mb-3 p-3 bg-gray-800/50 rounded">
            <div>
                <div class="text-gray-600 mb-0.5">Entry</div>
                <div class="text-gray-200 font-semibold font-mono">{{ number_format((float) $trade->entry_price, 6) }}</div>
            </div>
            <div>
                <div class="text-gray-600 mb-0.5">Stop Loss</div>
                <div class="text-red-400 font-semibold font-mono">{{ $trade->sl_price ? number_format((float) $trade->sl_price, 6) : '—' }}</div>
            </div>
            <div>
                <div class="text-gray-600 mb-0.5">TP1 <span class="text-gray-700">(70%)</span></div>
                <div class="text-emerald-400 font-semibold font-mono">{{ $trade->tp1_price ? number_format((float) $trade->tp1_price, 6) : '—' }}</div>
            </div>
            <div>
                <div class="text-gray-600 mb-0.5">TP2 <span class="text-gray-700">(30%)</span></div>
                <div class="text-emerald-300 font-semibold font-mono">{{ $trade->tp2_price ? number_format((float) $trade->tp2_price, 6) : '—' }}</div>
            </div>
        </div>

        {{-- Size row --}}
        <div class="flex gap-6 mb-3 text-gray-500">
            <div>Qty <span class="text-gray-300">{{ rtrim(number_format((float) $trade->quantity, 8), '0') }}</span></div>
            <div>Invested <span class="text-gray-300">${{ number_format((float) $trade->notional_usd, 2) }}</span></div>
            @if($trade->entry_filled_at)
            <div>Filled <span class="text-gray-300"><x-timestamp :value="$trade->entry_filled_at" format="M d, g:i A" /></span></div>
            @endif
        </div>

        {{-- Order IDs --}}
        <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-gray-600 border-t border-gray-800 pt-2">
            <div>Entry order <span class="font-mono text-gray-500">{{ $trade->exchange_order_id ?? '—' }}</span></div>
            <div>SL order <span class="font-mono text-gray-500">{{ $trade->sl_order_id ?? '—' }}</span></div>
            <div>TP1 order <span class="font-mono text-gray-500">{{ $trade->tp1_order_id ?? '—' }}</span></div>
            <div>TP2 order <span class="font-mono text-gray-500">{{ $trade->tp2_order_id ?? '—' }}</span></div>
            @if($trade->breakeven_moved_at)
            <div class="col-span-2 text-amber-600">Break-even moved <x-timestamp :value="$trade->breakeven_moved_at" format="M d, g:i A" /></div>
            @endif
            @if($trade->notes)
            <div class="col-span-2 text-red-500 mt-1">{{ $trade->notes }}</div>
            @endif
        </div>
    </div>
    @endforeach

    {{-- Chart + Execute sidebar --}}
    <div class="flex flex-col lg:flex-row gap-4 mb-6 lg:items-start">

        {{-- Chart --}}
        <div class="flex-1 min-w-0 bg-gray-900 border border-gray-800 rounded-lg overflow-hidden">
            <div class="px-4 py-2.5 border-b border-gray-800 flex items-center justify-between">
                <span class="text-xs text-gray-500 font-semibold uppercase tracking-wider">{{ $signal->timeframe }} Chart · Alligator</span>
                <div class="flex items-center gap-2">
                    <button id="chart-log-toggle"
                            class="text-[10px] px-2 py-0.5 rounded border border-gray-700 text-gray-600 hover:border-gray-500 hover:text-gray-400 transition-colors">
                        Log
                    </button>
                    <span class="text-[10px] text-gray-700">{{ $signal->pairScan?->exchange }}</span>
                </div>
            </div>
            <div id="signal-chart" class="w-full relative" style="height:760px;">
                <div id="chart-loading" class="absolute inset-0 flex items-center justify-center text-xs text-gray-600">
                    Loading chart…
                </div>
            </div>
        </div>

        @if($signal->isActive() && $signal->sl_price && $signal->executedTrades->where('status', 'open')->isEmpty())
        {{-- Execute panel --}}
        <div x-data="tradeExecutor()" class="lg:w-72 lg:flex-shrink-0 lg:sticky lg:top-4">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Execute on MEXC Spot</h2>
                    <span class="text-xs text-gray-500 flex items-center gap-1.5">
                        <span class="text-gray-600">Now</span>
                        <span x-text="entry ? entry.toPrecision(6) : '…'" class="text-gray-300 font-mono"></span>
                        <button @click="fetchPrice()" :class="priceLoading ? 'opacity-40 cursor-not-allowed' : 'hover:text-gray-300'" class="text-gray-600 transition-colors" title="Refresh price">↻</button>
                    </span>
                </div>

                <div class="mb-3">
                    <label class="text-xs text-gray-500 mb-1 block">Risk (USD)</label>
                    <input type="number" x-model.number="riskUsd" @input="calc()" min="1" step="0.5"
                           class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-gray-500">
                </div>

                <div class="mb-4">
                    <label class="text-xs text-gray-500 mb-1 block">Stop Loss</label>
                    <div class="flex gap-1.5">
                        <input type="number" x-model.number="sl" @input="calc()" step="any"
                               class="flex-1 min-w-0 bg-gray-800 border border-red-900/60 rounded px-3 py-2 text-sm text-red-300 focus:outline-none focus:border-red-600">
                        <button @click="pick('sl')"
                                :class="pickMode==='sl' ? 'border-red-500 text-red-400 bg-red-500/10' : 'border-gray-700 text-gray-600 hover:border-gray-500 hover:text-gray-400'"
                                class="px-2.5 rounded border transition-colors text-xs" title="Pick price from chart">⊕</button>
                    </div>
                </div>

                <div class="space-y-2 text-xs border-t border-gray-800 pt-3 mb-4">
                    <div class="flex justify-between text-gray-400">
                        <span>Investment</span>
                        <span class="text-gray-200 font-medium" x-text="fmt(notional) + ' USDT'"></span>
                    </div>
                    <div class="flex justify-between text-gray-400">
                        <span>Max risk</span>
                        <span class="text-red-400 font-medium" x-text="'−$' + fmt(riskUsd)"></span>
                    </div>
                    <div class="flex justify-between text-gray-400">
                        <span class="flex items-center gap-1.5">
                            <span class="text-gray-600">TP1</span>
                            <span class="font-mono text-emerald-500/70" x-text="tp1Price ? tp1Price.toPrecision(6) : '—'"></span>
                            <span class="px-1 rounded text-[10px] font-mono bg-emerald-900/40 text-emerald-500 border border-emerald-800/50">1:1</span>
                        </span>
                        <span class="text-emerald-400 font-medium" x-text="tp1Profit ? '+$' + fmt(tp1Profit) : '—'"></span>
                    </div>
                    <div class="flex justify-between text-gray-400">
                        <span class="flex items-center gap-1.5">
                            <span class="text-gray-600">TP2</span>
                            <span class="font-mono text-emerald-500/70" x-text="tp2Price ? tp2Price.toPrecision(6) : '—'"></span>
                            <span class="px-1 rounded text-[10px] font-mono bg-emerald-900/40 text-emerald-500 border border-emerald-800/50">1:2</span>
                        </span>
                        <span class="text-emerald-400 font-medium" x-text="tp2Profit ? '+$' + fmt(tp2Profit) : '—'"></span>
                    </div>
                    <div class="flex justify-between text-gray-400 border-t border-gray-800 pt-2">
                        <span>Quantity</span>
                        <span class="text-gray-300" x-text="qty.toFixed(4) + ' {{ Str::before($signal->pair, '/') ?: $signal->pair }}'"></span>
                    </div>
                </div>

                <div x-show="error" x-cloak class="text-red-400 text-xs mb-3" x-text="error"></div>

                <button @click="submit()" :disabled="loading"
                        :class="loading ? 'opacity-50 cursor-not-allowed' : 'hover:bg-emerald-600'"
                        class="w-full bg-emerald-700 text-white text-xs font-medium py-2 rounded transition-colors">
                    <span x-text="loading ? 'Placing orders…' : 'Confirm & Execute'"></span>
                </button>
            </div>
        </div>

        <script>
        function tradeExecutor() {
            return {
                loading: false, priceLoading: false, error: '',
                riskUsd: 10,
                notional: 0, tp1Profit: 0, tp2Profit: 0, tp1Price: null, tp2Price: null, qty: 0,
                pickMode: null,
                entry: {{ (float) $signal->entry_price }},
                sl:    {{ (float) $signal->sl_price }},
                fetchPrice() {
                    this.priceLoading = true;
                    fetch('https://api.mexc.com/api/v3/ticker/price?symbol={{ str_replace('/', '', $signal->pair) }}')
                        .then(r => r.json())
                        .then(d => { if (d.price) { this.entry = parseFloat(d.price); this.calc(); } })
                        .catch(() => {})
                        .finally(() => { this.priceLoading = false; });
                },
                priceDec() {
                    const s = this.entry.toString();
                    const dot = s.indexOf('.');
                    return dot >= 0 ? s.length - dot - 1 : 0;
                },
                roundPrice(p) { return parseFloat(p.toFixed(this.priceDec())); },
                init() {
                    this.calc();
                    this.fetchPrice();
                    window.addEventListener('chart-price-picked', (e) => {
                        if (e.detail.field === 'sl') this.sl = this.roundPrice(e.detail.price);
                        this.pickMode = null;
                        window.chartPickMode = null;
                        const el = document.getElementById('signal-chart');
                        if (el) el.style.cursor = '';
                        this.calc();
                    });
                },
                pick(field) {
                    this.pickMode = this.pickMode === field ? null : field;
                    window.chartPickMode = this.pickMode;
                    const el = document.getElementById('signal-chart');
                    if (el) el.style.cursor = this.pickMode ? 'crosshair' : '';
                },
                calc() {
                    const slDist = this.entry - this.sl;
                    if (slDist <= 0) { this.tp1Price = null; this.tp2Price = null; return; }
                    this.qty       = this.riskUsd / slDist;
                    this.notional  = this.qty * this.entry;
                    this.tp1Price  = this.entry + slDist;
                    this.tp2Price  = this.entry + 2 * slDist;
                    this.tp1Profit = this.qty * 0.70 * slDist;
                    this.tp2Profit = this.qty * 0.30 * 2 * slDist;
                    window.dispatchEvent(new CustomEvent('chart-tps-updated', { detail: { tp1: this.tp1Price, tp2: this.tp2Price } }));
                },
                fmt(v) { return v != null ? Number(v).toFixed(2) : '0.00'; },
                async submit() {
                    this.loading = true; this.error = '';
                    try {
                        const r = await fetch('{{ route('signals.execute', $signal) }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ risk_usd: this.riskUsd, sl: this.sl }),
                        });
                        const data = await r.json();
                        if (!r.ok) { this.error = data.message || 'Order placement failed.'; return; }
                        window.location.reload();
                    } catch (e) {
                        this.error = 'Network error — check console.';
                    } finally {
                        this.loading = false;
                    }
                },
            };
        }
        </script>
        @endif

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

        @if(request()->routeIs('signals.preview'))
        var candlesUrl = '{{ URL::temporarySignedRoute('signals.preview.candles', now()->addDays(7), ['signal' => $signal->id]) }}';
        @else
        var candlesUrl = '{{ route('signals.candles', $signal) }}';
        @endif
        fetch(candlesUrl)
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

                @php $openTrade = $signal->executedTrades->where('status', 'open')->first(); @endphp
                @if($openTrade)
                // Use executed trade prices instead of signal candle prices
                sig = Object.assign({}, sig, {
                    entry: {{ (float) $openTrade->entry_price }},
                    sl:    {{ (float) $openTrade->sl_price }},
                    tp1:   {{ $openTrade->tp1_price ? (float) $openTrade->tp1_price : 'null' }},
                    tp2:   {{ $openTrade->tp2_price ? (float) $openTrade->tp2_price : 'null' }},
                });
                @endif

                var entryLine = sig.entry ? cs.createPriceLine({ price: sig.entry, color: '#d1d5db', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'Entry', axisLabelVisible: true }) : null;
                var slLine    = sig.sl    ? cs.createPriceLine({ price: sig.sl,    color: '#ef4444', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'SL',    axisLabelVisible: true }) : null;
                var tp1Line   = sig.tp1   ? cs.createPriceLine({ price: sig.tp1,   color: '#10b981', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'TP1',   axisLabelVisible: true }) : null;
                var tp2Line   = sig.tp2   ? cs.createPriceLine({ price: sig.tp2,   color: '#34d399', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'TP2',   axisLabelVisible: true }) : null;

                // Chart click → SL pick mode; auto-recalculate TP1 (1:1) / TP2 (1:2) lines
                window.chartPickMode = null;
                chart.subscribeClick(function (param) {
                    if (!window.chartPickMode || !param.point) return;
                    var price = cs.coordinateToPrice(param.point.y);
                    if (!price) return;
                    window.chartPickMode = null;
                    if (slLine) slLine.applyOptions({ price: price });
                    window.dispatchEvent(new CustomEvent('chart-price-picked', { detail: { field: 'sl', price: price } }));
                });

                // Update TP lines when SL changes (typed or chart-picked)
                window.addEventListener('chart-tps-updated', function (e) {
                    var tp1 = e.detail.tp1, tp2 = e.detail.tp2;
                    if (tp1Line) { tp1Line.applyOptions({ price: tp1 }); } else { tp1Line = cs.createPriceLine({ price: tp1, color: '#10b981', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'TP1', axisLabelVisible: true }); }
                    if (tp2Line) { tp2Line.applyOptions({ price: tp2 }); } else { tp2Line = cs.createPriceLine({ price: tp2, color: '#34d399', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'TP2', axisLabelVisible: true }); }
                });

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
                chart.timeScale().applyOptions({ rightOffset: 10 });

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
