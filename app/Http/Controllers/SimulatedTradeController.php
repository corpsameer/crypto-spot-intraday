<?php

namespace App\Http\Controllers;

use App\Models\SimulatedTrade;
use App\Models\TradeEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SimulatedTradeController extends Controller
{
    private const OPEN_STATUSES = ['pending', 'active', 'tp1_hit', 'tp2_hit', 'trailing_active'];
    private const CLOSED_STATUSES = ['closed_sl', 'closed_tp1', 'closed_tp2', 'closed_trailing', 'expired', 'cancelled', 'error'];

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'all'),
            'entry_strategy' => (string) $request->query('entry_strategy', 'all'),
            'score_label' => (string) $request->query('score_label', 'all'),
            'trade_state' => (string) $request->query('trade_state', 'all'),
            'min_pnl' => $request->query('min_pnl'),
            'sort' => (string) $request->query('sort', 'updated_desc'),
            'per_page' => (int) $request->query('per_page', 25),
        ];
        $filters['per_page'] = in_array($filters['per_page'], [25, 50], true) ? $filters['per_page'] : 25;

        $query = SimulatedTrade::query()
            ->with(['tradePlan', 'candidateWatchlist', 'scanRun', 'scanResult', 'events' => fn ($query) => $query->orderBy('event_time')]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort']);

        $simulatedTrades = $query->paginate($filters['per_page'])->withQueryString();
        $summary = $this->summary();

        return view('simulated-trades.index', compact('simulatedTrades', 'summary', 'filters'));
    }

    public function show(SimulatedTrade $simulatedTrade): View
    {
        $simulatedTrade->load([
            'tradePlan',
            'candidateWatchlist',
            'scanRun',
            'scanResult',
            'spotSymbol',
            'scannerMetric',
            'events' => fn ($query) => $query->orderBy('event_time')->orderBy('id'),
        ]);

        return view('simulated-trades.show', compact('simulatedTrade'));
    }

    private function applyFilters($query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $query->where(function ($query) use ($filters): void {
                $query->where('coindcx_symbol', 'like', "%{$filters['q']}%")
                    ->orWhere('base_asset', 'like', "%{$filters['q']}%")
                    ->orWhere('quote_asset', 'like', "%{$filters['q']}%");
            });
        }

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if ($filters['entry_strategy'] !== 'all') {
            $query->where('entry_strategy', $filters['entry_strategy']);
        }

        if ($filters['score_label'] !== 'all') {
            $query->where('score_label', $filters['score_label']);
        }

        if ($filters['trade_state'] === 'open') {
            $query->whereIn('status', self::OPEN_STATUSES);
        } elseif ($filters['trade_state'] === 'closed') {
            $query->whereIn('status', self::CLOSED_STATUSES);
        }

        if ($filters['min_pnl'] !== null && $filters['min_pnl'] !== '' && is_numeric($filters['min_pnl'])) {
            $query->where('current_pnl_percent', '>=', (float) $filters['min_pnl']);
        }
    }

    private function applySort($query, string $sort): void
    {
        match ($sort) {
            'entry_time_desc' => $query->orderByDesc('entry_triggered_at')->orderByDesc('id'),
            'pnl_desc' => $query->orderByDesc('current_pnl_percent')->orderByDesc('updated_at'),
            'pnl_asc' => $query->orderBy('current_pnl_percent')->orderByDesc('updated_at'),
            'max_gain_desc' => $query->orderByDesc('max_gain_percent')->orderByDesc('updated_at'),
            'drawdown_asc' => $query->orderBy('max_drawdown_percent')->orderByDesc('updated_at'),
            'score_desc' => $query->orderByDesc('score')->orderByDesc('updated_at'),
            'symbol_asc' => $query->orderBy('coindcx_symbol')->orderByDesc('updated_at'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };
    }

    private function summary(): array
    {
        $openQuery = SimulatedTrade::query()->whereIn('status', self::OPEN_STATUSES);
        $closedQuery = SimulatedTrade::query()->whereIn('status', self::CLOSED_STATUSES);

        return [
            'open_count' => (clone $openQuery)->count(),
            'closed_count' => (clone $closedQuery)->count(),
            'tp1_hit_count' => SimulatedTrade::query()->whereNotNull('tp1_hit_at')->count(),
            'tp2_hit_count' => SimulatedTrade::query()->whereNotNull('tp2_hit_at')->count(),
            'sl_closed_count' => SimulatedTrade::query()->where('status', 'closed_sl')->count(),
            'unrealized_pnl' => (float) (clone $openQuery)->sum('current_pnl_percent'),
            'realized_pnl' => (float) (clone $closedQuery)->sum('final_pnl_percent'),
            'best_open_trade' => (clone $openQuery)->orderByDesc('max_gain_percent')->first(['id', 'coindcx_symbol', 'max_gain_percent']),
            'worst_open_trade' => (clone $openQuery)->orderBy('max_drawdown_percent')->first(['id', 'coindcx_symbol', 'max_drawdown_percent']),
            'latest_event' => TradeEvent::query()->orderByDesc('event_time')->first(['event_type', 'event_time', 'coindcx_symbol']),
        ];
    }
}
