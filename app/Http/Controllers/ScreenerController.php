<?php

namespace App\Http\Controllers;

use App\Models\FavoritePair;
use App\Models\ScreenerResult;
use App\Models\ScreenerRun;

class ScreenerController extends Controller
{
    public function index()
    {
        $run = ScreenerRun::completed()->latest('id')->first();

        return view('screener.index', [
            'run' => $run,
            'results' => $run ? $this->loadResults($run->id) : collect(),
            'favorites' => $this->favoritesMap(),
        ]);
    }

    public function show(ScreenerRun $screenerRun)
    {
        return view('screener.index', [
            'run' => $screenerRun,
            'results' => $this->loadResults($screenerRun->id),
            'favorites' => $this->favoritesMap(),
        ]);
    }

    public function history()
    {
        $runs = ScreenerRun::latest('id')->paginate(20);

        return view('screener.history', compact('runs'));
    }

    private function favoritesMap(): array
    {
        return FavoritePair::pluck('pair')->flip()->all();
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
