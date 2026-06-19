<?php

namespace App\Services;

use App\Models\PortfolioAccount;
use App\Models\SystemHealthLog;
use Illuminate\Support\Facades\DB;

class PortfolioReconciliationService
{
    private const OPEN_TRADE_STATUSES = ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'];
    private const PENDING_PLAN_STATUSES = ['pending', 'watching', 'triggered'];
    private const MATERIAL_MONEY_DIFF = 1.00;
    private const MATERIAL_PERCENT_DIFF = 0.01;

    public function reconcile(?int $portfolioId = null, bool $fix = false, bool $logHealth = true): array
    {
        $portfolios = PortfolioAccount::query()
            ->when($portfolioId, fn ($query) => $query->whereKey($portfolioId), fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();

        $reports = $portfolios->map(fn (PortfolioAccount $portfolio) => $this->reconcilePortfolio($portfolio, $fix))->values()->all();
        $status = $this->overallStatus($reports);

        $summary = [
            'status' => $status,
            'portfolio_count' => count($reports),
            'fixed' => $fix,
            'reports' => $reports,
        ];

        if ($logHealth) $this->logHealth($status, $summary);

        return count($reports) === 1
            ? array_merge($reports[0], ['all_reports' => $reports])
            : $summary;
    }

    private function reconcilePortfolio(PortfolioAccount $portfolio, bool $fix): array
    {
        $reservedPlans = $this->reservedPlansQuery($portfolio)->get(['id', 'coindcx_symbol', 'status', 'allocated_capital']);
        $openTrades = $this->openTradesQuery($portfolio)->get(['id', 'coindcx_symbol', 'status', 'allocated_capital', 'unrealized_pnl_amount', 'current_value']);

        $expectedReserved = round($reservedPlans->sum(fn ($plan) => (float) $plan->allocated_capital), 2);
        $expectedDeployed = round($openTrades->sum(fn ($trade) => (float) $trade->allocated_capital), 2);
        $expectedUnrealized = round($openTrades->sum(function ($trade) {
            if ($trade->unrealized_pnl_amount !== null) return (float) $trade->unrealized_pnl_amount;
            if ($trade->current_value !== null && $trade->allocated_capital !== null) return (float) $trade->current_value - (float) $trade->allocated_capital;
            return 0.0;
        }), 2);
        $expectedEquity = round((float) $portfolio->current_cash + $expectedUnrealized, 2);
        $expectedReturn = (float) $portfolio->starting_capital > 0
            ? round((($expectedEquity - (float) $portfolio->starting_capital) / (float) $portfolio->starting_capital) * 100, 4)
            : 0.0;

        $diffs = [
            'reserved_cash' => round((float) $portfolio->reserved_cash - $expectedReserved, 2),
            'deployed_capital' => round((float) $portfolio->deployed_capital - $expectedDeployed, 2),
            'unrealized_pnl' => round((float) $portfolio->unrealized_pnl - $expectedUnrealized, 2),
            'total_equity' => round((float) $portfolio->total_equity - $expectedEquity, 2),
            'total_return_percent' => round((float) $portfolio->total_return_percent - $expectedReturn, 4),
        ];

        $issues = [
            'expired_unreleased_trade_plans' => $this->expiredUnreleasedPlans($portfolio),
            'closed_unreleased_trades' => $this->closedUnreleasedTrades($portfolio),
            'duplicate_transactions' => $this->duplicateTransactions($portfolio),
            'duplicate_active_opportunities' => $this->duplicateActiveOpportunities($portfolio),
            'linkage_issues' => $this->linkageIssues($portfolio),
            'legacy_expired_simulated_trades' => $this->legacyExpiredTrades($portfolio),
            'scan_watchlist_context' => $this->scanWatchlistContext(),
            'recent_health_checks' => $this->recentHealthChecks(),
        ];

        $recommendations = $this->recommendations($diffs, $issues);
        $status = $this->statusFor($diffs, $issues);
        $fixed = null;

        if ($fix) {
            $before = $portfolio->only(['reserved_cash', 'deployed_capital', 'unrealized_pnl', 'total_equity', 'total_return_percent']);
            $portfolio->forceFill([
                'reserved_cash' => $expectedReserved,
                'deployed_capital' => $expectedDeployed,
                'unrealized_pnl' => $expectedUnrealized,
                'total_equity' => $expectedEquity,
                'total_return_percent' => $expectedReturn,
            ])->save();
            $fixed = ['before' => $before, 'after' => $portfolio->fresh()->only(array_keys($before))];
        }

        return [
            'status' => $status,
            'portfolio' => [
                'id' => $portfolio->id,
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
            ],
            'computed' => [
                'expected_reserved_cash' => $expectedReserved,
                'expected_deployed_capital' => $expectedDeployed,
                'expected_unrealized_pnl' => $expectedUnrealized,
                'expected_total_equity' => $expectedEquity,
                'expected_total_return_percent' => $expectedReturn,
                'reserved_trade_plan_ids' => $reservedPlans->pluck('id')->values()->all(),
                'open_simulated_trade_ids' => $openTrades->pluck('id')->values()->all(),
            ],
            'diffs' => $diffs,
            'issues' => $issues,
            'recommendations' => $recommendations,
            'fixes_applied' => $fixed,
        ];
    }

    private function reservedPlansQuery(PortfolioAccount $portfolio)
    {
        return DB::table('trade_plans')
            ->where('portfolio_account_id', $portfolio->id)
            ->where('portfolio_status', 'capital_reserved')
            ->where('allocated_capital', '>', 0)
            ->whereNotNull('capital_reserved_at')
            ->whereNull('capital_released_at')
            ->whereIn('status', ['pending', 'watching', 'triggered', 'expired'])
            ->whereNull('converted_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('simulated_trades')->whereColumn('simulated_trades.trade_plan_id', 'trade_plans.id'));
    }

    private function openTradesQuery(PortfolioAccount $portfolio)
    {
        return DB::table('simulated_trades')
            ->where('portfolio_account_id', $portfolio->id)
            ->whereIn('status', self::OPEN_TRADE_STATUSES)
            ->whereNull('closed_at')
            ->whereNull('capital_released_at');
    }

    private function expiredUnreleasedPlans(PortfolioAccount $portfolio): array
    {
        return $this->reservedPlansQuery($portfolio)->where('status', 'expired')->get(['id', 'coindcx_symbol', 'allocated_capital'])->map(fn ($row) => (array) $row)->all();
    }

    private function closedUnreleasedTrades(PortfolioAccount $portfolio): array
    {
        return DB::table('simulated_trades')->where('portfolio_account_id', $portfolio->id)->where('allocated_capital', '>', 0)->whereNotNull('closed_at')
            ->whereNull('capital_released_at')->whereNotIn('status', ['expired'])->whereRaw("COALESCE(close_reason, '') != 'expiry'")
            ->get(['id', 'coindcx_symbol', 'status', 'close_reason', 'allocated_capital'])->map(fn ($row) => (array) $row)->all();
    }

    private function duplicateTransactions(PortfolioAccount $portfolio): array
    {
        return [
            'capital_reserved' => $this->duplicateTransactionGroup($portfolio, 'capital_reserved', 'trade_plan_id'),
            'capital_released' => $this->duplicateTransactionGroup($portfolio, 'capital_released', 'trade_plan_id'),
            'trade_entry' => $this->duplicateTransactionGroup($portfolio, 'trade_entry', 'simulated_trade_id'),
            'trade_exit' => $this->duplicateTransactionGroup($portfolio, 'trade_exit', 'simulated_trade_id'),
        ];
    }

    private function duplicateTransactionGroup(PortfolioAccount $portfolio, string $type, string $column): array
    {
        return DB::table('portfolio_transactions')->where('portfolio_account_id', $portfolio->id)->where('transaction_type', $type)->whereNotNull($column)
            ->select($column, DB::raw('COUNT(*) as count'), DB::raw('GROUP_CONCAT(id) as transaction_ids'))->groupBy($column)->havingRaw('COUNT(*) > 1')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    private function duplicateActiveOpportunities(PortfolioAccount $portfolio): array
    {
        $open = DB::table('simulated_trades')->where('portfolio_account_id', $portfolio->id)->whereIn('status', self::OPEN_TRADE_STATUSES)->whereNull('closed_at')
            ->select('coindcx_symbol', DB::raw('COUNT(*) as open_trade_count'))->groupBy('coindcx_symbol')->pluck('open_trade_count', 'coindcx_symbol');
        $plans = DB::table('trade_plans')->where('portfolio_account_id', $portfolio->id)->whereIn('status', self::PENDING_PLAN_STATUSES)->whereNull('converted_at')
            ->whereRaw("COALESCE(portfolio_status, '') NOT IN ('released','rejected')")->select('coindcx_symbol', DB::raw('COUNT(*) as pending_plan_count'))->groupBy('coindcx_symbol')->pluck('pending_plan_count', 'coindcx_symbol');

        return collect($open->keys())->merge($plans->keys())->unique()->map(function ($symbol) use ($open, $plans) {
            $openCount = (int) ($open[$symbol] ?? 0);
            $planCount = (int) ($plans[$symbol] ?? 0);
            return ['coindcx_symbol' => $symbol, 'open_trade_count' => $openCount, 'pending_plan_count' => $planCount, 'total_active_opportunity_count' => $openCount + $planCount];
        })->filter(fn ($row) => $row['total_active_opportunity_count'] > 1)->values()->all();
    }

    private function linkageIssues(PortfolioAccount $portfolio): array
    {
        $plans = DB::table('trade_plans')->where('portfolio_account_id', $portfolio->id)
            ->where(fn ($query) => $query->where('status', 'converted_to_trade')->orWhere('portfolio_status', 'deployed'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('simulated_trades')->whereColumn('simulated_trades.trade_plan_id', 'trade_plans.id'))
            ->get(['id', 'coindcx_symbol', 'status', 'portfolio_status'])->map(fn ($row) => ['type' => 'converted_plan_missing_trade'] + (array) $row);

        $trades = DB::table('simulated_trades')->leftJoin('trade_plans', 'trade_plans.id', '=', 'simulated_trades.trade_plan_id')
            ->where('simulated_trades.portfolio_account_id', $portfolio->id)
            ->where(fn ($query) => $query->whereNull('simulated_trades.trade_plan_id')->orWhereNull('trade_plans.id'))
            ->get(['simulated_trades.id', 'simulated_trades.coindcx_symbol', 'simulated_trades.trade_plan_id', 'simulated_trades.status'])
            ->map(fn ($row) => ['type' => 'trade_missing_plan'] + (array) $row);

        return $plans->merge($trades)->values()->all();
    }

    private function legacyExpiredTrades(PortfolioAccount $portfolio): array
    {
        return DB::table('simulated_trades')->where('portfolio_account_id', $portfolio->id)->where(fn ($q) => $q->where('status', 'expired')->orWhere('close_reason', 'expiry'))
            ->get(['id', 'coindcx_symbol', 'status', 'close_reason'])->map(fn ($row) => (array) $row)->all();
    }

    private function scanWatchlistContext(): array
    {
        $latestScan = DB::table('scan_runs')->orderByDesc('completed_at')->orderByDesc('id')
            ->first(['id', 'scan_type', 'status', 'completed_at', 'watchlist_created_count', 'trade_plans_created_count']);

        $duplicateOpenCandidates = DB::table('candidate_watchlists')->where('status', 'open')
            ->select('coindcx_symbol', DB::raw('COUNT(*) as open_candidate_count'))
            ->groupBy('coindcx_symbol')->havingRaw('COUNT(*) > 1')
            ->get()->map(fn ($row) => (array) $row)->all();

        return [
            'latest_scan_run' => $latestScan ? (array) $latestScan : null,
            'duplicate_open_watchlist_candidates' => $duplicateOpenCandidates,
        ];
    }

    private function recentHealthChecks(): array
    {
        $services = ['scan_cycle_expiry_manager', 'portfolio_release_manager', 'active_trade_monitor', 'trade_plan_trigger_monitor', 'breakout_entry_simulator', 'pullback_entry_simulator', 'trailing_monitor'];
        return DB::table('system_health_logs')->whereIn('service_name', $services)->whereIn('id', function ($query) use ($services) {
            $query->selectRaw('MAX(id)')->from('system_health_logs')->whereIn('service_name', $services)->groupBy('service_name');
        })->orderBy('service_name')->get(['service_name', 'status', 'message', 'checked_at'])->map(fn ($row) => (array) $row)->all();
    }

    private function recommendations(array $diffs, array $issues): array
    {
        $items = [];
        if ($this->hasMaterialDiff($diffs)) $items[] = 'Review balance mismatches. Use --fix only to refresh derived account totals after validating underlying rows.';
        if ($issues['expired_unreleased_trade_plans']) $items[] = 'Run or inspect portfolio_release_manager for expired untriggered plans with reserved capital.';
        if ($issues['closed_unreleased_trades']) $items[] = 'Run or inspect portfolio_release_manager for closed trades with deployed capital.';
        if ($this->hasDuplicateTransactions($issues['duplicate_transactions'])) $items[] = 'Manually inspect duplicate portfolio_transactions before changing accounting history.';
        if ($issues['duplicate_active_opportunities']) $items[] = 'Manually inspect duplicate active opportunities by symbol; reconciliation does not close/delete rows.';
        if ($issues['linkage_issues']) $items[] = 'Inspect trade plan / simulated trade linkage issues manually.';
        if ($issues['legacy_expired_simulated_trades']) $items[] = 'Legacy expired simulated trades found. These are historical artifacts unless new rows are still being created.';
        return $items;
    }

    private function statusFor(array $diffs, array $issues): string
    {
        if ($this->hasMaterialDiff($diffs) || $issues['expired_unreleased_trade_plans'] || $issues['closed_unreleased_trades'] || $this->hasDuplicateTransactions($issues['duplicate_transactions']) || $issues['duplicate_active_opportunities'] || $issues['linkage_issues']) return 'error';
        if ($this->hasAnyDiff($diffs) || $issues['legacy_expired_simulated_trades']) return 'warning';
        return 'ok';
    }

    private function hasMaterialDiff(array $diffs): bool
    {
        return abs($diffs['reserved_cash']) > self::MATERIAL_MONEY_DIFF || abs($diffs['deployed_capital']) > self::MATERIAL_MONEY_DIFF || abs($diffs['unrealized_pnl']) > self::MATERIAL_MONEY_DIFF || abs($diffs['total_equity']) > self::MATERIAL_MONEY_DIFF || abs($diffs['total_return_percent']) > self::MATERIAL_PERCENT_DIFF;
    }

    private function hasAnyDiff(array $diffs): bool
    {
        return collect($diffs)->contains(fn ($value) => abs((float) $value) > 0);
    }

    private function hasDuplicateTransactions(array $duplicates): bool
    {
        return collect($duplicates)->flatten(1)->isNotEmpty();
    }

    private function overallStatus(array $reports): string
    {
        if (collect($reports)->contains(fn ($report) => $report['status'] === 'error')) return 'error';
        if (collect($reports)->contains(fn ($report) => $report['status'] === 'warning')) return 'warning';
        return 'ok';
    }

    private function logHealth(string $status, array $summary): void
    {
        SystemHealthLog::create([
            'service_name' => 'portfolio_reconciliation',
            'status' => $status,
            'message' => 'Portfolio reconciliation completed: '.strtoupper($status),
            'checked_at' => now(),
            'meta' => $summary,
        ]);
    }
}
