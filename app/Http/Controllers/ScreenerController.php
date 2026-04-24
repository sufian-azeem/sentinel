<?php

namespace App\Http\Controllers;

use App\Models\FavoritePair;
use App\Models\ScreenerPair;
use App\Models\ScreenerRun;
use Illuminate\Database\Eloquent\Collection;

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

    private function loadResults(int $runId): Collection
    {
        return ScreenerPair::where('screener_run_id', $runId)
            ->with('pairScans')
            ->orderByDesc('qualified')
            ->orderByDesc('score')
            ->get();
    }
}
