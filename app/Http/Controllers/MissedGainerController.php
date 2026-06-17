<?php

namespace App\Http\Controllers;

use App\Models\MissedGainer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MissedGainerController extends Controller
{
    public function index(Request $request): View
    {
        $date = $request->string('date')->toString() ?: MissedGainer::query()->max('analysis_date') ?: now()->toDateString();
        $quoteFilter = strtoupper($request->string('quote_filter', 'USDT')->toString());

        $query = MissedGainer::query()->whereDate('analysis_date', $date)->where('quote_asset', $quoteFilter);
        foreach (['miss_type', 'miss_severity', 'action_needed'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->string($filter)->toString());
        }
        if ($request->filled('q')) $query->where('coindcx_symbol', 'like', '%'.strtoupper($request->string('q')->toString()).'%');
        if ($request->filled('min_change')) $query->where('actual_change_24h_percent', '>=', (float) $request->input('min_change'));

        $rows = $query->orderByRaw("FIELD(miss_severity, 'critical', 'high', 'medium', 'low', 'none')")
            ->orderBy('leaderboard_rank')
            ->paginate(100)
            ->withQueryString();

        $summaryBase = MissedGainer::query()->whereDate('analysis_date', $date)->where('quote_asset', $quoteFilter);
        $summary = [
            'rows_count' => (clone $summaryBase)->count(),
            'missed_completely' => (clone $summaryBase)->where('miss_type', 'missed_completely')->count(),
            'captured_not_selected' => (clone $summaryBase)->where('miss_type', 'captured_not_selected')->count(),
            'selected_no_trade_plan' => (clone $summaryBase)->where('miss_type', 'selected_no_trade_plan')->count(),
            'trade_plan_not_triggered' => (clone $summaryBase)->where('miss_type', 'trade_plan_not_triggered')->count(),
            'captured_trade_created' => (clone $summaryBase)->where('miss_type', 'captured_trade_created')->count(),
            'critical_high' => (clone $summaryBase)->whereIn('miss_severity', ['critical', 'high'])->count(),
        ];
        $filterOptions = [
            'missTypes' => MissedGainer::query()->whereNotNull('miss_type')->distinct()->orderBy('miss_type')->pluck('miss_type'),
            'severities' => MissedGainer::query()->whereNotNull('miss_severity')->distinct()->orderBy('miss_severity')->pluck('miss_severity'),
            'actions' => MissedGainer::query()->whereNotNull('action_needed')->distinct()->orderBy('action_needed')->pluck('action_needed'),
        ];

        return view('missed-gainers.index', compact('rows', 'summary', 'date', 'quoteFilter', 'filterOptions'));
    }
}
