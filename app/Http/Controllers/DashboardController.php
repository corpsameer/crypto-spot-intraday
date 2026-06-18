<?php

namespace App\Http\Controllers;

use App\Models\CandidateWatchlist;
use App\Models\MissedGainer;
use App\Models\ScanRun;
use App\Models\SimulatedTrade;
use App\Models\TradeEvent;
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
            'Daily Gainers',
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
            'active_simulated_trade_count' => SimulatedTrade::query()->whereIn('status', ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'])->count(),
            'open_simulated_trade_count' => SimulatedTrade::query()->whereIn('status', ['pending', 'active', 'tp1_hit', 'tp2_hit', 'trailing_active'])->count(),
            'closed_simulated_trade_count' => SimulatedTrade::query()->whereIn('status', ['closed_tp1', 'closed_tp2', 'closed_sl', 'closed_trailing', 'expired', 'cancelled', 'error'])->count(),
            'trade_event_count' => TradeEvent::query()->count(),
            'open_unrealized_pnl' => (float) SimulatedTrade::query()->whereIn('status', ['pending', 'active', 'tp1_hit', 'tp2_hit', 'trailing_active'])->sum('current_pnl_percent'),
            'latest_trade_event' => TradeEvent::query()->orderByDesc('event_time')->orderByDesc('id')->first(['coindcx_symbol', 'event_type', 'event_time']),
            'today_critical_high_missed_gainers' => MissedGainer::query()->whereDate('analysis_date', now()->toDateString())->whereIn('miss_severity', ['critical', 'high'])->count(),
            'today_captured_not_selected' => MissedGainer::query()->whereDate('analysis_date', now()->toDateString())->where('miss_type', 'captured_not_selected')->count(),
            'today_missed_trade_created' => MissedGainer::query()->whereDate('analysis_date', now()->toDateString())->where('miss_type', 'captured_trade_created')->count(),
        ];

        return view('dashboard', compact('modules', 'latestScanRun', 'dashboardStats'));
    }
}
