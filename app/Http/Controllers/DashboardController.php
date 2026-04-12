<?php

namespace App\Http\Controllers;

use App\Models\ScreenerRun;
use App\Models\Signal;

class DashboardController extends Controller
{
    public function index()
    {
        $latestRun = ScreenerRun::latest('id')->first();

        $activeSignals = Signal::where('status', 'active')->count();

        $recentSignals = Signal::with('signalScan')
            ->latest('id')
            ->limit(10)
            ->get();

        $recentRuns = ScreenerRun::latest('id')->limit(5)->get();

        return view('dashboard', compact('latestRun', 'activeSignals', 'recentSignals', 'recentRuns'));
    }
}
