<?php

namespace App\Http\Controllers;

use App\Models\DailyGainerLeaderboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DailyGainerLeaderboardController extends Controller
{
    public function index(Request $request): View
    {
        $date = $request->string('date')->toString() ?: DailyGainerLeaderboard::query()->max('leaderboard_date');
        if (! $date) {
            $date = now()->toDateString();
        }
        $quoteFilter = strtoupper($request->string('quote_filter', 'USDT')->toString());

        $query = DailyGainerLeaderboard::query()
            ->whereDate('leaderboard_date', $date)
            ->where('quote_filter', $quoteFilter);

        if ($request->filled('q')) {
            $q = strtoupper($request->string('q')->toString());
            $query->where('coindcx_symbol', 'like', "%{$q}%");
        }
        foreach (['matched_in_scan', 'selected_for_watchlist'] as $filter) {
            if (in_array($request->string($filter)->toString(), ['yes', 'no'], true)) {
                $query->where($filter, $request->string($filter)->toString() === 'yes');
            }
        }
        if ($request->filled('min_change')) {
            $query->where('change_24h_percent', '>=', (float) $request->input('min_change'));
        }

        match ($request->string('sort', 'rank')->toString()) {
            'change' => $query->orderByDesc('change_24h_percent'),
            'volume' => $query->orderByDesc('quote_volume_24h'),
            default => $query->orderBy('rank'),
        };

        $rows = $query->paginate(100)->withQueryString();
        $summaryBase = DailyGainerLeaderboard::query()->whereDate('leaderboard_date', $date)->where('quote_filter', $quoteFilter);
        $top = (clone $summaryBase)->orderBy('rank')->first();
        $summary = [
            'leaderboard_date' => $date,
            'top_gainer' => $top?->coindcx_symbol,
            'top_change' => $top?->change_24h_percent,
            'rows_count' => (clone $summaryBase)->count(),
            'matched_in_scan_count' => (clone $summaryBase)->where('matched_in_scan', true)->count(),
            'selected_for_watchlist_count' => (clone $summaryBase)->where('selected_for_watchlist', true)->count(),
            'trade_plan_created_count' => (clone $summaryBase)->where('trade_plan_created', true)->count(),
        ];

        return view('daily-gainers.index', compact('rows', 'summary', 'date', 'quoteFilter'));
    }
}
