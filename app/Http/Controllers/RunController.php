<?php

namespace App\Http\Controllers;

use App\Enums\Exchange;
use App\Jobs\RunPipelineJob;
use App\Models\ScreenerRun;
use App\Services\ScreenerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RunController extends Controller
{
    public function __construct(private readonly ScreenerService $screener) {}

    public function index(): View
    {
        return view('run.index', [
            'exchangeDefaults' => config('trading.exchanges'),
            'scannerDefaults' => config('trading.scanner'),
            'recentRuns' => ScreenerRun::latest()->limit(8)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'data_file' => ['required', 'file', 'mimes:json', 'max:51200'],
            'exchange' => ['required', Rule::enum(Exchange::class)],
            'top' => ['required', 'integer', 'min:1', 'max:100'],
            'lookback' => ['required', 'integer', 'min:1', 'max:10'],
            'min_volume' => ['nullable', 'numeric', 'min:0'],
            'min_rvol' => ['nullable', 'numeric', 'min:0'],
            'min_bullish_tfs' => ['nullable', 'integer', 'min:1', 'max:7'],
        ]);

        $path = $request->file('data_file')->storeAs('screener', 'data.json');

        $defaults = config('trading.exchanges.'.$validated['exchange']);
        $tickers = $this->parseTickers($path);

        $screenerRun = $this->screener->run(
            tickers: $tickers,
            dataSource: 'orion_file',
            exchange: $validated['exchange'],
            minVolume: isset($validated['min_volume']) ? (float) $validated['min_volume'] * 1000 : $defaults['min_volume'],
            minRvol: isset($validated['min_rvol']) ? (float) $validated['min_rvol'] : $defaults['min_rvol'],
            minBullishTfs: isset($validated['min_bullish_tfs']) ? (int) $validated['min_bullish_tfs'] : $defaults['min_bullish_tfs'],
            topN: (int) $validated['top'],
        );

        RunPipelineJob::dispatch(
            $screenerRun->id,
            $validated['exchange'],
            (int) $validated['top'],
            (int) $validated['lookback'],
        );

        return redirect()
            ->route('run.index')
            ->with('success', "Screener complete — {$screenerRun->total_matched} pairs qualified. Signal scan queued.")
            ->with('screener_run_id', $screenerRun->id);
    }

    private function parseTickers(string $path): array
    {
        $data = json_decode(Storage::get($path), true);

        return $data['tickers'] ?? (array_is_list($data) ? $data : []);
    }
}
