<?php

namespace App\Console\Commands;

use App\Models\PortfolioAccount;
use App\Models\SystemHealthLog;
use App\Services\PortfolioReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CryptoSpotPortfolioE2ETest extends Command
{
    protected $signature = 'cryptospot:portfolio-e2e-test
        {--dry-run : Kept for explicit safety; the command is always read-only except health logging}
        {--reset-test-data : Not implemented; destructive resets are intentionally disabled}
        {--json : Output JSON only}
        {--symbol= : Limit symbol-specific cooldown/details where applicable}';

    protected $description = 'Validate the end-to-end portfolio-aware simulation chain without mutating trading data.';

    private const OPEN_TRADE_STATUSES = ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'];
    private const PENDING_PLAN_STATUSES = ['pending', 'watching', 'triggered'];
    private const CLOSED_TRADE_STATUSES = ['closed', 'sl_hit', 'tp2_hit', 'trailing_stopped'];
    private const MONEY_TOLERANCE = 1.00;

    private array $errors = [];
    private array $warnings = [];
    private array $recommendations = [];

    public function handle(PortfolioReconciliationService $reconciliationService): int
    {
        if ($this->option('reset-test-data')) {
            $this->error('The --reset-test-data option is intentionally disabled for production safety.');
            return self::FAILURE;
        }

        $portfolio = PortfolioAccount::query()->where('is_active', true)->orderBy('id')->first();
        $latestScan = $this->tableExists('scan_runs') ? DB::table('scan_runs')->where('status', 'completed')->orderByDesc('completed_at')->orderByDesc('id')->first() : null;
        if (! $latestScan && $this->tableExists('scan_runs')) $latestScan = DB::table('scan_runs')->orderByDesc('completed_at')->orderByDesc('id')->first();

        $sections = [
            'environment' => $this->environmentSection($portfolio, $latestScan),
            'portfolio' => $this->portfolioSection($portfolio),
            'scan_cycle_expiry' => $this->scanCycleExpirySection($latestScan),
            'watchlist' => $this->watchlistSection($latestScan),
            'trade_plans' => $this->tradePlansSection($latestScan, $portfolio),
            'capital_reservation' => $this->capitalReservationSection($portfolio),
            'entry_conversion' => $this->entryConversionSection($portfolio),
            'active_pnl' => $this->activePnlSection($portfolio),
            'closed_release' => $this->closedReleaseSection($portfolio),
            'expired_plan_release' => $this->expiredPlanReleaseSection($portfolio),
            'duplicates' => $this->duplicatesSection($portfolio),
            'cooldown' => $this->cooldownSection($portfolio),
            'reconciliation' => $this->reconciliationSection($reconciliationService, $portfolio),
            'dashboard' => $this->dashboardSection(),
        ];

        $status = $this->errors ? 'fail' : ($this->warnings ? 'warning' : 'pass');
        $report = [
            'status' => $status,
            'sections' => $sections,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'recommendations' => $this->recommendations,
        ];

        $this->logHealth($status, $report);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderReport($report);
        }

        return self::SUCCESS;
    }

    private function environmentSection(?PortfolioAccount $portfolio, mixed $latestScan): array
    {
        $dbOk = false; $dbName = null; $dbError = null;
        try { $dbName = DB::connection()->getDatabaseName(); DB::select('select 1'); $dbOk = true; } catch (Throwable $e) { $dbError = $e->getMessage(); }
        if (! $dbOk) $this->failCheck('Database connection failed: '.$dbError);
        if (! $portfolio) $this->failCheck('No active portfolio account exists.');
        if (! $latestScan) $this->warnCheck('No scan_run exists yet; run scanner separately when market/data validation is needed.');
        if (! $this->tableExists('system_health_logs') || DB::table('system_health_logs')->count() === 0) $this->warnCheck('No system health logs found before E2E run.');
        return ['app_env' => app()->environment(), 'db_connected' => $dbOk, 'db_name' => $dbName, 'latest_scan_run_id' => $latestScan->id ?? null, 'latest_scan_run_created_at' => $latestScan->created_at ?? null, 'latest_scan_run_completed_at' => $latestScan->completed_at ?? null, 'active_portfolio_id' => $portfolio?->id, 'active_portfolio_name' => $portfolio?->name, 'system_health_log_count' => $this->tableExists('system_health_logs') ? DB::table('system_health_logs')->count() : 0];
    }

    private function portfolioSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        foreach (['current_cash','reserved_cash','deployed_capital','total_equity'] as $field) if ($portfolio->{$field} === null) $this->failCheck("Active portfolio {$field} is null.");
        if ($portfolio->currency !== 'INR') $this->failCheck('Active portfolio currency is not INR.');
        if (abs((float) $portfolio->starting_capital - 100000.0) > self::MONEY_TOLERANCE) $this->warnCheck('Active portfolio starting capital is not ₹100000; using configured value.');
        return $portfolio->only(['id','name','currency','starting_capital','current_cash','reserved_cash','deployed_capital','realized_pnl','unrealized_pnl','total_equity','total_return_percent']);
    }

    private function scanCycleExpirySection(mixed $latestScan): array
    {
        if (! $latestScan) return ['status' => 'warning', 'reason' => 'no latest scan'];
        $olderActiveWatchlist = $this->tableExists('candidate_watchlists') && $this->columnExists('candidate_watchlists', 'scan_run_id')
            ? DB::table('candidate_watchlists')->where('scan_run_id', '<', $latestScan->id)->whereIn('status', ['open','active','watching'])->count() : 0;
        $olderPendingPlans = DB::table('trade_plans')->whereNotNull('scan_run_id')->where('scan_run_id', '<', $latestScan->id)->whereIn('status', self::PENDING_PLAN_STATUSES)->whereNull('converted_at')->count();
        if ($olderActiveWatchlist > 0) $this->failCheck("Older actionable watchlist rows remain after latest scan: {$olderActiveWatchlist}.");
        if ($olderPendingPlans > 0) $this->failCheck("Older pending/watching trade plans remain after latest scan: {$olderPendingPlans}.");
        return ['latest_scan_run_id' => $latestScan->id, 'older_active_watchlist_count' => $olderActiveWatchlist, 'older_pending_trade_plan_count' => $olderPendingPlans, 'watchlist_grouped_by_scan' => $this->groupCounts('candidate_watchlists', 'scan_run_id', 'status'), 'trade_plans_grouped_by_scan' => $this->groupCounts('trade_plans', 'scan_run_id', 'status')];
    }

    private function watchlistSection(mixed $latestScan): array
    {
        if (! $latestScan || ! $this->tableExists('candidate_watchlists')) return ['status' => 'warning', 'reason' => 'no scan or watchlist table'];
        $query = DB::table('candidate_watchlists');
        if ($this->columnExists('candidate_watchlists', 'scan_run_id')) $query->where('scan_run_id', $latestScan->id);
        else $query->where('created_at', '>=', $latestScan->started_at ?? $latestScan->created_at);
        $rows = (clone $query)->count();
        if ($rows === 0 && (($latestScan->watchlist_created_count ?? 0) > 0)) $this->failCheck('Latest scan reports watchlist rows but none were found.');
        if ($rows === 0) $this->warnCheck('Latest scan has no watchlist candidates; this can be normal in quiet market conditions.');
        return ['latest_scan_run_id' => $latestScan->id, 'watchlist_rows_count' => $rows, 'active_watchlist_count' => (clone $query)->whereIn('status', ['open','active','watching'])->count(), 'expired_watchlist_count' => (clone $query)->where('status', 'expired')->count(), 'rejected_blocked_count' => (clone $query)->whereIn('status', ['rejected','blocked'])->count()];
    }

    private function tradePlansSection(mixed $latestScan, ?PortfolioAccount $portfolio): array
    {
        $q = DB::table('trade_plans'); if ($portfolio) $q->where('portfolio_account_id', $portfolio->id); if ($latestScan) $q->where('scan_run_id', $latestScan->id);
        $counts = (clone $q)->select('status', DB::raw('COUNT(*) count'))->groupBy('status')->pluck('count','status')->all();
        $portfolioRejected = (clone $q)->where('portfolio_status', 'rejected')->count();
        $rejectedMissingReason = (clone $q)->where('portfolio_status', 'rejected')->whereNull('portfolio_rejection_reason')->count();
        if ((clone $q)->count() === 0) $this->warnCheck('No trade plans found for the latest/current opportunity set.');
        if ($rejectedMissingReason > 0) $this->failCheck("Portfolio-rejected trade plans missing rejection reason: {$rejectedMissingReason}.");
        return ['status_counts' => $counts, 'pending_watching_triggered_plans' => (clone $q)->whereIn('status', self::PENDING_PLAN_STATUSES)->count(), 'portfolio_rejected_plans' => $portfolioRejected, 'expired_plans' => (clone $q)->where('status','expired')->count(), 'converted_plans' => (clone $q)->where(fn($x) => $x->where('status','converted_to_trade')->orWhereNotNull('converted_at'))->count(), 'rejected_missing_reason_count' => $rejectedMissingReason];
    }

    private function capitalReservationSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        $q = DB::table('trade_plans')->where('portfolio_account_id',$portfolio->id)->where('portfolio_status','capital_reserved')->whereNull('capital_released_at');
        $missingAllocated = (clone $q)->where(fn($x)=>$x->whereNull('allocated_capital')->orWhere('allocated_capital','<=',0))->count();
        $missingReservedAt = (clone $q)->whereNull('capital_reserved_at')->count();
        $missingTx = (clone $q)->whereNotExists(fn($x)=>$x->selectRaw('1')->from('portfolio_transactions')->whereColumn('portfolio_transactions.trade_plan_id','trade_plans.id')->where('transaction_type','capital_reserved'))->count();
        $sum = round((float)(clone $q)->sum('allocated_capital'),2); $diff = round((float)$portfolio->reserved_cash - $sum,2);
        if ($missingAllocated || $missingReservedAt || $missingTx) $this->failCheck('Capital-reserved plans have missing allocation/reservation timestamp/transaction.');
        if (abs($diff) > self::MONEY_TOLERANCE) $this->failCheck("Reserved cash mismatch exceeds ₹1: {$diff}.");
        return ['reserved_plan_count'=>(clone $q)->count(), 'reserved_plan_total'=>$sum, 'portfolio_reserved_cash'=>(float)$portfolio->reserved_cash, 'diff'=>$diff, 'missing_allocated_count'=>$missingAllocated, 'missing_reserved_at_count'=>$missingReservedAt, 'missing_capital_reserved_transaction_count'=>$missingTx];
    }

    private function entryConversionSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        $dup = DB::table('simulated_trades')->where('portfolio_account_id',$portfolio->id)->whereNotNull('trade_plan_id')->select('trade_plan_id', DB::raw('COUNT(*) count'))->groupBy('trade_plan_id')->havingRaw('COUNT(*) > 1')->get();
        $trades = DB::table('simulated_trades')->where('portfolio_account_id',$portfolio->id);
        $missingTx = (clone $trades)->whereNotExists(fn($x)=>$x->selectRaw('1')->from('portfolio_transactions')->whereColumn('portfolio_transactions.simulated_trade_id','simulated_trades.id')->where('transaction_type','trade_entry'))->count();
        if ($dup->isNotEmpty()) $this->failCheck('Duplicate simulated trades exist for one or more trade plans.');
        if ($missingTx > 0) $this->failCheck("Portfolio simulated trades missing trade_entry transaction: {$missingTx}.");
        return ['portfolio_trades_count'=>(clone $trades)->count(), 'missing_quantity_count'=>(clone $trades)->where(fn($x)=>$x->whereNull('quantity')->orWhere('quantity','<=',0))->count(), 'missing_trade_entry_transaction_count'=>$missingTx, 'duplicate_simulated_trade_per_plan_count'=>$dup->count(), 'missing_trade_plan_id_count'=>(clone $trades)->whereNull('trade_plan_id')->count(), 'missing_allocated_capital_count'=>(clone $trades)->where(fn($x)=>$x->whereNull('allocated_capital')->orWhere('allocated_capital','<=',0))->count(), 'missing_entry_value_count'=>(clone $trades)->whereNull('entry_value')->count()];
    }

    private function activePnlSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        $q = DB::table('simulated_trades')->where('portfolio_account_id',$portfolio->id)->whereIn('status', self::OPEN_TRADE_STATUSES)->whereNull('closed_at');
        $deployed = round((float)(clone $q)->sum('allocated_capital'),2); $unreal = round((float)(clone $q)->sum('unrealized_pnl_amount'),2);
        $deployedDiff = round((float)$portfolio->deployed_capital - $deployed,2); $unrealDiff = round((float)$portfolio->unrealized_pnl - $unreal,2); $equityDiff = round((float)$portfolio->total_equity - ((float)$portfolio->current_cash + $unreal),2);
        $missing = (clone $q)->where(fn($x)=>$x->whereNull('current_value')->orWhereNull('unrealized_pnl_amount')->orWhereNull('net_pnl_amount'))->count();
        if ($missing) $this->failCheck("Open trades missing active INR P&L fields: {$missing}.");
        if (abs($deployedDiff)>1 || abs($unrealDiff)>1 || abs($equityDiff)>1) $this->failCheck('Active P&L/deployed/equity mismatch exceeds ₹1.');
        return ['open_trade_count'=>(clone $q)->count(), 'deployed_total'=>$deployed, 'unrealized_total'=>$unreal, 'portfolio_deployed_capital'=>(float)$portfolio->deployed_capital, 'portfolio_unrealized_pnl'=>(float)$portfolio->unrealized_pnl, 'portfolio_total_equity'=>(float)$portfolio->total_equity, 'deployed_diff'=>$deployedDiff, 'unrealized_diff'=>$unrealDiff, 'total_equity_diff'=>$equityDiff, 'missing_pnl_field_count'=>$missing];
    }

    private function closedReleaseSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        $q = DB::table('simulated_trades')->where('portfolio_account_id',$portfolio->id)->whereNotNull('closed_at')->whereNotIn('status',['expired'])->whereRaw("COALESCE(close_reason,'') != 'expiry'");
        $unreleased = (clone $q)->whereNull('capital_released_at')->count();
        $missingExit = (clone $q)->whereNotExists(fn($x)=>$x->selectRaw('1')->from('portfolio_transactions')->whereColumn('portfolio_transactions.simulated_trade_id','simulated_trades.id')->where('transaction_type','trade_exit'))->count();
        $dupExit = $this->duplicateTxCount($portfolio, 'trade_exit', 'simulated_trade_id');
        if ($unreleased || $missingExit || $dupExit) $this->failCheck('Closed non-legacy portfolio trades have capital release or trade_exit transaction issues.');
        return ['closed_trade_count'=>(clone $q)->count(), 'unreleased_closed_trade_count'=>$unreleased, 'missing_trade_exit_transaction_count'=>$missingExit, 'duplicate_trade_exit_transaction_count'=>$dupExit];
    }

    private function expiredPlanReleaseSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        $expiredReserved = DB::table('trade_plans')->where('portfolio_account_id',$portfolio->id)->where('status','expired')->where('portfolio_status','capital_reserved')->whereNull('converted_at');
        $unreleased = (clone $expiredReserved)->whereNull('capital_released_at')->count();
        $released = DB::table('trade_plans')->where('portfolio_account_id',$portfolio->id)->where('status','expired')->whereNotNull('capital_released_at')->count();
        $missingTx = DB::table('trade_plans')->where('portfolio_account_id',$portfolio->id)->where('status','expired')->whereNotNull('capital_released_at')->whereNotExists(fn($x)=>$x->selectRaw('1')->from('portfolio_transactions')->whereColumn('portfolio_transactions.trade_plan_id','trade_plans.id')->where('transaction_type','capital_released'))->count();
        $dup = $this->duplicateTxCount($portfolio,'capital_released','trade_plan_id');
        if ($unreleased || $missingTx || $dup) $this->failCheck('Expired untriggered reserved trade plans have release transaction issues.');
        return ['expired_reserved_unreleased_count'=>$unreleased, 'released_expired_plan_count'=>$released, 'missing_capital_released_transaction_count'=>$missingTx, 'duplicate_capital_released_transaction_count'=>$dup];
    }

    private function duplicatesSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        $openDup = DB::table('simulated_trades')->where('portfolio_account_id',$portfolio->id)->whereIn('status',self::OPEN_TRADE_STATUSES)->whereNull('closed_at')->select('coindcx_symbol',DB::raw('COUNT(*) count'))->groupBy('coindcx_symbol')->havingRaw('COUNT(*) > 1')->pluck('count','coindcx_symbol')->all();
        $planDup = DB::table('trade_plans')->where('portfolio_account_id',$portfolio->id)->whereIn('status',self::PENDING_PLAN_STATUSES)->whereNull('converted_at')->whereRaw("COALESCE(portfolio_status,'') NOT IN ('released','rejected')")->select('coindcx_symbol',DB::raw('COUNT(*) count'))->groupBy('coindcx_symbol')->havingRaw('COUNT(*) > 1')->pluck('count','coindcx_symbol')->all();
        $combined = $this->combinedDuplicateSymbols($portfolio);
        if ($openDup || $planDup || $combined) $this->failCheck('Duplicate same-symbol active opportunities exist.');
        return ['duplicate_open_trade_symbols'=>$openDup, 'duplicate_pending_plan_symbols'=>$planDup, 'duplicate_combined_symbols'=>$combined];
    }

    private function cooldownSection(?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status' => 'fail'];
        $symbol = $this->option('symbol');
        $closed = DB::table('simulated_trades')->where('portfolio_account_id',$portfolio->id)->whereNotNull('closed_at')->where(fn($q)=>$q->whereIn('close_reason',['sl','stop_loss','trailing','trailing_stop','tp2','target'])->orWhereIn('status',['sl_hit','trailing_stopped','tp2_hit']))->when($symbol, fn($q)=>$q->where('coindcx_symbol',$symbol))->orderByDesc('closed_at')->limit(50)->get();
        $violations = []; $rejections = 0;
        foreach ($closed as $trade) {
            $hours = str_contains((string)$trade->close_reason, 'sl') || $trade->status === 'sl_hit' ? 24 : 12;
            $plans = DB::table('trade_plans')->where('portfolio_account_id',$portfolio->id)->where('coindcx_symbol',$trade->coindcx_symbol)->where('created_at','>',$trade->closed_at)->where('created_at','<=',Carbon::parse($trade->closed_at)->addHours($hours))->get(['id','status','portfolio_status','portfolio_rejection_reason','created_at']);
            foreach ($plans as $plan) {
                $rejected = $plan->portfolio_status === 'rejected' && str_contains(strtolower((string)$plan->portfolio_rejection_reason), 'cooldown');
                if ($rejected) $rejections++; else $violations[] = ['closed_trade_id'=>$trade->id, 'symbol'=>$trade->coindcx_symbol, 'plan_id'=>$plan->id, 'created_at'=>$plan->created_at, 'cooldown_hours'=>$hours];
            }
        }
        if ($violations) $this->failCheck('Clear cooldown violation found for same-symbol plan after recent SL/trailing/win close.');
        return ['recent_closed_trades_checked'=>$closed->count(), 'cooldown_violations'=>$violations, 'cooldown_rejections_found'=>$rejections];
    }

    private function reconciliationSection(PortfolioReconciliationService $service, ?PortfolioAccount $portfolio): array
    {
        if (! $portfolio) return ['status'=>'fail'];
        $report = $service->reconcile($portfolio->id, false, false);
        $status = $report['status'] ?? 'unknown';
        if ($status === 'error') $this->failCheck('Portfolio reconciliation status is error.');
        elseif ($status === 'warning') $this->warnCheck('Portfolio reconciliation status is warning.');
        return ['reconciliation_status'=>$status, 'diffs'=>$report['diffs'] ?? [], 'issue_counts'=>$this->issueCounts($report['issues'] ?? [])];
    }

    private function dashboardSection(): array
    {
        $route = Route::has('cryptospot.portfolio.index');
        $controller = class_exists(\App\Http\Controllers\PortfolioController::class);
        $view = view()->exists('portfolio.index');
        if (! $route || ! $controller || ! $view) $this->failCheck('Portfolio dashboard route/controller/view is not ready.');
        return ['portfolio_route_exists'=>$route, 'portfolio_controller_exists'=>$controller, 'portfolio_view_exists'=>$view, 'dashboard_route_exists'=>Route::has('cryptospot.dashboard')];
    }

    private function renderReport(array $report): void
    {
        $this->info('Portfolio E2E Test Report'); $this->line('Final status: '.strtoupper($report['status']));
        foreach ($report['sections'] as $name => $section) { $this->newLine(); $this->line(str_replace('_',' ',ucwords($name, '_'))); foreach ($section as $k=>$v) $this->line('  - '.str_replace('_',' ',$k).': '.$this->format($v)); }
        if ($report['errors']) { $this->newLine(); $this->error('Errors'); foreach ($report['errors'] as $e) $this->line('  - '.$e); }
        if ($report['warnings']) { $this->newLine(); $this->warn('Warnings'); foreach ($report['warnings'] as $w) $this->line('  - '.$w); }
        if ($report['recommendations']) { $this->newLine(); $this->line('Recommendations'); foreach ($report['recommendations'] as $r) $this->line('  - '.$r); }
    }

    private function logHealth(string $status, array $report): void
    {
        if (! $this->tableExists('system_health_logs')) return;
        SystemHealthLog::create(['service_name'=>'portfolio_e2e_test','status'=>$status === 'pass' ? 'ok' : ($status === 'warning' ? 'warning' : 'error'),'message'=>'Portfolio E2E test completed: '.strtoupper($status),'checked_at'=>now(),'meta'=>['status'=>$status,'errors'=>$report['errors'],'warnings'=>$report['warnings'],'sections'=>array_map(fn($s)=>is_array($s)?array_slice($s,0,20,true):$s,$report['sections'])]]);
    }

    private function duplicateTxCount(PortfolioAccount $portfolio, string $type, string $column): int { return DB::table('portfolio_transactions')->where('portfolio_account_id',$portfolio->id)->where('transaction_type',$type)->whereNotNull($column)->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->get()->count(); }
    private function combinedDuplicateSymbols(PortfolioAccount $p): array { $open=DB::table('simulated_trades')->where('portfolio_account_id',$p->id)->whereIn('status',self::OPEN_TRADE_STATUSES)->whereNull('closed_at')->select('coindcx_symbol',DB::raw('COUNT(*) c'))->groupBy('coindcx_symbol')->pluck('c','coindcx_symbol'); $plans=DB::table('trade_plans')->where('portfolio_account_id',$p->id)->whereIn('status',self::PENDING_PLAN_STATUSES)->whereNull('converted_at')->whereRaw("COALESCE(portfolio_status,'') NOT IN ('released','rejected')")->select('coindcx_symbol',DB::raw('COUNT(*) c'))->groupBy('coindcx_symbol')->pluck('c','coindcx_symbol'); return collect($open->keys())->merge($plans->keys())->unique()->mapWithKeys(fn($s)=>[((string)$s)=>(int)($open[$s]??0)+(int)($plans[$s]??0)])->filter(fn($c)=>$c>1)->all(); }
    private function issueCounts(array $issues): array { return collect($issues)->map(fn($v)=>is_array($v)?(collect($v)->every(fn($x)=>is_array($x))?collect($v)->flatten(1)->count():count($v)):0)->all(); }
    private function groupCounts(string $table, string $group, string $status): array { if (! $this->tableExists($table) || ! $this->columnExists($table,$group)) return []; return DB::table($table)->select($group,$status,DB::raw('COUNT(*) count'))->groupBy($group,$status)->orderByDesc($group)->limit(20)->get()->map(fn($r)=>(array)$r)->all(); }
    private function tableExists(string $table): bool { return Schema::hasTable($table); }
    private function columnExists(string $table, string $column): bool { return Schema::hasColumn($table, $column); }
    private function failCheck(string $message): void { $this->errors[] = $message; $this->recommendations[] = $message; }
    private function warnCheck(string $message): void { $this->warnings[] = $message; }
    private function format(mixed $value): string { if (is_array($value) || is_object($value)) return json_encode($value, JSON_UNESCAPED_SLASHES); if (is_bool($value)) return $value ? 'yes' : 'no'; if ($value === null) return 'null'; return (string)$value; }
}
