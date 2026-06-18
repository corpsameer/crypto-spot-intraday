<?php

namespace App\Http\Controllers;

use App\Models\MissedGainer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MissedGainerController extends Controller
{
    public function index(Request $request): View
    {
        $latestDate = MissedGainer::query()->max('analysis_date');
        $date = $request->string('date')->toString() ?: ($latestDate ?: now()->toDateString());
        $quoteFilter = strtoupper($request->string('quote', $request->string('quote_filter', 'USDT')->toString())->toString());
        $perPage = in_array((int) $request->input('per_page', 25), [25, 50], true) ? (int) $request->input('per_page', 25) : 25;

        $query = $this->filteredQuery($request, $date, $quoteFilter)
            ->select([
                'id', 'analysis_date', 'leaderboard_id', 'leaderboard_rank', 'spot_symbol_id', 'coindcx_symbol', 'base_asset', 'quote_asset',
                'actual_change_24h_percent', 'actual_last_price', 'actual_quote_volume_24h', 'matched_in_scan', 'selected_for_watchlist',
                'trade_plan_created', 'simulated_trade_created', 'entry_triggered', 'best_scan_run_id', 'best_scan_result_id',
                'best_candidate_watchlist_id', 'best_trade_plan_id', 'best_simulated_trade_id', 'best_final_score', 'best_score_label',
                'miss_type', 'miss_reason', 'miss_severity', 'action_needed', 'analyzed_at',
            ])
            ->with(['leaderboard', 'spotSymbol', 'bestScanRun', 'bestScanResult', 'bestCandidateWatchlist', 'bestTradePlan', 'bestSimulatedTrade']);

        $this->applySorting($query, $request->string('sort', 'severity_rank')->toString());

        $rows = $query->paginate($perPage)->withQueryString();

        $summaryBase = $this->baseDateQuoteQuery($date, $quoteFilter);
        $topGainer = (clone $summaryBase)->orderByDesc('actual_change_24h_percent')->first(['coindcx_symbol', 'actual_change_24h_percent']);
        $summary = [
            'analysis_date' => $date,
            'rows_count' => (clone $summaryBase)->count(),
            'missed_completely' => (clone $summaryBase)->where('miss_type', 'missed_completely')->count(),
            'captured_not_selected' => (clone $summaryBase)->where('miss_type', 'captured_not_selected')->count(),
            'selected_no_trade_plan' => (clone $summaryBase)->where('miss_type', 'selected_no_trade_plan')->count(),
            'trade_plan_not_triggered' => (clone $summaryBase)->where('miss_type', 'trade_plan_not_triggered')->count(),
            'captured_trade_created' => (clone $summaryBase)->where('miss_type', 'captured_trade_created')->count(),
            'critical_high' => (clone $summaryBase)->whereIn('miss_severity', ['critical', 'high'])->count(),
            'top_gainer' => $topGainer,
            'avg_best_score' => (clone $summaryBase)->whereNotNull('best_final_score')->avg('best_final_score'),
        ];

        $quoteOptions = MissedGainer::query()->whereNotNull('quote_asset')->distinct()->orderBy('quote_asset')->pluck('quote_asset')->map(fn ($quote) => strtoupper($quote))->unique()->values();
        if ($quoteOptions->count() > 1) $quoteOptions->prepend('ALL');
        if (! $quoteOptions->contains('USDT')) $quoteOptions->prepend('USDT');

        return view('missed-gainers.index', [
            'rows' => $rows,
            'summary' => $summary,
            'date' => $date,
            'quoteFilter' => $quoteFilter,
            'quoteOptions' => $quoteOptions->unique()->values(),
            'missTypes' => ['all', 'missed_completely', 'captured_not_selected', 'selected_no_trade_plan', 'trade_plan_not_triggered', 'captured_trade_created', 'captured_trade_underperformed'],
            'severities' => ['all', 'critical', 'high', 'medium', 'low', 'none'],
            'actions' => ['all', 'review_prefilter_or_scan_timing', 'review_score_weights_thresholds', 'review_trade_plan_generation', 'review_entry_strategy_trigger_price', 'review_entry_timing', 'none'],
            'sortOptions' => ['severity_rank', 'leaderboard_rank', 'actual_change_desc', 'score_desc', 'analyzed_desc', 'symbol_asc'],
        ]);
    }

    public function show(MissedGainer $missedGainer): View
    {
        $missedGainer->load(['leaderboard', 'spotSymbol', 'bestScanRun', 'bestScanResult', 'bestCandidateWatchlist', 'bestTradePlan', 'bestSimulatedTrade']);
        return view('missed-gainers.show', compact('missedGainer'));
    }

    private function filteredQuery(Request $request, string $date, string $quoteFilter): Builder
    {
        $query = $this->baseDateQuoteQuery($date, $quoteFilter);

        foreach (['miss_type' => 'miss_type', 'severity' => 'miss_severity', 'action_needed' => 'action_needed'] as $param => $column) {
            $value = $request->string($param)->toString();
            if ($value !== '' && $value !== 'all') $query->where($column, $value);
        }

        foreach (['matched' => 'matched_in_scan', 'selected' => 'selected_for_watchlist', 'trade_plan' => 'trade_plan_created', 'simulated_trade' => 'simulated_trade_created'] as $param => $column) {
            $value = $request->string($param, 'all')->toString();
            if ($value === 'yes') $query->where($column, true);
            if ($value === 'no') $query->where($column, false);
        }

        if ($request->filled('q')) {
            $term = strtoupper($request->string('q')->toString());
            $query->where(fn (Builder $q) => $q->where('coindcx_symbol', 'like', "%{$term}%")->orWhere('base_asset', 'like', "%{$term}%")->orWhere('quote_asset', 'like', "%{$term}%"));
        }
        if ($request->filled('min_change')) $query->where('actual_change_24h_percent', '>=', (float) $request->input('min_change'));

        return $query;
    }

    private function baseDateQuoteQuery(string $date, string $quoteFilter): Builder
    {
        return MissedGainer::query()
            ->whereDate('analysis_date', $date)
            ->when($quoteFilter !== 'ALL', fn (Builder $query) => $query->where('quote_asset', $quoteFilter));
    }

    private function applySorting(Builder $query, string $sort): void
    {
        match ($sort) {
            'leaderboard_rank' => $query->orderBy('leaderboard_rank'),
            'actual_change_desc' => $query->orderByDesc('actual_change_24h_percent'),
            'score_desc' => $query->orderByDesc('best_final_score')->orderBy('leaderboard_rank'),
            'analyzed_desc' => $query->orderByDesc('analyzed_at'),
            'symbol_asc' => $query->orderBy('coindcx_symbol'),
            default => $query->orderByRaw("CASE miss_severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 WHEN 'none' THEN 5 ELSE 6 END")->orderBy('leaderboard_rank'),
        };
    }
}
