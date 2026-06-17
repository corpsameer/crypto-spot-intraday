<?php

namespace App\Http\Controllers;

use App\Models\CandidateWatchlist;
use App\Models\ScanRun;
use App\Models\TradePlan;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $modules = [
            'Universe Sync',
            'Market Snapshots',
            'Candles',
            'Metrics Engine',
            'Scoring Engine',
            'Scan Runs',
            'Scan Results',
            'Candidate Watchlist',
            'Trade Plans',
            'Simulated Trades',
            'Missed Gainers',
            'System Health',
            'Settings',
        ];

        $latestScanRun = ScanRun::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        $dashboardStats = [
            'active_watchlist_count' => CandidateWatchlist::query()->where('status', 'active')->count(),
            'pending_trade_plan_count' => TradePlan::query()->where('status', 'pending')->count(),
            'latest_top_plan' => TradePlan::query()->where('status', 'pending')->orderByDesc('score')->orderByDesc('updated_at')->first(['coindcx_symbol', 'score']),
            'expiring_soon_count' => TradePlan::query()->whereBetween('expires_at', [now(), now()->addHour()])->count(),
        ];

        return view('dashboard', compact('modules', 'latestScanRun', 'dashboardStats'));
    }
}
