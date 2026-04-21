<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Crypto Signals</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gray-950 text-gray-100 font-mono flex items-center justify-center p-4">

    <div class="w-full max-w-sm">

        {{-- Header --}}
        <div class="text-center mb-8">
            <div class="text-3xl text-emerald-400 mb-3">⚡</div>
            <h1 class="text-xl font-bold tracking-widest text-white uppercase">Crypto Signals</h1>
            <p class="text-gray-500 text-xs mt-1 tracking-wide">Sign in to your dashboard</p>
        </div>

        {{-- Card --}}
        <div class="bg-gray-900 border border-gray-800 rounded-2xl shadow-2xl p-8">

            @if ($errors->any())
                <div class="mb-6 bg-red-500/10 border border-red-500/30 rounded-lg px-4 py-3 text-sm text-red-400">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf

                <div class="space-y-1">
                    <label for="email" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Email</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="email"
                        placeholder="you@example.com"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/50 transition-colors @error('email') border-red-500 @enderror"
                    />
                </div>

                <div class="space-y-1">
                    <label for="password" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider">Password</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder="••••••••"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/50 transition-colors"
                    />
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input id="remember" type="checkbox" name="remember" class="w-4 h-4 accent-emerald-500 rounded" />
                    <label for="remember" class="text-xs text-gray-500 select-none cursor-pointer">Keep me signed in</label>
                </div>

                <button
                    type="submit"
                    class="w-full bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white text-sm font-bold py-3 rounded-lg tracking-wide transition-colors mt-2"
                >
                    Sign In
                </button>

            </form>
        </div>

    </div>

</body>
</html>
