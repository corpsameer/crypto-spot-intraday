<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScannerPerformanceController extends Controller
{
    public function index(Request $request): View
    {
        $quote = strtoupper((string) $request->query('quote', 'USDT')) ?: 'USDT';
        $minChange = (float) $request->query('min_change', 10);
        $status = $request->query('status');

        $latestStartedAt = Schema::hasTable('scan_runs') ? DB::table('scan_runs')->max('started_at') : null;
        $defaultTo = $latestStartedAt ? Carbon::parse($latestStartedAt)->toDateString() : now()->toDateString();
        $defaultFrom = $latestStartedAt ? Carbon::parse($latestStartedAt)->subDays(6)->toDateString() : now()->subDays(6)->toDateString();

        $from = Carbon::parse($request->query('from', $defaultFrom))->startOfDay();
        $to = Carbon::parse($request->query('to', $defaultTo))->endOfDay();
        $dateFrom = $from->toDateString();
        $dateTo = $to->toDateString();

        $scanRuns = DB::table('scan_runs')
            ->whereBetween('started_at', [$from, $to])
            ->when($quote !== 'ALL', fn ($query) => $query->where(function ($q) use ($quote) {
                $q->where('quote_filter', $quote)->orWhereNull('quote_filter');
            }))
            ->when($status, fn ($query) => $query->where('status', $status));

        $runIds = (clone $scanRuns)->pluck('id');
        $runCount = $runIds->count();
        $successfulStatuses = ['ok', 'success', 'completed'];
        $failedStatuses = ['error', 'failed'];

        $scanResultBase = DB::table('scan_results')->whereIn('scan_run_id', $runIds)
            ->when($quote !== 'ALL', fn ($query) => $query->where('quote_asset', $quote));

        $scanResultsCount = (clone $scanResultBase)->count();
        $prefilterCount = (clone $scanResultBase)->where('prefilter_passed', true)->count();
        $scoredCount = (clone $scanResultBase)->whereNotNull('final_score')->count();
        $selectedCount = (clone $scanResultBase)->where('selected_for_watchlist', true)->count();

        $dailyBase = DB::table('daily_gainer_leaderboard')
            ->whereBetween('leaderboard_date', [$dateFrom, $dateTo])
            ->where('change_24h_percent', '>=', $minChange)
            ->when($quote !== 'ALL', fn ($query) => $query->where('quote_filter', $quote));
        $actualGainers = (clone $dailyBase)->count();

        $missedBase = DB::table('missed_gainers')
            ->whereBetween('analysis_date', [$dateFrom, $dateTo])
            ->where('actual_change_24h_percent', '>=', $minChange)
            ->when($quote !== 'ALL', fn ($query) => $query->where('quote_asset', $quote));
        $analyzed = (clone $missedBase)->count();
        $matched = (clone $missedBase)->where('matched_in_scan', true)->count();
        $missedSelected = (clone $missedBase)->where('selected_for_watchlist', true)->count();
        $missedPlans = (clone $missedBase)->where('trade_plan_created', true)->count();

        $scanRunStats = [
            'total_scan_runs' => $runCount,
            'successful_scan_runs' => (clone $scanRuns)->whereIn('status', $successfulStatuses)->count(),
            'failed_scan_runs' => (clone $scanRuns)->where(function ($query) use ($failedStatuses) {
                $query->whereIn('status', $failedStatuses)->orWhereNotNull('error_message');
            })->count(),
            'avg_active_symbols' => $runCount ? (clone $scanRuns)->avg('total_active_symbols') : null,
            'avg_prefilter_passed' => $runCount ? (clone $scanRuns)->avg('prefilter_passed_count') : null,
            'avg_scored' => $runCount ? (clone $scanRuns)->avg('scored_count') : null,
            'avg_selected' => $runCount ? $selectedCount / $runCount : null,
            'avg_duration_seconds' => $runCount ? (clone $scanRuns)->avg('duration_seconds') : null,
            'actual_gainers' => $actualGainers,
            'capture_rate' => $this->percent($matched, $analyzed),
            'selection_rate' => $this->percent($missedSelected, $matched),
            'trade_plan_conversion_rate' => $this->percent($missedPlans, $missedSelected),
        ];

        $watchlistCount = DB::table('candidate_watchlists')->whereBetween('detected_at', [$from, $to])->count();
        $planBase = DB::table('trade_plans')->where(function ($q) use ($from, $to, $runIds) {
            $q->whereBetween('created_at', [$from, $to])->orWhereIn('scan_run_id', $runIds);
        })->when($quote !== 'ALL', fn ($query) => $query->where('quote_asset', $quote));
        $tradeBase = DB::table('simulated_trades')->where(function ($q) use ($from, $to, $runIds) {
            $q->whereBetween('created_at', [$from, $to])->orWhereIn('scan_run_id', $runIds);
        })->when($quote !== 'ALL', fn ($query) => $query->where('quote_asset', $quote));
        $eventBase = DB::table('trade_events')->whereBetween('event_time', [$from, $to]);

        $funnelCounts = [
            ['Scan results', $scanResultsCount, 'All symbols recorded in scan_results.'],
            ['Prefilter passed', $prefilterCount, 'Rows with prefilter_passed=true.'],
            ['Scored', $scoredCount, 'Rows with final_score populated.'],
            ['Selected', $selectedCount, 'Rows selected_for_watchlist=true.'],
            ['Watchlist rows', $watchlistCount, 'Candidate watchlist rows detected in date range.'],
            ['Trade plans', (clone $planBase)->count(), 'Trade plans created from selected candidates.'],
            ['Triggered plans', (clone $planBase)->whereNotNull('triggered_at')->count(), 'Plans with triggered_at populated.'],
            ['Simulated trades', (clone $tradeBase)->count(), 'Simulated trades created.'],
            ['TP1 hit', (clone $eventBase)->where('event_type', 'like', '%TP1%')->count(), 'Trade events containing TP1.'],
            ['TP2 hit', (clone $eventBase)->where('event_type', 'like', '%TP2%')->count(), 'Trade events containing TP2.'],
            ['SL closed', (clone $tradeBase)->where('close_reason', 'like', '%sl%')->count(), 'Closed with stop-loss reason.'],
            ['Trailing closed', (clone $tradeBase)->where('close_reason', 'like', '%trail%')->count(), 'Closed by trailing stop.'],
            ['Expired', (clone $tradeBase)->where('status', 'like', '%expired%')->count(), 'Expired simulated trades.'],
        ];
        $funnelStats = $this->buildFunnel($funnelCounts);

        $dailyCaptureStats = $this->dailyCaptureStats($dateFrom, $dateTo, $quote, $minChange);
        $missedReasonStats = [
            'miss_type' => $this->groupMissed($missedBase, 'miss_type'),
            'miss_reason' => $this->groupMissed($missedBase, 'miss_reason'),
            'action_needed' => $this->groupMissed($missedBase, 'action_needed'),
            'miss_severity' => $this->groupMissed($missedBase, 'miss_severity'),
        ];

        $latestScanRuns = (clone $scanRuns)->orderByDesc('started_at')->limit(50)->get()->map(function ($run) use ($quote) {
            $base = DB::table('scan_results')->where('scan_run_id', $run->id)->when($quote !== 'ALL', fn ($query) => $query->where('quote_asset', $quote));
            $run->scored_count_actual = (clone $base)->whereNotNull('final_score')->count();
            $run->selected_count_actual = (clone $base)->where('selected_for_watchlist', true)->count();
            $run->top_selected_symbol = (clone $base)->where('selected_for_watchlist', true)->orderByDesc('final_score')->value('coindcx_symbol');
            return $run;
        });

        $worstMissedGainers = (clone $missedBase)
            ->where('miss_type', '!=', 'captured_trade_created')
            ->orderByRaw("CASE miss_severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderByDesc('actual_change_24h_percent')
            ->orderBy('leaderboard_rank')
            ->limit(20)
            ->get();

        $scanHealthStats = $this->healthStats($from, $to);

        return view('analytics.scanner-performance', compact('scanRunStats', 'funnelStats', 'dailyCaptureStats', 'missedReasonStats', 'latestScanRuns', 'worstMissedGainers', 'scanHealthStats', 'quote', 'minChange', 'status', 'dateFrom', 'dateTo'));
    }

    private function percent(int|float $num, int|float $den): ?float { return $den > 0 ? ($num / $den) * 100 : null; }

    private function buildFunnel(array $rows): array
    {
        $start = $rows[0][1] ?? 0; $previous = null;
        return array_map(function ($row) use (&$previous, $start) {
            [$stage, $count, $notes] = $row;
            $item = ['stage' => $stage, 'count' => $count, 'from_previous' => $previous === null ? null : $this->percent($count, $previous), 'from_start' => $this->percent($count, $start), 'notes' => $notes];
            $previous = $count; return $item;
        }, $rows);
    }

    private function dailyCaptureStats(string $from, string $to, string $quote, float $minChange)
    {
        $days = collect();
        $daily = DB::table('daily_gainer_leaderboard')->selectRaw('leaderboard_date, COUNT(*) actual_gainers, MAX(change_24h_percent) top_change')
            ->whereBetween('leaderboard_date', [$from, $to])->where('change_24h_percent', '>=', $minChange)
            ->when($quote !== 'ALL', fn ($q) => $q->where('quote_filter', $quote))->groupBy('leaderboard_date')->get()->keyBy('leaderboard_date');
        $missed = DB::table('missed_gainers')->selectRaw('analysis_date, COUNT(*) analyzed, SUM(matched_in_scan = 1) matched, SUM(selected_for_watchlist = 1) selected, SUM(trade_plan_created = 1) trade_plans, SUM(simulated_trade_created = 1) sim_trades, SUM(matched_in_scan = 0) missed_completely, SUM(miss_type = ?) captured_not_selected', ['captured_not_selected'])
            ->whereBetween('analysis_date', [$from, $to])->where('actual_change_24h_percent', '>=', $minChange)
            ->when($quote !== 'ALL', fn ($q) => $q->where('quote_asset', $quote))->groupBy('analysis_date')->get()->keyBy('analysis_date');
        $symbols = DB::table('daily_gainer_leaderboard')->select('leaderboard_date', 'coindcx_symbol', 'change_24h_percent')->whereBetween('leaderboard_date', [$from, $to])->where('change_24h_percent', '>=', $minChange)->when($quote !== 'ALL', fn ($q) => $q->where('quote_filter', $quote))->orderByDesc('change_24h_percent')->get()->groupBy('leaderboard_date')->map->first();
        foreach ($daily->keys()->merge($missed->keys())->unique()->sortDesc() as $date) {
            $d = $daily->get($date); $m = $missed->get($date); $top = $symbols->get($date);
            $actual = (int) ($d->actual_gainers ?? $m->analyzed ?? 0); $matched = (int) ($m->matched ?? 0); $selected = (int) ($m->selected ?? 0);
            $days->push(['date' => $date, 'actual_gainers' => $actual, 'matched' => $matched, 'capture_rate' => $this->percent($matched, $actual), 'selected' => $selected, 'selection_rate' => $this->percent($selected, $matched), 'trade_plans' => (int) ($m->trade_plans ?? 0), 'sim_trades' => (int) ($m->sim_trades ?? 0), 'missed_completely' => (int) ($m->missed_completely ?? 0), 'captured_not_selected' => (int) ($m->captured_not_selected ?? 0), 'top_gainer' => $top->coindcx_symbol ?? '-', 'top_change' => $top->change_24h_percent ?? null]);
        }
        return $days;
    }

    private function groupMissed($base, string $column)
    {
        return (clone $base)->selectRaw("COALESCE($column, 'unknown') label, COUNT(*) count, AVG(actual_change_24h_percent) avg_change, MAX(actual_change_24h_percent) max_change, AVG(best_final_score) avg_best_score")
            ->groupBy($column)->orderByDesc('count')->get();
    }

    private function healthStats(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('system_health_logs')) return ['latest' => collect(), 'errors' => 0, 'warnings' => 0];
        $base = DB::table('system_health_logs')->whereBetween('checked_at', [$from, $to]);
        $services = ['scan_runner', 'daily_gainer_leaderboard', 'missed_gainer_analyzer'];
        return ['latest' => DB::table('system_health_logs')->whereIn('service_name', $services)->orderByDesc('checked_at')->get()->unique('service_name')->values(), 'errors' => (clone $base)->whereIn('status', ['error', 'failed'])->count(), 'warnings' => (clone $base)->whereIn('status', ['warning', 'partial'])->count()];
    }
}
