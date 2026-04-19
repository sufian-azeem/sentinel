<x-layouts.app title="Health">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-lg font-bold text-gray-100 tracking-widest uppercase">Health</h1>
        @if ($lastRanAt)
            <span class="{{ $lastRanAt->diffInMinutes() > 5 ? 'text-red-400' : 'text-gray-500' }} text-xs">
                Last checked {{ $lastRanAt->diffForHumans() }}
            </span>
        @endif
    </div>

    @if (count($checkResults?->storedCheckResults ?? []))
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($checkResults->storedCheckResults as $result)
                @php
                    $isFailed = in_array($result->status, ['failed', 'crashed']);
                    $isWarning = $result->status === 'warning';
                @endphp
                <div class="flex items-start gap-3 rounded-lg border px-4 py-4 {{ $isFailed ? 'border-red-700 bg-red-900/20' : ($isWarning ? 'border-yellow-600 bg-yellow-900/20' : 'border-gray-800 bg-gray-900') }}">
                    <span class="mt-0.5 text-lg leading-none">
                        @if ($isFailed) &#x1F534;
                        @elseif ($isWarning) &#x1F7E1;
                        @else &#x1F7E2;
                        @endif
                    </span>
                    <div>
                        <div class="font-bold text-sm text-gray-100">{{ $result->label }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">
                            {{ $result->notificationMessage ?: $result->shortSummary }}
                        </div>
                    </div>
                </div>
            @endforeach
        </dl>
    @else
        <p class="text-gray-500 text-sm">No check results yet.</p>
    @endif
</x-layouts.app>
