<?php

namespace App\Http\Controllers;

use App\Models\Signal;
use App\Services\SignalTrackerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class SignalController extends Controller
{
    public function index(Request $request)
    {
        $query = Signal::with('pairScan.screenerPair')->latest('id');

        if ($request->filled('pair')) {
            $query->where('pair', 'like', '%'.$request->pair.'%');
        }

        if ($request->filled('tf')) {
            $query->where('timeframe', $request->tf);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $signals = $query->paginate(25)->withQueryString();

        return view('signals.index', compact('signals'));
    }

    public function show(Signal $signal)
    {
        $signal->load('pairScan.screenerRun', 'outcome');

        return view('signals.show', compact('signal'));
    }

    public function candles(Signal $signal): JsonResponse
    {
        $signal->load('pairScan', 'outcome');

        $exchange = $signal->pairScan?->exchange ?? 'binance';
        $tf = $signal->timeframe;

        $tfMinutes = match (true) {
            str_ends_with($tf, 'H') => (int) $tf * 60,
            default => (int) $tf,
        };

        $signalTs = $signal->candle_time->utc()->timestamp;
        $since = Carbon::createFromTimestamp($signalTs - 50 * $tfMinutes * 60)->utc()->toIso8601String();

        $maxUntilTs = $signalTs + 100 * $tfMinutes * 60;
        $exitTs = $signal->outcome?->exit_time?->utc()->timestamp;
        $untilTs = $exitTs
            ? min($exitTs + 5 * $tfMinutes * 60, $maxUntilTs)
            : min(now()->utc()->timestamp, $maxUntilTs);
        $until = Carbon::createFromTimestamp($untilTs)->utc()->toIso8601String();

        $python = config('services.python_bin', 'python3');

        $process = new Process(
            [$python, 'chart_data.py',
                '--pair', $signal->pair,
                '--timeframe', $tf,
                '--exchange', $exchange,
                '--since', $since,
                '--until', $until,
            ],
            base_path('python'),
            timeout: 30,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            return response()->json(['error' => 'Failed to fetch chart data'], 500);
        }

        $data = json_decode($process->getOutput(), true);

        $data['signal'] = [
            'candle_time' => $signalTs,
            'entry' => (float) $signal->entry_price,
            'sl' => $signal->sl_price ? (float) $signal->sl_price : null,
            'tp1' => $signal->tp1_price ? (float) $signal->tp1_price : null,
            'tp2' => $signal->tp2_price ? (float) $signal->tp2_price : null,
            'entry_type' => $signal->entry_type,
            'exit_time' => $exitTs,
            'exit_price' => $signal->outcome?->exit_price ? (float) $signal->outcome->exit_price : null,
            'status' => $signal->status,
        ];

        return response()->json($data);
    }

    public function closeManually(Request $request, Signal $signal, SignalTrackerService $tracker)
    {
        $request->validate([
            'status' => ['required', 'in:tp1_hit,tp2_hit,sl_hit'],
            'exit_price' => ['required', 'numeric', 'gt:0'],
        ]);

        abort_if(! in_array($signal->status, ['active', 'tp1_hit']), 422, 'Signal is already closed.');

        $tracker->close($signal, $request->status, (float) $request->exit_price);

        return redirect()->route('signals.show', $signal)
            ->with('success', 'Signal marked as '.str_replace('_', ' ', $request->status).' manually.');
    }
}
