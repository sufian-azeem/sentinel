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
        $favorites = $this->favoritesMap();

        return view('screener.index', [
            'run' => $run,
            'results' => $run ? $this->loadResults($run->id, $favorites) : collect(),
            'favorites' => $favorites,
        ]);
    }

    public function show(ScreenerRun $screenerRun)
    {
        $favorites = $this->favoritesMap();

        return view('screener.index', [
            'run' => $screenerRun,
            'results' => $this->loadResults($screenerRun->id, $favorites),
            'favorites' => $favorites,
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

    private function loadResults(int $runId, array $favorites): Collection
    {
        return ScreenerPair::where('screener_run_id', $runId)
            ->with('pairScans')
            ->get()
            ->sortBy(fn ($r) => [
                $r->qualified ? 0 : 1,
                isset($favorites[$r->pair]) ? 0 : 1,
                -$r->score,
            ])
            ->values();
    }
}
