<x-layouts.app title="Signals">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-bold text-gray-200 tracking-wide">Signals</h1>
        @if(request()->boolean('all'))
            <a href="{{ route('signals.index', request()->except('all', 'page')) }}" class="text-xs text-gray-400 hover:text-gray-200">← By Day</a>
        @else
            <a href="{{ route('signals.index', array_merge(request()->query(), ['all' => 1])) }}" class="text-xs text-gray-400 hover:text-gray-200">Show All →</a>
        @endif
    </div>

    {{-- Filters --}}
    <form method="GET" class="flex gap-3 mb-6 flex-wrap">
        @if(request()->boolean('all'))
            <input type="hidden" name="all" value="1">
        @endif
        <input type="text" name="pair" value="{{ request('pair') }}"
               placeholder="Pair (e.g. BTC)"
               class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 placeholder-gray-600 focus:outline-none focus:border-emerald-500 w-36">
        <select name="tf" class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 focus:outline-none focus:border-emerald-500">
            <option value="">All TFs</option>
            @foreach(['5M','15M','1H','4H','8H','12H','1D'] as $tf)
            <option value="{{ $tf }}" {{ request('tf') === $tf ? 'selected' : '' }}>{{ $tf }}</option>
            @endforeach
        </select>
        <select name="status" class="bg-gray-900 border border-gray-700 rounded px-3 py-1.5 text-xs text-gray-300 focus:outline-none focus:border-emerald-500">
            <option value="">All Statuses</option>
            @foreach(['active','tp1_hit','tp2_hit','sl_hit','expired'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-1.5 rounded text-xs">Filter</button>
        @if(request()->hasAny(['pair','tf','status']))
        <a href="{{ route('signals.index', request()->boolean('all') ? ['all' => 1] : []) }}" class="px-4 py-1.5 rounded text-xs text-gray-400 hover:text-gray-200 border border-gray-700">Clear</a>
        @endif
    </form>

    @if($byDay !== null)
        {{-- Grouped by day view --}}
        @forelse($byDay as $date => $daySignals)
            @php
                $label = match(true) {
                    $date === now()->toDateString()           => 'Today',
                    $date === now()->subDay()->toDateString() => 'Yesterday',
                    default => \Carbon\Carbon::parse($date)->format('M j, Y'),
                };
            @endphp
            <div class="mb-6">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ $label }}</span>
                    <span class="text-xs text-gray-600">{{ $daySignals->count() }} signal{{ $daySignals->count() === 1 ? '' : 's' }}</span>
                    <div class="flex-1 border-t border-gray-800"></div>
                </div>
                @include('signals._table', ['signals' => $daySignals])
            </div>
        @empty
            <div class="bg-gray-900 border border-gray-800 rounded-lg px-4 py-8 text-center text-gray-600 text-xs">No signals found</div>
        @endforelse

    @else
        {{-- Flat paginated view --}}
        @include('signals._table', ['signals' => $signals])
        @if($signals->hasPages())
        <div class="mt-3 px-1">
            {{ $signals->links() }}
        </div>
        @endif
    @endif

</x-layouts.app>
