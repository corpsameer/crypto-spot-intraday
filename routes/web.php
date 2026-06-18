<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DailyGainerLeaderboardController;
use App\Http\Controllers\DailyReviewController;
use App\Http\Controllers\MissedGainerController;
use App\Http\Controllers\ScannerPerformanceController;
use App\Http\Controllers\ScoreBucketAnalyticsController;
use App\Http\Controllers\SetupTypeAnalyticsController;
use App\Http\Controllers\TradePerformanceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SimulatedTradeController;
use App\Http\Controllers\ScanResultController;
use App\Http\Controllers\SpotSymbolController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('cryptospot')->name('cryptospot.')->group(function (): void {
    Route::get('/', function () {
        return auth()->check()
            ? redirect()->route('cryptospot.dashboard')
            : redirect()->route('cryptospot.login');
    })->name('home');

    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/spot-symbols', [SpotSymbolController::class, 'index'])->name('spot-symbols.index');
        Route::get('/scans/latest', [ScanResultController::class, 'latest'])->name('scans.latest');
        Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
        Route::get('/trade-plans', [WatchlistController::class, 'tradePlans'])->name('trade-plans.index');
        Route::get('/simulated-trades', [SimulatedTradeController::class, 'index'])->name('simulated-trades.index');
        Route::get('/daily-review', [DailyReviewController::class, 'index'])->name('daily-review.index');
        Route::get('/daily-gainers', [DailyGainerLeaderboardController::class, 'index'])->name('daily-gainers.index');
        Route::get('/analytics/scanner-performance', [ScannerPerformanceController::class, 'index'])->name('analytics.scanner-performance');
        Route::get('/analytics/trade-performance', [TradePerformanceController::class, 'index'])->name('analytics.trade-performance');
        Route::get('/analytics/score-buckets', [ScoreBucketAnalyticsController::class, 'index'])->name('analytics.score-buckets');
        Route::get('/analytics/setup-types', [SetupTypeAnalyticsController::class, 'index'])->name('analytics.setup-types');
        Route::get('/missed-gainers', [MissedGainerController::class, 'index'])->name('missed-gainers.index');
        Route::get('/missed-gainers/{missedGainer}', [MissedGainerController::class, 'show'])->name('missed-gainers.show');
        Route::get('/simulated-trades/{simulatedTrade}', [SimulatedTradeController::class, 'show'])->name('simulated-trades.show');
        Route::get('/scans/{scanRun}', [ScanResultController::class, 'show'])->name('scans.show');
        Route::post('/spot-symbols/sync', [SpotSymbolController::class, 'sync'])->name('spot-symbols.sync');
        Route::get('/system-health', [SystemHealthController::class, 'index'])->name('system-health.index');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});
