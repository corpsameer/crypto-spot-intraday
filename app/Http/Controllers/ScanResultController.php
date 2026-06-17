<?php

namespace App\Http\Controllers;

use App\Models\ScanRun;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class ScanResultController extends Controller
{
    public function latest(Request $request): View
    {
        $scanRun = ScanRun::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if (! $scanRun) {
            return view('scan-results.index', [
                'scanRun' => null,
                'recentScanRuns' => collect(),
                'summary' => [],
                'marketContext' => null,
                'results' => null,
                'rejectionReasons' => collect(),
                'filters' => $this->filters($request),
            ]);
        }

        return $this->show($request, $scanRun);
    }

    public function show(Request $request, ScanRun $scanRun): View
    {
        $filters = $this->filters($request);
        $summary = $this->summary($scanRun);

        $query = $scanRun->scanResults()->select([
            'id', 'scan_run_id', 'coindcx_symbol', 'base_asset', 'quote_asset', 'status',
            'prefilter_passed', 'score_passed', 'selected_for_watchlist', 'candidate_created', 'candidate_watchlist_id', 'selection_type',
            'selection_rank', 'selection_reason', 'prefilter_reason', 'rejection_reason',
            'quote_volume_24h', 'change_15m_percent', 'change_1h_percent', 'change_4h_percent', 'change_24h_percent',
            'volume_spike_15m', 'volume_spike_1h', 'spread_percent', 'orderbook_depth_usdt',
            'distance_from_24h_high_percent', 'candle_close_strength', 'relative_strength_vs_btc',
            'risk_penalty', 'final_score', 'score_label', 'score_breakdown', 'raw_payload', 'evaluated_at',
        ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort']);

        $results = $query->paginate($filters['per_page'])->withQueryString();

        return view('scan-results.index', [
            'scanRun' => $scanRun,
            'recentScanRuns' => ScanRun::query()->orderByDesc('id')->limit(20)->get(),
            'summary' => $summary,
            'marketContext' => Arr::get($scanRun->raw_payload ?? [], 'market_context'),
            'results' => $results,
            'rejectionReasons' => $scanRun->scanResults()
                ->whereNotNull('rejection_reason')
                ->distinct()
                ->orderBy('rejection_reason')
                ->pluck('rejection_reason'),
            'filters' => $filters,
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'all'),
            'selection' => (string) $request->query('selection', 'all'),
            'candidate_created' => (string) $request->query('candidate_created', 'all'),
            'score_label' => (string) $request->query('score_label', 'all'),
            'rejection_reason' => (string) $request->query('rejection_reason', 'all'),
            'min_score' => $request->query('min_score'),
            'sort' => (string) $request->query('sort', 'final_score_desc'),
            'per_page' => in_array((int) $request->query('per_page', 25), [25, 50], true) ? (int) $request->query('per_page', 25) : 25,
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $query->where(function ($query) use ($filters): void {
                $like = '%' . $filters['q'] . '%';
                $query->where('coindcx_symbol', 'like', $like)
                    ->orWhere('base_asset', 'like', $like)
                    ->orWhere('quote_asset', 'like', $like);
            });
        }

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        match ($filters['selection']) {
            'selected' => $query->where('selected_for_watchlist', true),
            'threshold' => $query->where('selection_type', 'threshold'),
            'fallback' => $query->where('selection_type', 'fallback'),
            'not_selected' => $query->where('selected_for_watchlist', false),
            default => null,
        };

        match ($filters['candidate_created']) {
            'yes' => $query->where('candidate_created', true),
            'no' => $query->where('candidate_created', false),
            default => null,
        };

        if ($filters['score_label'] !== 'all') {
            $query->where('score_label', $filters['score_label']);
        }

        if ($filters['rejection_reason'] !== 'all') {
            $query->where('rejection_reason', $filters['rejection_reason']);
        }

        if (is_numeric($filters['min_score'])) {
            $query->where('final_score', '>=', $filters['min_score']);
        }
    }

    private function applySort($query, string $sort): void
    {
        match ($sort) {
            'final_score_asc' => $query->orderBy('final_score')->orderBy('coindcx_symbol'),
            'volume_desc' => $query->orderByDesc('quote_volume_24h')->orderBy('coindcx_symbol'),
            'change_1h_desc' => $query->orderByDesc('change_1h_percent')->orderBy('coindcx_symbol'),
            'change_15m_desc' => $query->orderByDesc('change_15m_percent')->orderBy('coindcx_symbol'),
            'volume_spike_desc' => $query->orderByDesc('volume_spike_15m')->orderByDesc('volume_spike_1h'),
            'spread_asc' => $query->orderBy('spread_percent')->orderBy('coindcx_symbol'),
            'selection_rank_asc' => $query->orderByRaw('selection_rank IS NULL')->orderBy('selection_rank')->orderByDesc('final_score'),
            'symbol_asc' => $query->orderBy('coindcx_symbol'),
            default => $query->orderByDesc('final_score')->orderBy('coindcx_symbol'),
        };
    }

    private function summary(ScanRun $scanRun): array
    {
        $counts = $scanRun->scanResults()
            ->selectRaw('COUNT(*) as total_results')
            ->selectRaw("SUM(CASE WHEN status = 'prefilter_rejected' THEN 1 ELSE 0 END) as prefilter_rejected_count")
            ->selectRaw('SUM(CASE WHEN prefilter_passed = 1 THEN 1 ELSE 0 END) as prefilter_passed_count')
            ->selectRaw("SUM(CASE WHEN status = 'candles_fetched' THEN 1 ELSE 0 END) as candles_fetched_count")
            ->selectRaw("SUM(CASE WHEN status = 'metrics_calculated' THEN 1 ELSE 0 END) as metrics_calculated_count")
            ->selectRaw("SUM(CASE WHEN status = 'scored' THEN 1 ELSE 0 END) as scored_count")
            ->selectRaw('SUM(CASE WHEN selected_for_watchlist = 1 THEN 1 ELSE 0 END) as selected_for_watchlist_count')
            ->selectRaw('SUM(CASE WHEN candidate_created = 1 THEN 1 ELSE 0 END) as candidate_created_count')
            ->selectRaw("SUM(CASE WHEN selection_type = 'threshold' THEN 1 ELSE 0 END) as threshold_selected_count")
            ->selectRaw("SUM(CASE WHEN selection_type = 'fallback' THEN 1 ELSE 0 END) as fallback_selected_count")
            ->selectRaw('SUM(CASE WHEN score_passed = 1 THEN 1 ELSE 0 END) as score_passed_count')
            ->first();

        $payload = $scanRun->raw_payload ?? [];

        return [
            'total_results' => (int) ($counts->total_results ?? 0),
            'prefilter_rejected_count' => (int) ($counts->prefilter_rejected_count ?? 0),
            'prefilter_passed_count' => (int) ($scanRun->prefilter_passed_count ?: $counts->prefilter_passed_count),
            'candles_fetched_count' => (int) ($scanRun->candles_fetched_count ?: $counts->candles_fetched_count),
            'metrics_calculated_count' => (int) ($scanRun->metrics_calculated_count ?: $counts->metrics_calculated_count),
            'scored_count' => (int) ($scanRun->scored_count ?: $counts->scored_count),
            'selected_for_watchlist_count' => (int) ($counts->selected_for_watchlist_count ?? 0),
            'candidate_created_count' => (int) ($counts->candidate_created_count ?? 0),
            'threshold_selected_count' => (int) (Arr::get($payload, 'selection_summary.threshold_selected_count') ?? $counts->threshold_selected_count ?? 0),
            'fallback_selected_count' => (int) (Arr::get($payload, 'selection_summary.fallback_selected_count') ?? $counts->fallback_selected_count ?? 0),
            'score_passed_count' => (int) ($counts->score_passed_count ?? 0),
        ];
    }
}
