<?php

namespace App\Http\Controllers;

use App\Models\CandidateWatchlist;
use App\Models\DailyGainerLeaderboard;
use App\Models\MarketSnapshot;
use App\Models\MissedGainer;
use App\Models\ScanResult;
use App\Models\ScanRun;
use App\Models\SimulatedTrade;
use App\Models\SystemHealthLog;
use App\Models\TradeEvent;
use App\Models\TradePlan;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $openTradeStatuses = ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'];
        $closedTradeStatuses = ['closed_sl', 'closed_trailing', 'closed_tp1', 'closed_tp2', 'expired', 'cancelled', 'error'];
        $openPlanStatuses = ['pending', 'watching', 'triggered'];

        $latestScan = ScanRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();
        $latestScanId = $latestScan?->id;

        $scanResults = ScanResult::query()->when($latestScanId, fn ($query) => $query->where('scan_run_id', $latestScanId));
        $scanStats = [
            'symbols_scanned' => (int) ($latestScan?->total_active_symbols ?? 0),
            'ticker_rows_fetched' => (int) ($latestScan?->ticker_rows_fetched ?? 0),
            'prefilter_passed' => (int) ($latestScan?->prefilter_passed_count ?: (clone $scanResults)->where('prefilter_passed', true)->count()),
            'scored' => (int) ($latestScan?->scored_count ?: (clone $scanResults)->whereNotNull('final_score')->count()),
            'selected' => (int) ($latestScan?->watchlist_created_count ?: (clone $scanResults)->where('selected_for_watchlist', true)->count()),
            'trade_plans' => (int) ($latestScan?->trade_plans_created_count ?: TradePlan::query()->when($latestScanId, fn ($query) => $query->where('scan_run_id', $latestScanId))->count()),
            'errors' => $latestScan?->error_message ? 1 : 0,
        ];

        $marketSnapshot = Schema::hasTable('market_snapshots')
            ? MarketSnapshot::query()->orderByDesc('snapshot_time')->orderByDesc('id')->first()
            : null;

        $tradePlanStats = [
            'active_watchlist' => CandidateWatchlist::query()->whereIn('status', ['active', 'open', 'watchlist'])->count(),
            'open_plans' => TradePlan::query()->whereIn('status', $openPlanStatuses)->count(),
            'triggered' => TradePlan::query()->where('status', 'triggered')->count(),
            'converted' => TradePlan::query()->where('status', 'converted_to_trade')->orWhereNotNull('converted_at')->count(),
            'expired' => TradePlan::query()->where('status', 'expired')->count(),
            'breakout' => TradePlan::query()->where('entry_strategy', 'breakout')->whereIn('status', $openPlanStatuses)->count(),
            'pullback' => TradePlan::query()->where('entry_strategy', 'pullback')->whereIn('status', $openPlanStatuses)->count(),
            'last_24h' => TradePlan::query()->where('created_at', '>=', now()->subDay())->count(),
        ];

        $openTradesQuery = SimulatedTrade::query()->whereIn('status', $openTradeStatuses);
        $closedTradesQuery = SimulatedTrade::query()->whereIn('status', $closedTradeStatuses);
        $simulatedTradeStats = [
            'open_count' => (clone $openTradesQuery)->count(),
            'closed_count' => (clone $closedTradesQuery)->count(),
            'open_unrealized_sum' => (float) (clone $openTradesQuery)->sum('current_pnl_percent'),
            'open_unrealized_avg' => (float) (clone $openTradesQuery)->avg('current_pnl_percent'),
            'closed_realized_sum' => (float) (clone $closedTradesQuery)->sum('final_pnl_percent'),
            'closed_realized_avg' => (float) (clone $closedTradesQuery)->avg('final_pnl_percent'),
            'best_open_max_gain' => (float) ((clone $openTradesQuery)->max('max_gain_percent') ?? 0),
            'worst_open_drawdown' => (float) ((clone $openTradesQuery)->min('max_drawdown_percent') ?? 0),
            'tp1_hit_count' => SimulatedTrade::query()->whereNotNull('tp1_hit_at')->orWhereIn('status', ['tp1_hit', 'tp2_hit', 'closed_tp1', 'closed_tp2'])->count(),
            'tp2_hit_count' => SimulatedTrade::query()->whereNotNull('tp2_hit_at')->orWhereIn('status', ['tp2_hit', 'closed_tp2'])->count(),
            'sl_closed_count' => SimulatedTrade::query()->where('status', 'closed_sl')->count(),
            'trailing_closed_count' => SimulatedTrade::query()->where('status', 'closed_trailing')->count(),
            'expired_count' => SimulatedTrade::query()->where('status', 'expired')->count(),
        ];

        $latestLeaderboardDate = DailyGainerLeaderboard::query()->max('leaderboard_date');
        $leaderboardQuery = DailyGainerLeaderboard::query()->when($latestLeaderboardDate, fn ($query) => $query->whereDate('leaderboard_date', $latestLeaderboardDate));
        $tenPercentCount = (clone $leaderboardQuery)->where('change_24h_percent', '>=', 10)->count();
        $matchedCount = (clone $leaderboardQuery)->where('change_24h_percent', '>=', 10)->where('matched_in_scan', true)->count();
        $dailyGainerStats = [
            'leaderboard_date' => $latestLeaderboardDate,
            'top_gainer' => (clone $leaderboardQuery)->orderByDesc('change_24h_percent')->first(['coindcx_symbol', 'change_24h_percent']),
            'total_count' => (clone $leaderboardQuery)->count(),
            'ten_percent_count' => $tenPercentCount,
            'matched_count' => $matchedCount,
            'selected_count' => (clone $leaderboardQuery)->where('selected_for_watchlist', true)->count(),
            'trade_plan_count' => (clone $leaderboardQuery)->where('trade_plan_created', true)->count(),
            'simulated_trade_count' => (clone $leaderboardQuery)->where('simulated_trade_created', true)->count(),
            'capture_rate' => $tenPercentCount > 0 ? ($matchedCount / $tenPercentCount) * 100 : null,
            'selection_rate' => $matchedCount > 0 ? ((clone $leaderboardQuery)->where('selected_for_watchlist', true)->count() / $matchedCount) * 100 : null,
        ];

        $latestAnalysisDate = MissedGainer::query()->max('analysis_date');
        $missedQuery = MissedGainer::query()->when($latestAnalysisDate, fn ($query) => $query->whereDate('analysis_date', $latestAnalysisDate));
        $missedGainerStats = [
            'analysis_date' => $latestAnalysisDate,
            'total_analyzed' => (clone $missedQuery)->count(),
            'missed_completely' => (clone $missedQuery)->where('miss_type', 'missed_completely')->count(),
            'captured_not_selected' => (clone $missedQuery)->where('miss_type', 'captured_not_selected')->count(),
            'selected_no_trade_plan' => (clone $missedQuery)->where('miss_type', 'selected_no_trade_plan')->count(),
            'trade_plan_not_triggered' => (clone $missedQuery)->where('miss_type', 'trade_plan_not_triggered')->count(),
            'captured_trade_created' => (clone $missedQuery)->where('miss_type', 'captured_trade_created')->count(),
            'critical_high' => (clone $missedQuery)->whereIn('miss_severity', ['critical', 'high'])->count(),
        ];

        $latestMissedGainers = (clone $missedQuery)
            ->orderByRaw("CASE miss_severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('leaderboard_rank')
            ->limit(10)
            ->get();

        $latestOpenTrades = SimulatedTrade::query()
            ->whereIn('status', $openTradeStatuses)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        $latestTradeEvents = TradeEvent::query()
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $latestHealth = Schema::hasTable('system_health_logs')
            ? SystemHealthLog::query()->orderByDesc('checked_at')->orderByDesc('id')->limit(100)->get()->unique('service_name')->values()
            : collect();

        return view('dashboard', [
            'dashboard' => compact(
                'latestScan',
                'scanStats',
                'marketSnapshot',
                'tradePlanStats',
                'simulatedTradeStats',
                'dailyGainerStats',
                'missedGainerStats',
                'latestMissedGainers',
                'latestOpenTrades',
                'latestTradeEvents',
                'latestHealth'
            ),
        ]);
    }
}
