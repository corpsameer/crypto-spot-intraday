<?php

namespace App\Http\Controllers;

use App\Models\CandidateWatchlist;
use App\Models\ScanRun;
use App\Models\TradePlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class WatchlistController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->watchlistFilters($request);

        $query = CandidateWatchlist::query()
            ->select(['id', 'spot_symbol_id', 'scanner_metric_id', 'coindcx_symbol', 'detected_at', 'candidate_type', 'entry_strategy', 'trigger_price', 'confirmation_price', 'last_price', 'score', 'status', 'reason', 'rejection_reason', 'expires_at', 'raw_payload', 'created_at', 'updated_at'])
            ->with(['spotSymbol:id,coindcx_symbol,base_asset,quote_asset', 'tradePlans:id,candidate_watchlist_id,status'])
            ->withCount([
                'tradePlans as pending_trade_plans_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'watching']),
            ]);

        $this->applyWatchlistFilters($query, $filters);
        $this->applyWatchlistSort($query, $filters['sort']);

        $candidates = $query->paginate($filters['per_page'])->withQueryString();
        $latestScanRun = ScanRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();

        return view('watchlist.index', [
            'candidates' => $candidates,
            'filters' => $filters,
            'summary' => $this->watchlistSummary($latestScanRun),
            'latestTradePlans' => TradePlan::query()
                ->select(['id', 'candidate_watchlist_id', 'coindcx_symbol', 'entry_strategy', 'status', 'score', 'score_label', 'trigger_price', 'entry_price', 'tp1_price', 'tp2_price', 'sl_price', 'expires_at', 'updated_at'])
                ->whereIn('status', ['pending', 'watching'])
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function tradePlans(Request $request): View
    {
        $filters = $this->tradePlanFilters($request);

        $query = TradePlan::query()
            ->select(['id', 'scan_run_id', 'scan_result_id', 'candidate_watchlist_id', 'spot_symbol_id', 'coindcx_symbol', 'base_asset', 'quote_asset', 'entry_strategy', 'status', 'score', 'score_label', 'reference_price', 'trigger_price', 'entry_price', 'tp1_price', 'tp2_price', 'sl_price', 'risk_reward_ratio', 'valid_from', 'expires_at', 'plan_reason', 'notes', 'raw_payload', 'updated_at'])
            ->with(['candidateWatchlist:id,coindcx_symbol,status,score', 'scanRun:id,scan_name,started_at']);

        $this->applyTradePlanFilters($query, $filters);
        $this->applyTradePlanSort($query, $filters['sort']);

        return view('trade-plans.index', [
            'tradePlans' => $query->paginate($filters['per_page'])->withQueryString(),
            'filters' => $filters,
            'summary' => $this->tradePlanSummary(),
        ]);
    }

    private function watchlistFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'all'),
            'score_label' => (string) $request->query('score_label', 'all'),
            'min_score' => $request->query('min_score'),
            'has_trade_plan' => (string) $request->query('has_trade_plan', 'all'),
            'sort' => (string) $request->query('sort', 'updated_desc'),
            'per_page' => in_array((int) $request->query('per_page', 25), [25, 50], true) ? (int) $request->query('per_page', 25) : 25,
        ];
    }

    private function tradePlanFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'all'),
            'entry_strategy' => (string) $request->query('entry_strategy', 'all'),
            'score_label' => (string) $request->query('score_label', 'all'),
            'min_score' => $request->query('min_score'),
            'expiry' => (string) $request->query('expiry', 'all'),
            'candidate_id' => $request->query('candidate_id'),
            'sort' => (string) $request->query('sort', 'updated_desc'),
            'per_page' => in_array((int) $request->query('per_page', 25), [25, 50], true) ? (int) $request->query('per_page', 25) : 25,
        ];
    }

    private function applyWatchlistFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(function (Builder $query) use ($like): void {
                $query->where('coindcx_symbol', 'like', $like)->orWhereHas('spotSymbol', fn (Builder $q) => $q->where('base_asset', 'like', $like)->orWhere('quote_asset', 'like', $like));
            });
        }
        if ($filters['status'] !== 'all') { $query->where('status', $filters['status']); }
        if ($filters['score_label'] !== 'all') { $query->where('raw_payload->score_label', $filters['score_label']); }
        if (is_numeric($filters['min_score'])) { $query->where('score', '>=', $filters['min_score']); }
        match ($filters['has_trade_plan']) {
            'yes' => $query->whereHas('tradePlans'),
            'no' => $query->whereDoesntHave('tradePlans'),
            default => null,
        };
    }

    private function applyTradePlanFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(fn (Builder $query) => $query->where('coindcx_symbol', 'like', $like)->orWhere('base_asset', 'like', $like)->orWhere('quote_asset', 'like', $like));
        }
        if ($filters['status'] !== 'all') { $query->where('status', $filters['status']); }
        if ($filters['entry_strategy'] !== 'all') { $query->where('entry_strategy', $filters['entry_strategy']); }
        if ($filters['score_label'] !== 'all') { $query->where('score_label', $filters['score_label']); }
        if (is_numeric($filters['min_score'])) { $query->where('score', '>=', $filters['min_score']); }
        if (is_numeric($filters['candidate_id'])) { $query->where('candidate_watchlist_id', $filters['candidate_id']); }
        match ($filters['expiry']) {
            'active' => $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            'expiring_1h' => $query->whereBetween('expires_at', [now(), now()->addHour()]),
            'expired' => $query->where('expires_at', '<=', now()),
            default => null,
        };
    }

    private function applyWatchlistSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'score_desc' => $query->orderByDesc('score')->orderBy('coindcx_symbol'),
            'symbol_asc' => $query->orderBy('coindcx_symbol'),
            'created_desc' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('updated_at'),
        };
    }

    private function applyTradePlanSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'score_desc' => $query->orderByDesc('score')->orderBy('coindcx_symbol'),
            'expires_asc' => $query->orderByRaw('expires_at IS NULL')->orderBy('expires_at'),
            'symbol_asc' => $query->orderBy('coindcx_symbol'),
            'trigger_price_asc' => $query->orderByRaw('trigger_price IS NULL')->orderBy('trigger_price'),
            default => $query->orderByDesc('updated_at'),
        };
    }

    private function watchlistSummary(?ScanRun $latestScanRun): array
    {
        $highest = CandidateWatchlist::query()->orderByDesc('score')->first(['id', 'coindcx_symbol', 'score']);
        return [
            'active_count' => CandidateWatchlist::query()->where('status', 'active')->count(),
            'refreshed_count' => CandidateWatchlist::query()->where('status', 'refreshed')->count(),
            'updated_today_count' => CandidateWatchlist::query()->whereDate('updated_at', today())->count(),
            'with_pending_trade_plans_count' => CandidateWatchlist::query()->whereHas('tradePlans', fn (Builder $q) => $q->whereIn('status', ['pending', 'watching']))->count(),
            'highest' => $highest,
            'latest_scan_run' => $latestScanRun,
        ];
    }

    private function tradePlanSummary(): array
    {
        $highest = TradePlan::query()->where('status', 'pending')->orderByDesc('score')->first(['id', 'coindcx_symbol', 'score']);
        return [
            'pending_count' => TradePlan::query()->where('status', 'pending')->count(),
            'watching_count' => TradePlan::query()->where('status', 'watching')->count(),
            'breakout_count' => TradePlan::query()->where('entry_strategy', 'breakout')->count(),
            'pullback_count' => TradePlan::query()->where('entry_strategy', 'pullback')->count(),
            'expiring_1h_count' => TradePlan::query()->whereBetween('expires_at', [now(), now()->addHour()])->count(),
            'expired_count' => TradePlan::query()->where(fn (Builder $q) => $q->where('status', 'expired')->orWhere('expires_at', '<=', now()))->count(),
            'highest_pending' => $highest,
        ];
    }

    public static function payloadValue(?array $payload, string $key, mixed $default = null): mixed
    {
        return Arr::get($payload ?? [], $key, $default);
    }
}
