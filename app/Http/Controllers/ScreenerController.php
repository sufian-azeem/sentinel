<?php

namespace App\Http\Controllers;

use App\Models\ScreenerResult;
use App\Models\ScreenerRun;

class ScreenerController extends Controller
{
    public function index()
    {
        $run = ScreenerRun::where('status', 'completed')->latest('id')->first();

        return view('screener.index', [
            'run' => $run,
            'results' => $run ? $this->loadResults($run->id) : collect(),
        ]);
    }

    public function show(ScreenerRun $screenerRun)
    {
        return view('screener.index', [
            'run' => $screenerRun,
            'results' => $this->loadResults($screenerRun->id),
        ]);
    }

    public function history()
    {
        $runs = ScreenerRun::latest('id')->paginate(20);

        return view('screener.history', compact('runs'));
    }

    private function loadResults(int $runId)
    {
        return ScreenerResult::where('screener_run_id', $runId)
            ->with('signalScans')
            ->orderByDesc('qualified')
            ->orderByDesc('score')
            ->get();
    }
}
