<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Crypto Signals</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gray-950 text-gray-100 font-mono flex items-center justify-center">

    <div class="w-full max-w-sm px-6">
        <div class="text-center mb-8">
            <div class="text-emerald-400 font-bold tracking-widest text-lg mb-1">⚡ CRYPTO SIGNALS</div>
            <div class="text-gray-500 text-xs">Sign in to continue</div>
        </div>

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-xs text-gray-400 mb-1">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="email"
                    class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 text-sm text-gray-100 focus:outline-none focus:border-emerald-500 @error('email') border-red-500 @enderror"
                />
                @error('email')
                    <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-xs text-gray-400 mb-1">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2 text-sm text-gray-100 focus:outline-none focus:border-emerald-500"
                />
            </div>

            <div class="flex items-center gap-2">
                <input id="remember" type="checkbox" name="remember" class="accent-emerald-500" />
                <label for="remember" class="text-xs text-gray-400">Remember me</label>
            </div>

            <button
                type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold py-2 rounded transition-colors"
            >
                Sign in
            </button>
        </form>
    </div>

</body>
</html>
