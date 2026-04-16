<?php

namespace App\Http\Controllers;

use App\Models\ScreenerResult;
use App\Models\ScreenerRun;
use App\Models\SignalScan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function index(Request $request)
    {
        $activeRuns = ScreenerRun::completed()
            ->latest('id')
            ->with(['screenerResults' => fn ($q) => $q
                ->where('qualified', true)
                ->orderByDesc('score')
                ->with(['signalScans' => fn ($q2) => $q2->with('signals')->latest('id')]),
            ])
            ->get();

        // Paginated scan list
        $query = SignalScan::with(['screenerRun', 'signals']);

        if (request()->filled('pair')) {
            $query->where('signal_scans.pair', 'like', '%'.request('pair').'%');
        }

        if (request()->filled('status')) {
            $query->where('signal_scans.status', request('status'));
        }

        if (request()->filled('run')) {
            $query->where('signal_scans.screener_run_id', request('run'))
                ->leftJoin('screener_results', 'signal_scans.screener_result_id', '=', 'screener_results.id')
                ->orderByDesc('screener_results.score')
                ->select('signal_scans.*');
        } else {
            $query->whereHas('screenerRun', fn ($q) => $q->completed())
                ->latest('signal_scans.id');
        }

        $scans = $query->paginate(25)->withQueryString();

        $monitoringData = $activeRuns->map(function (ScreenerRun $run) {
            $exchange = $run->filters_json['exchange'] ?? '—';

            return [
                'id' => $run->id,
                'exchange' => $exchange,
                'expiresLabel' => 'expires '.$run->started_at->addHours(ScreenerRun::EXPIRY_HOURS)->diffForHumans(),
                'pairs' => $run->screenerResults->map(function (ScreenerResult $result) {
                    $scansByTf = $result->signalScans->groupBy('timeframe');

                    $tfs = [];
                    foreach (['15M', '1H', '4H'] as $tf) {
                        $scan = ($scansByTf[$tf] ?? collect())->first();
                        $signal = $scan?->signals->first();

                        $conditions = $scan?->conditions_json ?? [];
                        $lastCandle = ! empty($conditions) ? end($conditions) : null;

                        $tfs[$tf] = [
                            'status' => $signal ? $signal->status : ($scan?->status ?? 'pending'),
                            'lastScanned' => $scan?->created_at?->diffForHumans() ?? null,
                            'error' => $scan?->error_message ?? null,
                            'conditions' => $lastCandle ? [
                                'ltf' => $lastCandle['ltf'] ?? null,
                                'htf' => $lastCandle['htf'] ?? null,
                                'pullback' => $lastCandle['pullback'] ?? null,
                                'awakening' => $lastCandle['awakening'] ?? null,
                            ] : null,
                            'signal' => $signal ? [
                                'id' => $signal->id,
                                'entry_type' => $signal->entry_type,
                                'entry' => number_format((float) $signal->entry_price, 6),
                                'sl' => $signal->sl_price ? number_format((float) $signal->sl_price, 6) : null,
                                'tp1' => $signal->tp1_price ? number_format((float) $signal->tp1_price, 6) : null,
                                'tp2' => $signal->tp2_price ? number_format((float) $signal->tp2_price, 6) : null,
                                'risk_pct' => number_format((float) $signal->risk_pct, 2),
                                'status' => $signal->status,
                                'candle_time' => $signal->candle_time->format('M d, g:i A'),
                            ] : null,
                        ];
                    }

                    $hasActiveSignal = $result->signalScans
                        ->flatMap->signals
                        ->whereIn('status', ['active', 'tp1_hit'])
                        ->isNotEmpty();

                    return [
                        'id' => $result->id,
                        'pair' => $result->pair,
                        'score' => number_format((float) $result->score, 4),
                        'hasActiveSignal' => $hasActiveSignal,
                        'tfs' => $tfs,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return view('scans.index', compact('monitoringData', 'scans'));
    }

    public function removePair(ScreenerResult $screenerResult): RedirectResponse
    {
        SignalScan::where('screener_result_id', $screenerResult->id)->delete();
        $screenerResult->delete();

        return back()->with('success', "{$screenerResult->pair} removed.");
    }
}
