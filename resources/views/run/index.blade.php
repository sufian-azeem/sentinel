<x-layouts.app title="Run Pipeline">

    <div class="mb-5">
        <h1 class="text-sm font-semibold text-gray-400 tracking-widest uppercase">Run Pipeline</h1>
        <p class="text-xs text-gray-600 mt-0.5">Upload a screener export to score pairs and queue signal scans.</p>
    </div>

    @if(session('success'))
    <div class="border border-emerald-500/20 bg-emerald-500/5 rounded px-3 py-2 mb-4 flex items-center justify-between">
        <span class="text-emerald-400 text-xs">✓ {{ session('success') }}</span>
        @if(session('screener_run_id'))
        <a href="{{ route('screener.show', session('screener_run_id')) }}"
           class="text-emerald-500 hover:text-emerald-400 text-xs font-medium ml-4">View results →</a>
        @endif
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start"
         x-data="{
            exchange: '{{ old('exchange', 'hyperliquid') }}',
            defaults: {{ Js::from($exchangeDefaults) }},
            get d() { const r = this.defaults[this.exchange]; return { ...r, min_volume: r.min_volume / 1000 }; },
            fileName: '', dragging: false, advanced: false, loading: false,
            setFile(file) {
                this.fileName = file ? file.name : '';
                const dt = new DataTransfer();
                if (file) dt.items.add(file);
                this.$refs.fileInput.files = dt.files;
            },
         }">

        {{-- ── Form ── --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('run.store') }}" enctype="multipart/form-data"
                  @submit="loading = true">
                @csrf

                <div class="rounded-lg border border-gray-800 bg-gray-900 overflow-hidden">

                    {{-- File Upload --}}
                    <div class="p-4 border-b border-gray-800">
                        <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-widest mb-2">
                            Data File <span class="text-red-500/70">*</span>
                        </label>
                        <div @dragover.prevent="dragging = true"
                             @dragleave.prevent="dragging = false"
                             @drop.prevent="dragging = false; setFile($event.dataTransfer.files[0])">
                            <div :class="{
                                    'border-emerald-500/50 bg-emerald-500/5': dragging,
                                    'border-emerald-600/30 bg-emerald-500/5': !dragging && fileName,
                                    'border-gray-700/50 hover:border-gray-600': !dragging && !fileName
                                 }"
                                 class="border border-dashed rounded cursor-pointer transition-all duration-200 px-4 py-3"
                                 @click="$refs.fileInput.click()">
                                <template x-if="!fileName">
                                    <div class="flex items-center gap-3">
                                        <span class="text-gray-700 text-base leading-none">⬆</span>
                                        <div class="leading-none">
                                            <span class="text-xs text-gray-400">Drop </span>
                                            <code class="text-[11px] text-gray-300 bg-gray-800 px-1 py-px rounded font-mono">data.json</code>
                                            <span class="text-xs text-gray-400"> or click to browse</span>
                                            <div class="text-[10px] text-gray-700 mt-1">Orion Terminal export · Max 50 MB</div>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="fileName">
                                    <div class="flex items-center gap-2">
                                        <span class="text-emerald-500 text-xs leading-none">✓</span>
                                        <span class="text-xs text-gray-300 font-mono" x-text="fileName"></span>
                                        <button type="button" @click.stop="setFile(null)"
                                                class="text-gray-700 hover:text-gray-400 text-xs ml-auto leading-none">✕</button>
                                    </div>
                                </template>
                            </div>
                            <input type="file" name="data_file" accept=".json" x-ref="fileInput"
                                   class="hidden" @change="setFile($event.target.files[0])">
                        </div>
                        @error('data_file')
                        <p class="text-red-400 text-[10px] mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Exchange --}}
                    <div class="p-4 border-b border-gray-800">
                        <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-widest mb-2">Exchange</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach([
                                'hyperliquid' => ['label' => 'HyperLiquid', 'desc' => 'Min vol $100K', 'active' => 'border-blue-500/50 bg-blue-500/8', 'text' => 'text-blue-400', 'dot' => 'bg-blue-500'],
                                'binance'     => ['label' => 'Binance',     'desc' => 'Min vol $1M',   'active' => 'border-yellow-500/50 bg-yellow-500/8', 'text' => 'text-yellow-400', 'dot' => 'bg-yellow-400'],
                            ] as $value => $meta)
                            <label :class="exchange === '{{ $value }}' ? '{{ $meta['active'] }}' : 'border-gray-800 hover:border-gray-700 bg-gray-800/40'"
                                   class="flex items-center gap-2.5 px-3 py-2.5 rounded border cursor-pointer transition-all duration-150">
                                <input type="radio" name="exchange" value="{{ $value }}" x-model="exchange" class="hidden">
                                <div class="w-2.5 h-2.5 rounded-full border flex items-center justify-center flex-shrink-0 transition-colors"
                                     :class="exchange === '{{ $value }}' ? '{{ $meta['dot'] }} border-transparent' : 'border-gray-600'">
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-medium leading-none transition-colors"
                                         :class="exchange === '{{ $value }}' ? '{{ $meta['text'] }}' : 'text-gray-300'">{{ $meta['label'] }}</div>
                                    <div class="text-[10px] text-gray-600 mt-0.5">{{ $meta['desc'] }}</div>
                                </div>
                            </label>
                            @endforeach
                        </div>
                        @error('exchange')
                        <p class="text-red-400 text-[10px] mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Scanner Options --}}
                    <div class="p-4 border-b border-gray-800">
                        <div class="flex items-center justify-between mb-2.5">
                            <label class="text-[10px] font-semibold text-gray-600 uppercase tracking-widest">Scanner Options</label>
                            <button type="button" @click="advanced = !advanced"
                                    class="text-[10px] text-gray-700 hover:text-gray-400 transition-colors">
                                <span x-text="advanced ? '▲ less' : '▼ more'"></span>
                            </button>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] text-gray-600 mb-1">Top N Pairs</label>
                                <input type="number" name="top" value="{{ old('top', $scannerDefaults['top']) }}" min="1" max="100"
                                       class="w-full bg-gray-800/60 border border-gray-700/50 rounded px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-gray-500 transition-colors">
                                @error('top') <p class="text-red-400 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[10px] text-gray-600 mb-1">Lookback Candles</label>
                                <input type="number" name="lookback" value="{{ old('lookback', $scannerDefaults['lookback']) }}" min="1" max="10"
                                       class="w-full bg-gray-800/60 border border-gray-700/50 rounded px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-gray-500 transition-colors">
                                @error('lookback') <p class="text-red-400 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div x-show="advanced" x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="mt-2.5 pt-2.5 border-t border-gray-800 grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] text-gray-600 mb-1">Min Volume USD 1H (K)</label>
                                <div class="relative">
                                    <input type="number" name="min_volume"
                                           :value="$el.dataset.old || d.min_volume" data-old="{{ old('min_volume') }}"
                                           min="0" step="1"
                                           class="w-full bg-gray-800/60 border border-gray-700/50 rounded px-3 py-2 pr-7 text-xs text-gray-300 focus:outline-none focus:border-gray-500 transition-colors">
                                    <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] text-gray-600 pointer-events-none">K</span>
                                </div>
                                @error('min_volume') <p class="text-red-400 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[10px] text-gray-600 mb-1">Min rVol (15M)</label>
                                <input type="number" name="min_rvol"
                                       :value="$el.dataset.old || d.min_rvol" data-old="{{ old('min_rvol') }}"
                                       min="0" step="0.1"
                                       class="w-full bg-gray-800/60 border border-gray-700/50 rounded px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-gray-500 transition-colors">
                                @error('min_rvol') <p class="text-red-400 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[10px] text-gray-600 mb-1">Min Bullish TFs</label>
                                <input type="number" name="min_bullish_tfs"
                                       :value="$el.dataset.old || d.min_bullish_tfs" data-old="{{ old('min_bullish_tfs') }}"
                                       min="1" max="7"
                                       class="w-full bg-gray-800/60 border border-gray-700/50 rounded px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-gray-500 transition-colors">
                                @error('min_bullish_tfs') <p class="text-red-400 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Submit --}}
                    <div class="p-4">
                        <button type="submit"
                                :disabled="!fileName || loading"
                                :class="(!fileName || loading)
                                    ? 'opacity-30 cursor-not-allowed'
                                    : 'hover:bg-emerald-500/15 hover:border-emerald-500/50'"
                                class="w-full border border-emerald-600/30 bg-emerald-500/10 text-emerald-400 py-2 rounded text-xs font-semibold tracking-wide transition-all duration-200 flex items-center justify-center gap-2">
                            <template x-if="!loading">
                                <span>⚡ Run Pipeline</span>
                            </template>
                            <template x-if="loading">
                                <span class="flex items-center gap-2">
                                    <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                    </svg>
                                    Processing…
                                </span>
                            </template>
                        </button>
                    </div>

                </div>
            </form>
        </div>

        {{-- ── Recent Runs ── --}}
        <div>
            <div class="text-[10px] font-semibold text-gray-600 uppercase tracking-widest mb-2">Recent Runs</div>
            <div class="rounded-lg border border-gray-800 bg-gray-900 overflow-hidden">
                @forelse($recentRuns as $run)
                <div class="px-3 py-2.5 border-b border-gray-800/60 last:border-0 hover:bg-gray-800/30 transition-colors">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-gray-500 text-[10px] font-mono tabular-nums">#{{ str_pad($run->id, 2, '0', STR_PAD_LEFT) }}</span>
                        <x-exchange-badge :exchange="$run->filters_json['exchange'] ?? ''" />
                        <x-run-status :status="$run->status" />
                        <span class="ml-auto text-[10px] text-gray-700 tabular-nums">{{ $run->started_at->format('M d g:i A') }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] text-gray-700">
                            {{ $run->total_scanned ?? 0 }} scanned
                            <span class="text-emerald-600 font-medium ml-1">{{ $run->total_matched ?? 0 }} matched</span>
                        </span>
                        <div class="flex gap-2">
                            <a href="{{ route('screener.show', $run->id) }}"
                               class="text-[10px] text-gray-700 hover:text-emerald-500 transition-colors">screener</a>
                            <a href="{{ route('scans.index', ['run' => $run->id]) }}"
                               class="text-[10px] text-gray-700 hover:text-emerald-500 transition-colors">scans</a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="px-3 py-8 text-center text-gray-700 text-[10px] tracking-wider uppercase">No runs yet</div>
                @endforelse
            </div>
        </div>

    </div>

</x-layouts.app>
