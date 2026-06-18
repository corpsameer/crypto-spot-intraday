@extends('layouts.app')

@section('content')
@php
    $fmtDate = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s') : '-';
    $age = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->diffForHumans() : '-';
    $badge = function ($value) {
        $value = strtolower((string) $value);
        return match (true) {
            in_array($value, ['ok', 'success', 'completed', 'fresh'], true) => 'badge badge-green',
            in_array($value, ['warning', 'stale'], true) => 'badge badge-orange',
            in_array($value, ['error', 'failed', 'critical'], true) => 'badge badge-red',
            in_array($value, ['missing'], true) => 'badge badge-red',
            default => 'badge badge-gray',
        };
    };
    $metaExcerpt = function ($meta) {
        if (blank($meta)) return '-';
        $encoded = is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_SLASHES);
        return \Illuminate\Support\Str::limit($encoded, 160);
    };
@endphp
<header class="page-header page-header-actions">
    <div>
        <h1>System Health</h1>
        <p class="subtitle">Monitor scheduled scans, realtime trade monitors, analytics jobs, freshness, warnings, and errors.</p>
        <p class="muted small">Server time: {{ now()->format('Y-m-d H:i:s T') }} · Schedule timezone: Asia/Kolkata · Login-protected route.</p>
    </div>
    <div class="actions quick-actions">
        <a class="secondary-button" href="{{ route('cryptospot.dashboard') }}">Dashboard</a>
        <a class="secondary-button" href="{{ route('cryptospot.analytics.scanner-performance') }}">Scanner Performance</a>
        <a class="secondary-button" href="{{ route('cryptospot.analytics.trade-performance') }}">Trade Performance</a>
        <a class="secondary-button" href="{{ route('cryptospot.daily-gainers.index') }}">Daily Gainers</a>
        <a class="secondary-button" href="{{ route('cryptospot.missed-gainers.index') }}">Missed Gainers</a>
        <a class="secondary-button" href="{{ route('cryptospot.simulated-trades.index') }}">Simulated Trades</a>
    </div>
</header>

<section class="section-card">
    <h2>Overall Health</h2>
    <div class="grid metric-grid">
        @foreach ([
            'Overall status' => ['overall_status', true], 'Healthy services' => ['healthy_count', false], 'Warning services' => ['warning_count', false], 'Error services' => ['error_count', false], 'Missing services' => ['missing_count', false], 'Stale services' => ['stale_count', false], 'Latest scan age' => ['latest_scan_age', false], 'Latest realtime monitor age' => ['latest_realtime_age', false], 'Recent errors' => ['recent_errors_24h', false], 'Recent warnings' => ['recent_warnings_24h', false],
        ] as $label => [$key, $status])
            <article class="card metric-card"><span>{{ $label }}</span><strong>@if($status)<span class="{{ $badge($summaryStats[$key]) }}">{{ $summaryStats[$key] }}</span>@else{{ $summaryStats[$key] }}@endif</strong></article>
        @endforeach
    </div>
</section>

<section class="section-card">
    <h2>Filters</h2>
    <form method="GET" class="card filters">
        <label>Service<select name="service_name"><option value="">All</option>@foreach($serviceOptions as $service)<option value="{{ $service }}" @selected($filters['serviceFilter'] === $service)>{{ $service }}</option>@endforeach</select></label>
        <label>Status<select name="status"><option value="">All</option>@foreach(['ok','success','completed','warning','error','failed','missing'] as $status)<option value="{{ $status }}" @selected($filters['statusFilter'] === $status)>{{ $status }}</option>@endforeach</select></label>
        <label>Severity<select name="severity"><option value="">All</option>@foreach(['ok','warning','critical','unknown'] as $severity)<option value="{{ $severity }}" @selected($filters['severityFilter'] === $severity)>{{ $severity }}</option>@endforeach</select></label>
        <label>From<input type="date" name="from" value="{{ $filters['from']->toDateString() }}"></label>
        <label>To<input type="date" name="to" value="{{ $filters['to']->toDateString() }}"></label>
        <div class="actions"><button class="primary-button" type="submit">Apply</button><a class="secondary-button" href="{{ route('cryptospot.system-health.index') }}">Reset</a></div>
    </form>
</section>

<section class="section-card"><h2>Latest Service Health</h2><div class="card table-wrap"><table class="table scanner-table"><thead><tr><th>Service</th><th>Category</th><th>Latest status</th><th>Freshness</th><th>Last checked at</th><th>Age</th><th>Expected cadence</th><th>Message</th><th>Error 24h</th><th>Warning 24h</th><th>Actions/help text</th></tr></thead><tbody>@foreach($serviceHealthRows as $row)<tr><td>{{ $row['service'] }}</td><td>{{ $row['category'] }}</td><td><span class="{{ $badge($row['status']) }}">{{ $row['status'] }}</span></td><td><span class="{{ $badge($row['freshness']) }}">{{ $row['freshness'] }}</span></td><td class="nowrap">{{ $fmtDate($row['last_checked_at']) }}</td><td>{{ $row['age'] ?? '-' }}</td><td>{{ $row['expected_cadence'] }}</td><td>{{ $row['message'] ?? '-' }}</td><td>{{ $row['error_count_24h'] }}</td><td>{{ $row['warning_count_24h'] }}</td><td>{{ $row['help'] }}</td></tr>@endforeach</tbody></table></div></section>

<section class="section-card"><h2>Recent Warnings and Errors</h2><div class="card table-wrap">@if($recentWarningsErrors->isEmpty())<p class="muted">No warnings or errors found for the selected filters.</p>@else<table class="table"><thead><tr><th>Checked at</th><th>Service</th><th>Status</th><th>Message</th><th>Meta summary</th><th>Created at</th></tr></thead><tbody>@foreach($recentWarningsErrors as $log)<tr><td class="nowrap">{{ $fmtDate($log->checked_at) }}</td><td>{{ $log->service_name }}</td><td><span class="{{ $badge($log->status) }}">{{ $log->status }}</span></td><td>{{ $log->message ?? '-' }}</td><td>@if($log->meta)<details><summary>{{ $metaExcerpt($log->meta) }}</summary><pre>{{ json_encode($log->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@else-@endif</td><td class="nowrap">{{ $fmtDate($log->created_at) }}</td></tr>@endforeach</tbody></table>{{ $recentWarningsErrors->links() }}@endif</div></section>

<section class="section-card"><h2>Scheduled Job Freshness</h2><div class="card table-wrap"><table class="table"><thead><tr><th>Job</th><th>Command</th><th>Schedule</th><th>Expected service</th><th>Last run</th><th>Frequency</th><th>Stale threshold</th><th>Status</th></tr></thead><tbody>@foreach($schedulerSummary as $job)<tr><td>{{ $job['label'] }}</td><td><code>{{ $job['command'] }}</code></td><td>{{ $job['cadence'] }}</td><td>{{ $job['service'] }}</td><td>{{ $fmtDate($job['last_run']) }}</td><td>{{ $job['cadence'] }}</td><td>{{ $job['threshold_minutes'] }} min</td><td><span class="{{ $badge($job['freshness']) }}">{{ $job['freshness'] }}</span></td></tr>@endforeach</tbody></table><h3>Scheduler cron instruction</h3><pre>* * * * * cd /var/www/crypto-spot-intraday && php artisan schedule:run >> /dev/null 2>&1</pre></div></section>

<section class="section-card"><h2>Realtime Monitor Freshness</h2><div class="card table-wrap"><p><strong>Supervisor process:</strong> cryptospot-realtime-monitors · <strong>Interval:</strong> 15 seconds · <strong>Script:</strong> python/scripts/run_realtime_monitors_loop.py</p><table class="table"><thead><tr><th>Service</th><th>Last checked</th><th>Age</th><th>Status</th><th>Stale flag</th><th>Message</th></tr></thead><tbody>@foreach($realtimeSummary as $row)<tr><td>{{ $row['service'] }}</td><td>{{ $fmtDate($row['last_checked_at']) }}</td><td>{{ $row['age'] ?? '-' }}</td><td><span class="{{ $badge($row['status']) }}">{{ $row['status'] }}</span></td><td><span class="{{ $badge($row['freshness']) }}">{{ $row['freshness'] }}</span></td><td>{{ $row['message'] ?? '-' }}</td></tr>@endforeach</tbody></table><h3>Supervisor check commands (text only)</h3><pre>sudo supervisorctl status cryptospot-realtime-monitors
sudo tail -f /var/log/cryptospot/realtime-monitors.log
sudo supervisorctl restart cryptospot-realtime-monitors</pre></div></section>

<section class="section-card"><h2>Data Freshness / Sanity Checks</h2><div class="card table-wrap"><table class="table"><thead><tr><th>Dataset</th><th>Latest timestamp / key</th><th>Details</th><th>Age</th></tr></thead><tbody>
<tr><td>Latest scan_run</td><td>#{{ $dataFreshnessStats['scan_run']['latest']?->id ?? '-' }} · {{ $dataFreshnessStats['scan_run']['latest']?->status ?? '-' }}</td><td>Started {{ $fmtDate($dataFreshnessStats['scan_run']['latest']?->started_at) }} · Completed {{ $fmtDate($dataFreshnessStats['scan_run']['latest']?->completed_at) }}</td><td>{{ $age($dataFreshnessStats['scan_run']['latest']?->started_at) }}</td></tr>
<tr><td>Latest scan_result</td><td>{{ $fmtDate($dataFreshnessStats['scan_result']['latest']?->created_at) }}</td><td>Count today: {{ $dataFreshnessStats['scan_result']['count_today'] }}</td><td>{{ $age($dataFreshnessStats['scan_result']['latest']?->created_at) }}</td></tr>
<tr><td>Latest candidate_watchlist</td><td>{{ $fmtDate($dataFreshnessStats['candidate_watchlist']['latest']?->created_at) }}</td><td>Active/open count: {{ $dataFreshnessStats['candidate_watchlist']['open_count'] }}</td><td>{{ $age($dataFreshnessStats['candidate_watchlist']['latest']?->created_at) }}</td></tr>
<tr><td>Latest trade_plan</td><td>{{ $fmtDate($dataFreshnessStats['trade_plan']['latest']?->updated_at ?? $dataFreshnessStats['trade_plan']['latest']?->created_at) }}</td><td>Open: {{ $dataFreshnessStats['trade_plan']['open_count'] }} · Triggered: {{ $dataFreshnessStats['trade_plan']['triggered_count'] }}</td><td>{{ $age($dataFreshnessStats['trade_plan']['latest']?->updated_at ?? $dataFreshnessStats['trade_plan']['latest']?->created_at) }}</td></tr>
<tr><td>Latest simulated_trade</td><td>{{ $fmtDate($dataFreshnessStats['simulated_trade']['latest']?->updated_at) }}</td><td>Open: {{ $dataFreshnessStats['simulated_trade']['open_count'] }} · Closed: {{ $dataFreshnessStats['simulated_trade']['closed_count'] }}</td><td>{{ $age($dataFreshnessStats['simulated_trade']['latest']?->updated_at) }}</td></tr>
<tr><td>Latest trade_event</td><td>{{ $fmtDate($dataFreshnessStats['trade_event']['latest']?->event_time) }}</td><td>Event type: {{ $dataFreshnessStats['trade_event']['latest']?->event_type ?? '-' }}</td><td>{{ $age($dataFreshnessStats['trade_event']['latest']?->event_time) }}</td></tr>
<tr><td>Latest daily_gainer_leaderboard</td><td>{{ $dataFreshnessStats['daily_gainer_leaderboard']['latest']?->leaderboard_date ?? '-' }}</td><td>Updated {{ $fmtDate($dataFreshnessStats['daily_gainer_leaderboard']['latest']?->updated_at) }} · Rows: {{ $dataFreshnessStats['daily_gainer_leaderboard']['rows_count'] }}</td><td>{{ $age($dataFreshnessStats['daily_gainer_leaderboard']['latest']?->updated_at) }}</td></tr>
<tr><td>Latest missed_gainers</td><td>{{ $dataFreshnessStats['missed_gainers']['latest']?->analysis_date ?? '-' }}</td><td>Analyzed {{ $fmtDate($dataFreshnessStats['missed_gainers']['latest']?->analyzed_at) }} · Rows: {{ $dataFreshnessStats['missed_gainers']['rows_count'] }}</td><td>{{ $age($dataFreshnessStats['missed_gainers']['latest']?->analyzed_at) }}</td></tr>
</tbody></table></div></section>

<section class="section-card"><h2>Latest Scan Details</h2><div class="card"><div class="details-grid"><div><strong>Scan run ID</strong><br>#{{ $latestScanStats['scan']?->id ?? '-' }}</div><div><strong>Status</strong><br><span class="{{ $badge($latestScanStats['scan']?->status) }}">{{ $latestScanStats['scan']?->status ?? '-' }}</span></div><div><strong>Started at</strong><br>{{ $fmtDate($latestScanStats['scan']?->started_at) }}</div><div><strong>Duration</strong><br>{{ $latestScanStats['duration'] ?? '-' }}</div><div><strong>Active symbols</strong><br>{{ $latestScanStats['scan']?->total_active_symbols ?? 0 }}</div><div><strong>Ticker rows fetched</strong><br>{{ $latestScanStats['scan']?->ticker_rows_fetched ?? 0 }}</div><div><strong>Prefilter passed</strong><br>{{ $latestScanStats['scan']?->prefilter_passed_count ?? 0 }}</div><div><strong>Selected</strong><br>{{ $latestScanStats['scan']?->watchlist_created_count ?? 0 }}</div><div><strong>Trade plans created</strong><br>{{ $latestScanStats['scan']?->trade_plans_created_count ?? 0 }}</div><div><strong>Errors</strong><br>{{ $latestScanStats['scan']?->error_message ?? '-' }}</div></div>@if($latestScanStats['raw_payload'])<details class="details-panel"><summary>Raw payload / summary JSON</summary><pre>{{ json_encode($latestScanStats['raw_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif</div></section>

<section class="section-card"><h2>Troubleshooting Commands</h2><div class="grid">@foreach($troubleshootingCommands as $title => $commands)<article class="card"><h3>{{ $title }}</h3><pre>{{ $commands }}</pre></article>@endforeach</div><p class="muted small">Commands are displayed for operator use only. This page does not execute shell, Python, Supervisor, CoinDCX, private API, or trading actions.</p></section>
@endsection
