<?php

namespace App\Http\Controllers;

use App\Models\Signal;
use App\Services\SignalTrackerService;
use Illuminate\Http\Request;

class SignalController extends Controller
{
    public function index(Request $request)
    {
        $query = Signal::with('pairScan')->latest('id');

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
