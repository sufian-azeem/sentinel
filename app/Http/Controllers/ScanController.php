<?php

namespace App\Http\Controllers;

use App\Models\ScreenerRun;
use App\Models\SignalScan;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    private const int EXPIRY_HOURS = 24;

    public function index(Request $request)
    {
        $query = SignalScan::with(['screenerRun', 'signals']);

        if ($request->filled('pair')) {
            $query->where('signal_scans.pair', 'like', '%'.$request->pair.'%');
        }

        if ($request->filled('status')) {
            $query->where('signal_scans.status', $request->status);
        }

        if ($request->filled('run')) {
            $query->where('signal_scans.screener_run_id', $request->run)
                ->leftJoin('screener_results', 'signal_scans.screener_result_id', '=', 'screener_results.id')
                ->orderByDesc('screener_results.score')
                ->select('signal_scans.*');
        } else {
            $query->latest('signal_scans.id');
        }

        $scans = $query->paginate(25)->withQueryString();

        // Monitoring: all active (non-expired) runs with qualified pairs + latest scans
        $cutoff = now()->subHours(self::EXPIRY_HOURS);
        $activeRuns = ScreenerRun::where('status', 'completed')
            ->where('started_at', '>=', $cutoff)
            ->latest('id')
            ->with(['screenerResults' => fn ($q) => $q
                ->where('qualified', true)
                ->orderByDesc('score')
                ->with(['signalScans' => fn ($q2) => $q2->with('signals')->latest('id')]),
            ])
            ->get();

        return view('scans.index', compact('scans', 'activeRuns'));
    }
}
