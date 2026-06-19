<?php

namespace App\Http\Controllers;

use App\Models\CandidateWatchlist;
use App\Models\DailyGainerLeaderboard;
use App\Models\MissedGainer;
use App\Models\ScanResult;
use App\Models\ScanRun;
use App\Models\SimulatedTrade;
use App\Models\SystemHealthLog;
use App\Models\TradeEvent;
use App\Models\TradePlan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    private const REALTIME_SERVICES = [
        'trade_plan_trigger_monitor',
        'breakout_entry_simulator',
        'pullback_entry_simulator',
        'active_trade_monitor',
        'trade_event_monitor',
        'trailing_monitor',
    ];

    private const SCHEDULED_SERVICES = [
        'scan_runner' => ['label' => 'Full scan', 'threshold_minutes' => 90, 'cadence' => 'hourly at :00 IST', 'command' => 'php artisan cryptospot:scan'],
        'daily_gainer_leaderboard' => ['label' => 'Daily gainers', 'threshold_minutes' => 300, 'cadence' => '15 */4 * * * IST', 'command' => 'php artisan cryptospot:daily-gainers'],
        'missed_gainer_analyzer' => ['label' => 'Missed gainers', 'threshold_minutes' => 300, 'cadence' => '20 */4 * * * IST', 'command' => 'php artisan cryptospot:missed-gainers'],
        'retention_cleanup' => ['label' => 'Cleanup', 'threshold_minutes' => 1800, 'cadence' => 'daily 03:30 IST', 'command' => 'php artisan cryptospot:cleanup'],
        'scan_cycle_expiry_manager' => ['label' => 'Scan-cycle opportunity expiry', 'threshold_minutes' => 90, 'cadence' => 'with each full scan', 'command' => 'php artisan cryptospot:scan'],
    ];

    public function index(Request $request): View
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString())->startOfDay() : now()->subDay();
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString())->endOfDay() : now();
        $serviceFilter = $request->string('service_name')->toString() ?: null;
        $statusFilter = $request->string('status')->toString() ?: null;
        $severityFilter = $request->string('severity')->toString() ?: null;

        $latestLogs = $this->latestLogsByService();
        $serviceHealthRows = $this->buildServiceHealthRows($latestLogs);
        if ($severityFilter) {
            $serviceHealthRows = $serviceHealthRows->where('severity', $severityFilter)->values();
        }

        $recentWarningsErrors = SystemHealthLog::query()
            ->whereBetween('checked_at', [$from, $to])
            ->whereIn('status', ['warning', 'error', 'failed'])
            ->when($serviceFilter, fn ($query) => $query->where('service_name', $serviceFilter))
            ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $summaryStats = $this->summaryStats($serviceHealthRows, $from, $to);

        return view('system-health.index', [
            'filters' => compact('serviceFilter', 'statusFilter', 'severityFilter', 'from', 'to'),
            'serviceOptions' => $latestLogs->keys()->merge($this->expectedServices())->unique()->sort()->values(),
            'summaryStats' => $summaryStats,
            'serviceHealthRows' => $serviceHealthRows,
            'staleServices' => $serviceHealthRows->where('is_stale', true)->values(),
            'recentWarningsErrors' => $recentWarningsErrors,
            'latestScanStats' => $this->latestScanStats(),
            'latestTradeStats' => $this->latestTradeStats(),
            'schedulerSummary' => $this->schedulerSummary($serviceHealthRows),
            'realtimeSummary' => $serviceHealthRows->where('category', 'realtime')->values(),
            'dataFreshnessStats' => $this->dataFreshnessStats(),
            'troubleshootingCommands' => $this->troubleshootingCommands(),
        ]);
    }

    private function latestLogsByService(): Collection
    {
        if (! Schema::hasTable('system_health_logs')) {
            return collect();
        }
        $ids = SystemHealthLog::query()->select(DB::raw('MAX(id) as id'))->groupBy('service_name');
        return SystemHealthLog::query()->whereIn('id', $ids)->get()->keyBy('service_name');
    }

    private function buildServiceHealthRows(Collection $latestLogs): Collection
    {
        return $this->expectedServices()->merge($latestLogs->keys())->unique()->sort()->map(function (string $service) use ($latestLogs): array {
            $log = $latestLogs->get($service);
            $threshold = $this->thresholdMinutes($service);
            $lastChecked = $log?->checked_at;
            $missing = ! $log;
            $stale = $missing || ($lastChecked && $lastChecked->lt(now()->subMinutes($threshold)));
            $status = $missing ? 'missing' : strtolower((string) $log->status);
            $severity = match (true) {
                in_array($status, ['error', 'failed'], true) => 'critical',
                $status === 'warning' || $stale || $missing => 'warning',
                in_array($status, ['ok', 'success', 'completed'], true) => 'ok',
                default => 'unknown',
            };
            $since24 = now()->subDay();
            return [
                'service' => $service,
                'category' => $this->category($service),
                'status' => $status,
                'severity' => $severity,
                'freshness' => $missing ? 'missing' : ($stale ? 'stale' : 'fresh'),
                'is_stale' => $stale,
                'last_checked_at' => $lastChecked,
                'age' => $lastChecked?->diffForHumans(),
                'expected_cadence' => $this->cadence($service),
                'message' => $log?->message,
                'error_count_24h' => SystemHealthLog::query()->where('service_name', $service)->whereIn('status', ['error', 'failed'])->where('checked_at', '>=', $since24)->count(),
                'warning_count_24h' => SystemHealthLog::query()->where('service_name', $service)->where('status', 'warning')->where('checked_at', '>=', $since24)->count(),
                'help' => $this->category($service) === 'realtime' ? 'Check Supervisor status/logs below.' : ($this->category($service) === 'scheduled' ? 'Check Laravel scheduler and cron below.' : 'Review data pipeline logs.'),
            ];
        })->values();
    }

    private function expectedServices(): Collection
    {
        return collect(array_keys(self::SCHEDULED_SERVICES))->merge(self::REALTIME_SERVICES)->merge(['realtime_monitors_loop']);
    }

    private function thresholdMinutes(string $service): int { return self::SCHEDULED_SERVICES[$service]['threshold_minutes'] ?? (in_array($service, self::REALTIME_SERVICES, true) || $service === 'realtime_monitors_loop' ? 2 : 300); }
    private function category(string $service): string { return isset(self::SCHEDULED_SERVICES[$service]) ? 'scheduled' : (in_array($service, self::REALTIME_SERVICES, true) || $service === 'realtime_monitors_loop' ? 'realtime' : (str_contains($service, 'collector') || str_contains($service, 'snapshot') || str_contains($service, 'engine') ? 'data' : 'other')); }
    private function cadence(string $service): string { return self::SCHEDULED_SERVICES[$service]['cadence'] ?? ($this->category($service) === 'realtime' ? '15 sec loop, stale > 2 min' : 'best effort, stale > 5h'); }

    private function summaryStats(Collection $rows, Carbon $from, Carbon $to): array
    {
        $critical = $rows->where('severity', 'critical')->count();
        $warnings = $rows->where('severity', 'warning')->count();
        $latestRealtime = $rows->where('category', 'realtime')->pluck('last_checked_at')->filter()->sortDesc()->first();
        $latestScan = ScanRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();
        return [
            'overall_status' => $critical > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok'),
            'healthy_count' => $rows->where('severity', 'ok')->count(),
            'warning_count' => $warnings,
            'error_count' => $critical,
            'missing_count' => $rows->where('freshness', 'missing')->count(),
            'stale_count' => $rows->where('freshness', 'stale')->count(),
            'latest_scan_age' => $latestScan?->started_at?->diffForHumans() ?? '-',
            'latest_realtime_age' => $latestRealtime?->diffForHumans() ?? '-',
            'recent_errors_24h' => SystemHealthLog::query()->whereIn('status', ['error', 'failed'])->whereBetween('checked_at', [$from, $to])->count(),
            'recent_warnings_24h' => SystemHealthLog::query()->where('status', 'warning')->whereBetween('checked_at', [$from, $to])->count(),
        ];
    }

    private function schedulerSummary(Collection $rows): Collection { return collect(self::SCHEDULED_SERVICES)->map(fn ($cfg, $service) => $cfg + ['service' => $service, 'last_run' => $rows->firstWhere('service', $service)['last_checked_at'] ?? null, 'status' => $rows->firstWhere('service', $service)['status'] ?? 'missing', 'freshness' => $rows->firstWhere('service', $service)['freshness'] ?? 'missing']); }
    private function latestScanStats(): array { $scan = ScanRun::query()->orderByDesc('started_at')->orderByDesc('id')->first(); return ['scan' => $scan, 'duration' => $scan?->duration_seconds ? $scan->duration_seconds.'s' : null, 'raw_payload' => $scan?->raw_payload]; }
    private function latestTradeStats(): array { return ['latest_trade' => SimulatedTrade::query()->orderByDesc('updated_at')->first(), 'latest_event' => TradeEvent::query()->orderByDesc('event_time')->orderByDesc('id')->first()]; }

    private function dataFreshnessStats(): array
    {
        $today = now()->startOfDay();
        $latestLeaderboardDate = DailyGainerLeaderboard::query()->max('leaderboard_date');
        $latestAnalysisDate = MissedGainer::query()->max('analysis_date');
        return [
            'scan_run' => ['latest' => ScanRun::query()->orderByDesc('started_at')->orderByDesc('id')->first()],
            'scan_result' => ['latest' => ScanResult::query()->orderByDesc('created_at')->first(), 'count_today' => ScanResult::query()->where('created_at', '>=', $today)->count()],
            'candidate_watchlist' => ['latest' => CandidateWatchlist::query()->orderByDesc('created_at')->first(), 'open_count' => CandidateWatchlist::query()->whereIn('status', ['active', 'open', 'watchlist'])->count()],
            'trade_plan' => ['latest' => TradePlan::query()->orderByDesc('updated_at')->first(), 'open_count' => TradePlan::query()->whereIn('status', ['pending', 'watching'])->count(), 'triggered_count' => TradePlan::query()->where('status', 'triggered')->count()],
            'simulated_trade' => ['latest' => SimulatedTrade::query()->orderByDesc('updated_at')->first(), 'open_count' => SimulatedTrade::query()->whereIn('status', ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'])->count(), 'closed_count' => SimulatedTrade::query()->whereIn('status', ['closed_sl', 'closed_trailing', 'closed_tp1', 'closed_tp2', 'expired', 'cancelled', 'error'])->count()],
            'trade_event' => ['latest' => TradeEvent::query()->orderByDesc('event_time')->orderByDesc('id')->first()],
            'daily_gainer_leaderboard' => ['latest' => DailyGainerLeaderboard::query()->orderByDesc('updated_at')->first(), 'rows_count' => DailyGainerLeaderboard::query()->when($latestLeaderboardDate, fn ($q) => $q->whereDate('leaderboard_date', $latestLeaderboardDate))->count()],
            'missed_gainers' => ['latest' => MissedGainer::query()->orderByDesc('analyzed_at')->orderByDesc('id')->first(), 'rows_count' => MissedGainer::query()->when($latestAnalysisDate, fn ($q) => $q->whereDate('analysis_date', $latestAnalysisDate))->count()],
        ];
    }

    private function troubleshootingCommands(): array
    {
        return [
            'Local/manual commands' => "cd python\npython scripts/run_realtime_monitors_loop.py --once --interval 5 --limit 50\n\nphp artisan cryptospot:scan\nphp artisan cryptospot:daily-gainers\nphp artisan cryptospot:missed-gainers\nphp artisan cryptospot:cleanup\n\nphp artisan schedule:list\nphp artisan schedule:run",
            'VPS commands' => "sudo supervisorctl status cryptospot-realtime-monitors\nsudo tail -f /var/log/cryptospot/realtime-monitors.log\ntail -f storage/logs/cryptospot-scheduler.log\ntail -f storage/logs/laravel.log",
            'Crontab' => 'crontab -l',
        ];
    }
}
