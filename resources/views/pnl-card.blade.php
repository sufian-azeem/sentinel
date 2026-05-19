<x-layouts.app title="PNL Card">

    <div class="mb-6">
        <h1 class="text-lg font-bold text-gray-200 tracking-wide">PNL Card Generator</h1>
        <p class="text-xs text-gray-600 mt-0.5">Fill in your trade details and download a shareable card</p>
    </div>

    <div x-data="pnlCard()" class="flex flex-col lg:flex-row gap-8 items-start">

        {{-- Form --}}
        <div class="lg:w-72 flex-shrink-0">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
                <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4">Trade Details</h2>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Pair</label>
                        <input type="text" x-model="pair" @input="pair = pair.toUpperCase()" placeholder="e.g. BTC"
                               class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-emerald-500 placeholder-gray-600">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Direction</label>
                        <select x-model="direction"
                                class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-emerald-500">
                            <option>LONG</option>
                            <option>SHORT</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Timeframe</label>
                        <select x-model="tf"
                                class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-emerald-500">
                            @foreach(['5M','15M','1H','4H','8H','12H','1D'] as $t)
                            <option>{{ $t }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Entry Price</label>
                        <input type="number" x-model="entry" step="any" placeholder="0.00"
                               class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-emerald-500 placeholder-gray-600">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Exit Price</label>
                        <input type="number" x-model="exitPrice" step="any" placeholder="0.00"
                               class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-emerald-500 placeholder-gray-600">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Date</label>
                        <input type="date" x-model="date"
                               class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-emerald-500">
                    </div>
                </div>

                <div class="mt-4 pt-4 border-t border-gray-800">
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>ROI</span>
                        <span x-text="roiDisplay()" :style="'color:' + roiColor()" class="font-semibold font-mono"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Preview + Download --}}
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs text-gray-500">Preview</span>
                <div class="flex gap-2">
                    <button @click="copy()"
                            class="text-xs px-3 py-1.5 rounded border border-gray-700 text-gray-400 hover:border-gray-500 hover:text-gray-200 transition-colors"
                            x-text="copied ? 'Copied!' : 'Copy'"></button>
                    <button @click="download()"
                            class="text-xs px-3 py-1.5 rounded border border-gray-700 text-gray-400 hover:border-gray-500 hover:text-gray-200 transition-colors">
                        Download PNG
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
            <div id="manual-pnl-card"
                 style="width:580px;background:#0b0e14;border-radius:16px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;position:relative;border:1px solid #1a2030;flex-shrink:0;">
                {{-- Glow --}}
                <div :style="'position:absolute;top:-80px;left:-60px;width:340px;height:340px;background:radial-gradient(circle,' + glowColor() + ' 0%,transparent 70%);pointer-events:none;'"></div>
                {{-- Top bar --}}
                <div style="padding:18px 28px 0;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:11px;font-weight:700;color:#374151;letter-spacing:0.18em;text-transform:uppercase;">Sentinel</span>
                    <span style="font-size:11px;color:#374151;" x-text="tf + ' · ' + formattedDate()"></span>
                </div>
                {{-- Body --}}
                <div style="padding:14px 28px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;">
                    {{-- Left --}}
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                            <span style="font-size:19px;font-weight:700;color:#e5e7eb;" x-text="displayPair()"></span>
                            <span x-text="direction"
                                  :style="'font-size:10px;font-weight:700;color:' + badgeColor() + ';background:' + badgeBg() + ';border:1px solid ' + badgeBorder() + ';padding:2px 8px;border-radius:4px;text-transform:uppercase;'"></span>
                        </div>
                        <div style="font-size:10px;font-weight:600;color:#374151;letter-spacing:0.18em;text-transform:uppercase;margin-bottom:3px;">ROI</div>
                        <div :style="'font-size:62px;font-weight:900;color:' + roiColor() + ';line-height:1;letter-spacing:-2px;margin-bottom:20px;'"
                             x-text="roiDisplay()"></div>
                        <div style="display:flex;gap:28px;">
                            <div>
                                <div style="font-size:10px;font-weight:600;color:#374151;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:4px;">Entry</div>
                                <div style="font-size:15px;font-weight:600;color:#9ca3af;font-family:'Courier New',monospace;"
                                     x-text="entry ? parseFloat(entry).toLocaleString('en', {minimumFractionDigits:2,maximumFractionDigits:6}) : '—'"></div>
                            </div>
                            <div>
                                <div style="font-size:10px;font-weight:600;color:#374151;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:4px;">Exit</div>
                                <div :style="'font-size:15px;font-weight:600;color:' + roiColor() + ';font-family:\'Courier New\',monospace;'"
                                     x-text="exitPrice ? parseFloat(exitPrice).toLocaleString('en', {minimumFractionDigits:2,maximumFractionDigits:6}) : '—'"></div>
                            </div>
                        </div>
                    </div>
                    {{-- Rocket --}}
                    <div style="font-size:108px;line-height:1;flex-shrink:0;opacity:0.88;">🚀</div>
                </div>
            </div>
            </div>
        </div>

    </div>

    @push('scripts')
    <script>
    function pnlCard() {
        return {
            pair: 'BTC',
            direction: 'LONG',
            tf: '4H',
            entry: '',
            exitPrice: '',
            date: new Date().toISOString().split('T')[0],
            copied: false,

            roi() {
                const e = parseFloat(this.entry);
                const x = parseFloat(this.exitPrice);
                if (!e || !x || e === 0) return null;
                return ((x - e) / e) * 100;
            },
            roiDisplay() {
                const r = this.roi();
                if (r === null) return '+0.00%';
                return (r >= 0 ? '+' : '') + r.toFixed(2) + '%';
            },
            displayPair() {
                const p = (this.pair || 'BTC').toUpperCase().trim();
                return p.includes('/') ? p : p + '/USDT';
            },
            roiColor() {
                const r = this.roi();
                if (r === null) return '#34d399';
                return r > 0 ? '#34d399' : r < 0 ? '#f87171' : '#9ca3af';
            },
            glowColor() {
                const r = this.roi();
                return (r === null || r >= 0) ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.10)';
            },
            badgeColor() {
                const r = this.roi();
                return (r === null || r >= 0) ? '#10b981' : '#ef4444';
            },
            badgeBg() {
                const r = this.roi();
                return (r === null || r >= 0) ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.12)';
            },
            badgeBorder() {
                const r = this.roi();
                return (r === null || r >= 0) ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.25)';
            },
            formattedDate() {
                if (!this.date) return '';
                const d = new Date(this.date + 'T00:00:00');
                return d.toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' });
            },
            _buildCanvas() {
                const scale = 2, W = 580, H = 240;
                const canvas = document.createElement('canvas');
                canvas.width = W * scale; canvas.height = H * scale;
                const ctx = canvas.getContext('2d');
                ctx.scale(scale, scale);

                const pair     = this.displayPair();
                const color    = this.roiColor();
                const sans     = '"Segoe UI", -apple-system, BlinkMacSystemFont, Arial, sans-serif';
                const mono     = '"Courier New", Courier, monospace';
                const fmt      = v => parseFloat(v).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 6 });
                const entryVal = this.entry     ? fmt(this.entry)     : '—';
                const exitVal  = this.exitPrice ? fmt(this.exitPrice) : '—';

                ctx.fillStyle = '#0b0e14';
                ctx.fillRect(0, 0, W, H);

                const glow = ctx.createRadialGradient(-30, -40, 0, -30, -40, 260);
                glow.addColorStop(0, this.glowColor()); glow.addColorStop(1, 'rgba(0,0,0,0)');
                ctx.fillStyle = glow; ctx.fillRect(0, 0, W, H);

                ctx.fillStyle = '#374151';
                ctx.font = '700 11px ' + sans; ctx.letterSpacing = '2px';
                ctx.fillText('SENTINEL', 28, 29);
                ctx.font = '11px ' + sans; ctx.letterSpacing = '0px';
                ctx.textAlign = 'right';
                ctx.fillText(this.tf + ' · ' + this.formattedDate(), W - 28, 29);
                ctx.textAlign = 'left';

                ctx.fillStyle = '#e5e7eb';
                ctx.font = '700 19px ' + sans; ctx.letterSpacing = '-0.5px';
                ctx.fillText(pair, 28, 72);
                const pairW = ctx.measureText(pair).width;

                ctx.letterSpacing = '0px'; ctx.font = '700 10px ' + sans;
                const dirW = ctx.measureText(this.direction).width;
                const bx = 28 + pairW + 10, bPad = 8, bW = dirW + bPad * 2;
                ctx.fillStyle = this.badgeBg();
                ctx.beginPath(); ctx.roundRect(bx, 59, bW, 16, 3); ctx.fill();
                ctx.strokeStyle = this.badgeBorder(); ctx.lineWidth = 1;
                ctx.beginPath(); ctx.roundRect(bx, 59, bW, 16, 3); ctx.stroke();
                ctx.fillStyle = this.badgeColor(); ctx.fillText(this.direction, bx + bPad, 70);

                ctx.letterSpacing = '2px'; ctx.fillStyle = '#374151';
                ctx.font = '600 10px ' + sans; ctx.fillText('ROI', 28, 95);

                ctx.letterSpacing = '-2px'; ctx.fillStyle = color;
                ctx.font = '900 62px ' + sans; ctx.fillText(this.roiDisplay(), 26, 162);

                ctx.letterSpacing = '1.5px'; ctx.fillStyle = '#374151';
                ctx.font = '600 9px ' + sans;
                ctx.fillText('ENTRY', 28, 185); ctx.fillText('EXIT', 200, 185);

                ctx.letterSpacing = '0px'; ctx.fillStyle = '#9ca3af';
                ctx.font = '600 14px ' + mono; ctx.fillText(entryVal, 28, 205);
                ctx.fillStyle = color; ctx.fillText(exitVal, 200, 205);

                ctx.font = '100px sans-serif'; ctx.fillText('🚀', 445, 192);

                return { canvas, pair };
            },
            download() {
                const { canvas, pair } = this._buildCanvas();
                const link = document.createElement('a');
                link.download = 'pnl-' + pair.replace('/', '-') + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            },
            async copy() {
                const { canvas } = this._buildCanvas();
                canvas.toBlob(async (blob) => {
                    try {
                        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
                        this.copied = true;
                        setTimeout(() => { this.copied = false; }, 2000);
                    } catch (e) {
                        alert('Copy failed — try downloading instead.');
                    }
                }, 'image/png');
            },
        };
    }
    </script>
    @endpush

</x-layouts.app>
