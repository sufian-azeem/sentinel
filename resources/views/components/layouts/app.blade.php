<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Sentinel' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak]{display:none!important}</style>
    @if(isset($autoRefresh))
    <meta http-equiv="refresh" content="60">
    @endif
</head>
<body class="h-full bg-gray-950 text-gray-100 font-mono">

    {{-- Navigation --}}
    <nav class="bg-gray-900 border-b border-gray-800">
        <div class="max-w-screen-2xl mx-auto px-4 flex items-center gap-6 h-12">
            <a href="{{ route('dashboard') }}" class="text-emerald-400 font-bold tracking-widest text-sm">⚡ SENTINEL</a>
            <div class="flex gap-1 ml-4">
                <a href="{{ route('dashboard') }}"
                   class="px-3 py-1 rounded text-xs {{ request()->routeIs('dashboard') ? 'bg-emerald-500/20 text-emerald-400' : 'text-gray-400 hover:text-gray-200' }}">
                    Dashboard
                </a>
                <a href="{{ route('screener.index') }}"
                   class="px-3 py-1 rounded text-xs {{ request()->routeIs('screener.*') ? 'bg-emerald-500/20 text-emerald-400' : 'text-gray-400 hover:text-gray-200' }}">
                    Screener
                </a>
                <a href="{{ route('signals.index') }}"
                   class="px-3 py-1 rounded text-xs {{ request()->routeIs('signals.*') ? 'bg-emerald-500/20 text-emerald-400' : 'text-gray-400 hover:text-gray-200' }}">
                    Signals
                </a>
                <a href="{{ route('scans.index') }}"
                   class="px-3 py-1 rounded text-xs {{ request()->routeIs('scans.*') ? 'bg-emerald-500/20 text-emerald-400' : 'text-gray-400 hover:text-gray-200' }}">
                    Scans
                </a>
                <a href="{{ route('run.index') }}"
                   class="px-3 py-1 rounded text-xs {{ request()->routeIs('run.*') ? 'bg-emerald-500/20 text-emerald-400' : 'text-gray-400 hover:text-gray-200' }}">
                    Run
                </a>
                <a href="{{ route('health') }}"
                   class="px-3 py-1 rounded text-xs {{ request()->routeIs('health') ? 'bg-emerald-500/20 text-emerald-400' : 'text-gray-400 hover:text-gray-200' }}">
                    Health
                </a>
                <a href="{{ route('log-viewer.index') }}"
                   class="px-3 py-1 rounded text-xs {{ request()->routeIs('log-viewer.*') ? 'bg-emerald-500/20 text-emerald-400' : 'text-gray-400 hover:text-gray-200' }}">
                    Logs
                </a>
            </div>
            <div class="ml-auto flex items-center gap-4">
                <div class="text-xs text-gray-600"><x-timestamp :value="now()" format="M d, Y g:i A" /> {{ now(config('app.timezone'))->format('T') }}</div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-xs text-gray-500 hover:text-gray-300 transition-colors">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    {{-- Page Content --}}
    <main class="max-w-screen-2xl mx-auto px-4 py-6">
        {{ $slot }}
    </main>

    @stack('scripts')
</body>
</html>
