<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FavoritePairController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\SignalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/screener/favorites/{pair}', [FavoritePairController::class, 'toggle'])->name('screener.favorites.toggle')->where('pair', '.+');

Route::get('/screener', [ScreenerController::class, 'index'])->name('screener.index');
Route::get('/screener/history', [ScreenerController::class, 'history'])->name('screener.history');
Route::get('/screener/{screenerRun}', [ScreenerController::class, 'show'])->name('screener.show');

Route::get('/signals', [SignalController::class, 'index'])->name('signals.index');
Route::get('/signals/{signal}', [SignalController::class, 'show'])->name('signals.show');
Route::post('/signals/{signal}/close', [SignalController::class, 'closeManually'])->name('signals.close');

Route::get('/scans', [ScanController::class, 'index'])->name('scans.index');
Route::delete('/scans/pairs/{screenerResult}', [ScanController::class, 'removePair'])->name('scans.pairs.remove');

Route::get('/run', [RunController::class, 'index'])->name('run.index');
Route::post('/run', [RunController::class, 'store'])->name('run.store');
