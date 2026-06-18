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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DailyReviewController extends Controller
{
    public function index(Request $request): View
    {
        $quote = strtoupper((string) $request->query('quote', 'USDT'));
        $selectedDate = $request->query('date') ?: $this->defaultDate();
        $date = Carbon::parse($selectedDate)->toDateString();

        $scanRuns = ScanRun::query()->whereDate('started_at', $date)->orderByDesc('started_at')->get();
        $scanSummary = [
            'rows' => $scanRuns,
            'first_scan_time' => optional($scanRuns->sortBy('started_at')->first())->started_at,
            'last_scan_time' => optional($scanRuns->sortByDesc('started_at')->first())->started_at,
            'avg_duration' => $scanRuns->avg('duration_seconds'),
            'avg_selected' => $scanRuns->avg('watchlist_created_count'),
            'total_selected' => $scanRuns->sum('watchlist_created_count'),
        ];

        $marketContext = MarketSnapshot::query()->whereDate('snapshot_time', $date)->orderByDesc('snapshot_time')->first();

        $actualGainersCount = DailyGainerLeaderboard::query()
            ->whereDate('leaderboard_date', $date)->where('quote_asset', $quote)
            ->where('change_24h_percent', '>=', 10)->count();

        $topGainers = DailyGainerLeaderboard::query()
            ->whereDate('leaderboard_date', $date)->where('quote_asset', $quote)
            ->orderBy('rank')->limit(20)->get();

        $missedBase = MissedGainer::query()->whereDate('analysis_date', $date)->where('quote_asset', $quote);
        $missedRows = (clone $missedBase)->get();
        $missedReviewRows = (clone $missedBase)
            ->where(function ($query): void {
                $query->where('miss_type', '!=', 'captured_trade_created')
                    ->orWhereIn('miss_severity', ['critical', 'high', 'medium']);
            })
            ->orderByRaw("CASE miss_severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('leaderboard_rank')->limit(20)->get();

        $selectedCandidates = CandidateWatchlist::query()
            ->whereDate('created_at', $date)
            ->where(function ($query) use ($quote): void {
                $query->where('coindcx_symbol', 'like', '%'.$quote)->orWhere('coindcx_symbol', 'like', '%_'.$quote);
            })
            ->withCount('tradePlans')->orderByDesc('created_at')->limit(50)->get();

        if ($selectedCandidates->isEmpty()) {
            $selectedCandidates = ScanResult::query()
                ->whereHas('scanRun', fn ($query) => $query->whereDate('started_at', $date))
                ->where('selected_for_watchlist', true)->where('quote_asset', $quote)
                ->orderByDesc('evaluated_at')->limit(50)->get();
        }

        $tradePlans = TradePlan::query()->whereDate('created_at', $date)->where('quote_asset', $quote)->orderByDesc('created_at')->limit(50)->get();
        $simulatedTrades = SimulatedTrade::query()
            ->where('quote_asset', $quote)
            ->where(function ($query) use ($date): void {
                $query->whereDate('entry_triggered_at', $date)->orWhereDate('created_at', $date)->orWhereDate('closed_at', $date);
            })->orderByDesc(DB::raw('COALESCE(entry_triggered_at, created_at)'))->limit(50)->get();
        $tradeEvents = TradeEvent::query()->whereDate('event_time', $date)->orderByDesc('event_time')->limit(100)->get();

        $closedTrades = SimulatedTrade::query()->where('quote_asset', $quote)->whereDate('closed_at', $date)->get();
        $openTrades = SimulatedTrade::query()->where('quote_asset', $quote)->whereNotIn('status', ['closed', 'closed_tp1', 'closed_tp2', 'closed_sl', 'closed_trailing', 'expired', 'cancelled'])->where(function ($query) use ($date): void {
            $query->whereDate('entry_triggered_at', $date)->orWhereDate('created_at', $date);
        })->get();

        $healthLogs = SystemHealthLog::query()->whereDate('checked_at', $date)->orderByDesc('checked_at')->get();
        $expectedServices = ['scan_runner','daily_gainer_leaderboard','missed_gainer_analyzer','retention_cleanup','trade_plan_trigger_monitor','breakout_entry_simulator','pullback_entry_simulator','active_trade_monitor','trade_event_monitor','trailing_monitor','trade_expiry_monitor'];
        $healthSummary = collect($expectedServices)->map(function ($service) use ($healthLogs) {
            $logs = $healthLogs->where('service_name', $service);
            return ['service' => $service, 'latest' => $logs->first(), 'warnings' => $logs->where('status', 'warning')->count(), 'errors' => $logs->whereIn('status', ['error', 'failed'])->count()];
        });

        $eventCounts = $tradeEvents->countBy('event_type');
        $summaryStats = [
            'scan_runs' => $scanRuns->count(), 'successful_scans' => $scanRuns->whereIn('status', ['completed', 'success', 'ok'])->count(), 'failed_scans' => $scanRuns->whereIn('status', ['failed', 'error'])->count(),
            'actual_gainers' => $actualGainersCount, 'matched_in_scan' => $missedRows->where('matched_in_scan', true)->count(), 'selected_for_watchlist' => $missedRows->where('selected_for_watchlist', true)->count(),
            'trade_plans_created' => $missedRows->where('trade_plan_created', true)->count(), 'simulated_trades_created' => $missedRows->where('simulated_trade_created', true)->count(), 'open_trades' => $openTrades->count(), 'closed_trades' => $closedTrades->count(),
            'realized_pnl' => $closedTrades->sum('final_pnl_percent'), 'unrealized_pnl' => $openTrades->sum('current_pnl_percent'), 'tp1_hits' => $eventCounts->get('TP1_HIT', 0), 'tp2_hits' => $eventCounts->get('TP2_HIT', 0), 'sl_hits' => $eventCounts->get('SL_HIT', 0),
            'trailing_closes' => $eventCounts->get('TRAILING_STOP_HIT', 0), 'expired_trades' => $eventCounts->get('EXPIRED', 0), 'critical_high_items' => $missedRows->whereIn('miss_severity', ['critical', 'high'])->count(),
            'overall_health' => $healthLogs->whereIn('status', ['error', 'failed'])->isNotEmpty() ? 'error' : ($healthLogs->where('status', 'warning')->isNotEmpty() ? 'warning' : ($healthLogs->isEmpty() ? 'missing' : 'ok')),
        ];

        $missedSummary = $missedRows->countBy('miss_type');
        $reviewNotes = $this->reviewNotes($summaryStats, $missedSummary);

        return view('daily-review.index', compact('selectedDate', 'quote', 'summaryStats', 'scanSummary', 'marketContext', 'topGainers', 'missedSummary', 'missedReviewRows', 'selectedCandidates', 'tradePlans', 'simulatedTrades', 'tradeEvents', 'healthSummary', 'reviewNotes'));
    }

    private function defaultDate(): string
    {
        $latestLeaderboard = DailyGainerLeaderboard::query()->max('leaderboard_date');
        $latestMissed = MissedGainer::query()->max('analysis_date');
        return collect([$latestLeaderboard, $latestMissed])->filter()->max() ?: now()->toDateString();
    }

    private function reviewNotes(array $stats, $missedSummary): array
    {
        $notes = [];
        $rate = $stats['actual_gainers'] > 0 ? ($stats['matched_in_scan'] / $stats['actual_gainers']) * 100 : 0;
        if ($stats['actual_gainers'] > 0 && $rate >= 90) $notes[] = 'Scanner coverage was strong; most actual gainers appeared in scan results.';
        if (($missedSummary->get('captured_not_selected', 0)) > 0) $notes[] = 'Main review item: many gainers were captured but not selected. Review scoring thresholds/fallback later.';
        if (($missedSummary->get('missed_completely', 0)) > 0) $notes[] = 'Review prefilter or scan timing; some actual gainers did not appear in scan results.';
        if ($stats['selected_for_watchlist'] > 0 && $stats['trade_plans_created'] === 0) $notes[] = 'Review trade plan generation.';
        if (($missedSummary->get('trade_plan_not_triggered', 0)) > 0) $notes[] = 'Review entry trigger placement.';
        if ($stats['sl_hits'] > 0) $notes[] = 'Review SL distance and entry timing.';
        if ($stats['tp2_hits'] > 0 || $stats['trailing_closes'] > 0) $notes[] = 'Some trades reached the intended high-profit zone.';
        if (in_array($stats['overall_health'], ['warning', 'error'], true)) $notes[] = 'System health needs attention; review warnings/errors.';
        return $notes ?: ['No major deterministic review flags were found for this date.'];
    }
}
