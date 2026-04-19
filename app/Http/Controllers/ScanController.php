<?php

namespace App\Http\Controllers;

use App\Models\PairScan;
use App\Models\ScreenerPair;
use App\Models\ScreenerRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function index(Request $request)
    {
        $activeRuns = ScreenerRun::completed()
            ->latest('id')
            ->with(['screenerPairs' => fn ($q) => $q
                ->qualified()
                ->orderByDesc('score')
                ->with(['pairScans' => fn ($q2) => $q2->with('signals')->latest('id')]),
            ])
            ->get();

        // Paginated scan list
        $query = PairScan::with(['screenerRun', 'signals']);

        if (request()->filled('pair')) {
            $query->where('pair_scans.pair', 'like', '%'.request('pair').'%');
        }

        if (request()->filled('status')) {
            $query->where('pair_scans.status', request('status'));
        }

        if (request()->filled('run')) {
            $query->where('screener_run_id', request('run'))
                ->orderByDesc(
                    ScreenerPair::select('score')
                        ->whereColumn('id', 'pair_scans.screener_result_id')
                        ->limit(1)
                );
        } else {
            $query->whereHas('screenerRun', fn ($q) => $q->completed())
                ->latest('id');
        }

        $scans = $query->paginate(25)->withQueryString();

        $monitoringData = $activeRuns->map(function (ScreenerRun $run) {
            $exchange = $run->filters_json['exchange'] ?? '—';

            return [
                'id' => $run->id,
                'exchange' => $exchange,
                'expiresLabel' => 'expires '.$run->started_at->addHours(ScreenerRun::EXPIRY_HOURS)->diffForHumans(),
                'pairs' => $run->screenerPairs->map(function (ScreenerPair $result) {
                    $scansByTf = $result->pairScans->groupBy('timeframe');

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

                    $hasActiveSignal = $result->pairScans
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

    public function removePair(ScreenerPair $screenerPair): RedirectResponse
    {
        PairScan::where('screener_result_id', $screenerPair->id)->delete();
        $screenerPair->delete();

        return back()->with('success', "{$screenerPair->pair} removed.");
    }
}
