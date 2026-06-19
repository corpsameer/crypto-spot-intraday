<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\PortfolioAccount;
use App\Models\PortfolioTransaction;
use App\Models\SimulatedTrade;
use App\Models\TradePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortfolioAnalyticsService
{
    private const OPEN_TRADE_STATUSES = ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'];
    private const PENDING_PLAN_STATUSES = ['pending', 'watching', 'triggered'];

    public function getActivePortfolio(): ?PortfolioAccount
    {
        return PortfolioAccount::query()->active()->orderBy('id')->first();
    }

    public function getSummary(PortfolioAccount $portfolio): array
    {
        return [
            'name' => $portfolio->name,
            'currency' => $portfolio->currency,
            'starting_capital' => (float) $portfolio->starting_capital,
            'current_cash' => (float) $portfolio->current_cash,
            'reserved_cash' => (float) $portfolio->reserved_cash,
            'deployed_capital' => (float) $portfolio->deployed_capital,
            'realized_pnl' => (float) $portfolio->realized_pnl,
            'unrealized_pnl' => (float) $portfolio->unrealized_pnl,
            'total_equity' => (float) $portfolio->total_equity,
            'total_return_percent' => (float) $portfolio->total_return_percent,
            'updated_at' => $portfolio->updated_at,
        ];
    }

    public function getCapitalUsage(PortfolioAccount $portfolio): array
    {
        $openTradesCount = $this->openTradesQuery($portfolio)->count();
        $pendingPlansCount = $this->pendingPlansQuery($portfolio)->count();
        $maxTotalOpenOpportunities = (int) $this->setting('portfolio.max_total_open_opportunities', (int) $portfolio->max_open_trades + (int) $portfolio->max_pending_trade_plans);
        $totalOpenOpportunities = $openTradesCount + $pendingPlansCount;
        $reserveCashPercent = (float) $portfolio->reserve_cash_percent;
        $reserveCashAmount = (float) $portfolio->total_equity * $reserveCashPercent / 100;

        return [
            'max_open_trades' => (int) $portfolio->max_open_trades,
            'open_trades_count' => $openTradesCount,
            'preferred_open_trades' => (int) $portfolio->preferred_open_trades,
            'max_pending_trade_plans' => (int) $portfolio->max_pending_trade_plans,
            'pending_trade_plans_count' => $pendingPlansCount,
            'max_total_open_opportunities' => $maxTotalOpenOpportunities,
            'total_open_opportunities' => $totalOpenOpportunities,
            'available_slots' => max($maxTotalOpenOpportunities - $totalOpenOpportunities, 0),
            'reserved_cash_percent' => (float) $portfolio->total_equity > 0 ? ((float) $portfolio->reserved_cash / (float) $portfolio->total_equity) * 100 : 0,
            'available_cash' => (float) $portfolio->current_cash - (float) $portfolio->reserved_cash,
            'reserve_cash_amount' => $reserveCashAmount,
            'tradable_cash' => max((float) $portfolio->current_cash - (float) $portfolio->reserved_cash - $reserveCashAmount, 0),
        ];
    }

    public function getMonthlyGrowth(PortfolioAccount $portfolio, ?Carbon $month = null): array
    {
        $month = ($month ?? now())->copy();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $priorRealized = PortfolioTransaction::query()
            ->where('portfolio_account_id', $portfolio->id)
            ->where('transaction_type', 'trade_exit')
            ->where('transaction_time', '<', $start)
            ->sum('amount');
        $startingEquity = (float) $portfolio->starting_capital + (float) $priorRealized;
        if ($startingEquity <= 0) $startingEquity = (float) $portfolio->starting_capital;

        $closed = SimulatedTrade::query()->where('portfolio_account_id', $portfolio->id)->whereBetween('capital_released_at', [$start, $end])->get();
        $pnl = fn ($trade) => (float) ($trade->net_pnl_amount ?? $trade->realized_pnl_amount ?? 0);
        $monthlyRealized = $closed->sum($pnl);
        $best = $closed->sortByDesc($pnl)->first();
        $worst = $closed->sortBy($pnl)->first();
        $winning = $closed->filter(fn ($trade) => $pnl($trade) > 0)->count();
        $losing = $closed->filter(fn ($trade) => $pnl($trade) < 0)->count();

        return [
            'month' => $month->format('F Y'),
            'starting_equity' => $startingEquity,
            'current_equity' => (float) $portfolio->total_equity,
            'monthly_return_percent' => $startingEquity > 0 ? (((float) $portfolio->total_equity - $startingEquity) / $startingEquity) * 100 : 0,
            'monthly_realized_pnl' => $monthlyRealized,
            'monthly_unrealized_pnl' => (float) $portfolio->unrealized_pnl,
            'monthly_closed_trades' => $closed->count(),
            'monthly_winning_trades' => $winning,
            'monthly_losing_trades' => $losing,
            'monthly_win_rate' => $closed->count() > 0 ? ($winning / $closed->count()) * 100 : null,
            'best_trade' => $best,
            'best_trade_pnl' => $best ? $pnl($best) : null,
            'worst_trade' => $worst,
            'worst_trade_pnl' => $worst ? $pnl($worst) : null,
            'estimated_note' => 'Monthly start equity is estimated from available portfolio transactions.',
        ];
    }

    public function getOpenTrades(PortfolioAccount $portfolio) { return $this->openTradesQuery($portfolio)->orderByDesc('entry_triggered_at')->orderByDesc('id')->get(); }
    public function getPendingPlans(PortfolioAccount $portfolio) { return $this->pendingPlansQuery($portfolio)->orderByDesc('capital_reserved_at')->orderByDesc('id')->get(); }

    public function getRecentClosedTrades(PortfolioAccount $portfolio, int $limit = 20)
    {
        return SimulatedTrade::query()->where('portfolio_account_id', $portfolio->id)->whereNotNull('closed_at')
            ->where(fn ($q) => $q->where('close_reason', '!=', 'expiry')->orWhereNotNull('capital_released_at'))
            ->orderByDesc(DB::raw('COALESCE(capital_released_at, closed_at)'))->limit($limit)->get();
    }

    public function getRecentTransactions(PortfolioAccount $portfolio, int $limit = 30)
    {
        return PortfolioTransaction::query()->with(['tradePlan:id,coindcx_symbol', 'simulatedTrade:id,coindcx_symbol'])
            ->where('portfolio_account_id', $portfolio->id)->whereIn('transaction_type', ['capital_reserved', 'trade_entry', 'capital_released', 'trade_exit'])
            ->orderByDesc('transaction_time')->orderByDesc('id')->limit($limit)->get();
    }

    public function getAllocationSummary(PortfolioAccount $portfolio, ?Carbon $month = null): array
    {
        $month = ($month ?? now())->copy(); $start = $month->startOfMonth(); $end = $month->copy()->endOfMonth();
        $plans = TradePlan::query()->where('portfolio_account_id', $portfolio->id)->whereBetween('created_at', [$start, $end])->get();
        return [
            'month' => $month->format('F Y'),
            'by_setup_type' => $plans->groupBy(fn ($p) => $p->setup_type ?? $p->entry_strategy ?? 'unknown')->map(fn ($rows) => ['count' => $rows->count(), 'allocated' => (float) $rows->sum('allocated_capital')])->sortKeys(),
            'by_score_bucket' => $plans->groupBy(fn ($p) => $this->scoreBucket($p->score))->map(fn ($rows) => ['count' => $rows->count(), 'allocated' => (float) $rows->sum('allocated_capital')])->sortKeys(),
            'by_symbol' => $plans->groupBy('coindcx_symbol')->map(fn ($rows) => ['count' => $rows->count(), 'allocated' => (float) $rows->sum('allocated_capital')])->sortByDesc('allocated')->take(20),
            'portfolio_status_counts' => $plans->groupBy(fn ($p) => $p->portfolio_status ?: 'unknown')->map->count()->sortKeys(),
            'rejection_reasons' => $plans->whereNotNull('portfolio_rejection_reason')->groupBy('portfolio_rejection_reason')->map->count()->sortDesc(),
        ];
    }

    public function getReconciliation(PortfolioAccount $portfolio): array
    {
        $deployed = (float) $this->openTradesQuery($portfolio)->sum('allocated_capital');
        $unrealized = (float) $this->openTradesQuery($portfolio)->sum('unrealized_pnl_amount');
        $reserved = (float) TradePlan::query()->where('portfolio_account_id', $portfolio->id)->where('portfolio_status', 'capital_reserved')->whereNull('capital_released_at')->sum('allocated_capital');
        $mismatch = abs((float)$portfolio->deployed_capital - $deployed) > 1 || abs((float)$portfolio->unrealized_pnl - $unrealized) > 1 || abs((float)$portfolio->reserved_cash - $reserved) > 1;
        return compact('deployed', 'unrealized', 'reserved', 'mismatch');
    }

    private function openTradesQuery(PortfolioAccount $portfolio) { return SimulatedTrade::query()->where('portfolio_account_id', $portfolio->id)->whereIn('status', self::OPEN_TRADE_STATUSES)->whereNull('closed_at'); }
    private function pendingPlansQuery(PortfolioAccount $portfolio) { return TradePlan::query()->where('portfolio_account_id', $portfolio->id)->whereIn('status', self::PENDING_PLAN_STATUSES)->whereIn('portfolio_status', ['capital_reserved', 'approved'])->whereNull('converted_at'); }
    private function setting(string $key, int $default): int { return (int) (AppSetting::query()->where('key', $key)->value('value') ?? $default); }
    private function scoreBucket($score): string { if ($score === null) return 'unknown'; $score = (float) $score; return $score >= 80 ? '80+' : ($score >= 60 ? '60-79' : ($score >= 40 ? '40-59' : '<40')); }
}
