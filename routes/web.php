<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FavoritePairController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\SignalController;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/health', HealthCheckResultsController::class)->name('health');

    Route::post('/screener/favorites/{pair}', [FavoritePairController::class, 'toggle'])->name('screener.favorites.toggle')->where('pair', '.+');

    Route::get('/screener', [ScreenerController::class, 'index'])->name('screener.index');
    Route::get('/screener/history', [ScreenerController::class, 'history'])->name('screener.history');
    Route::get('/screener/{screenerRun}', [ScreenerController::class, 'show'])->name('screener.show');

    Route::get('/signals', [SignalController::class, 'index'])->name('signals.index');
    Route::get('/signals/{signal}', [SignalController::class, 'show'])->name('signals.show');
    Route::get('/signals/{signal}/candles', [SignalController::class, 'candles'])->name('signals.candles');
    Route::post('/signals/{signal}/close', [SignalController::class, 'closeManually'])->name('signals.close');

    Route::get('/scans', [ScanController::class, 'index'])->name('scans.index');
    Route::delete('/scans/pairs/{screenerPair}', [ScanController::class, 'removePair'])->name('scans.pairs.remove');

    Route::get('/run', [RunController::class, 'index'])->name('run.index');
    Route::post('/run', [RunController::class, 'store'])->name('run.store');
});
