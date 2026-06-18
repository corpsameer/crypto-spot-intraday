@extends('layouts.app')

@section('content')
@php
    $num = fn ($v) => $v === null ? '-' : number_format((float) $v);
    $pct = fn ($v) => $v === null ? '-' : number_format((float) $v, 2) . '%';
    $dec = fn ($v) => $v === null ? '-' : number_format((float) $v, 2);
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d H:i') : '-';
    $badge = function ($value) {
        $value = strtolower((string) $value);
        return match (true) {
            in_array($value, ['ok','success','completed','active','captured_trade_created'], true) => 'badge badge-green',
            in_array($value, ['warning','partial','medium','pending','running'], true) => 'badge badge-yellow',
            in_array($value, ['error','failed','critical','high'], true) => 'badge badge-red',
            in_array($value, ['low'], true) => 'badge badge-blue',
            default => 'badge badge-gray',
        };
    };
@endphp

<header class="page-header page-header-actions">
    <div>
        <h1>Scanner Performance Analytics</h1>
        <p class="subtitle">Analyze scan funnel, daily gainer capture, watchlist selection, and missed-gainer reasons.</p>
        <p class="muted small">Read-only Laravel analytics. No Python execution, exchange calls, scanner changes, or trading actions are available from this page.</p>
    </div>
    <div class="actions quick-actions">
        <a class="secondary-button" href="{{ route('cryptospot.dashboard') }}">Dashboard</a>
        <a class="secondary-button" href="{{ route('cryptospot.scans.latest') }}">Latest Scanner</a>
        <a class="secondary-button" href="{{ route('cryptospot.daily-gainers.index') }}">Daily Gainers</a>
        <a class="secondary-button" href="{{ route('cryptospot.missed-gainers.index') }}">Missed Gainers</a>
        <a class="secondary-button" href="{{ route('cryptospot.simulated-trades.index') }}">Simulated Trades</a>
    </div>
</header>

<section class="card section-card">
    <form class="filters" method="GET" action="{{ route('cryptospot.analytics.scanner-performance') }}">
        <label>From date<input type="date" name="from" value="{{ $dateFrom }}"></label>
        <label>To date<input type="date" name="to" value="{{ $dateTo }}"></label>
        <label>Quote<input type="text" name="quote" value="{{ $quote }}" placeholder="USDT or ALL"></label>
        <label>Scan status<input type="text" name="status" value="{{ $status }}" placeholder="Optional"></label>
        <label>Min actual gainer change %<input type="number" step="0.01" name="min_change" value="{{ $minChange }}"></label>
        <div class="actions"><button class="primary-button" type="submit">Apply</button><a class="secondary-button" href="{{ route('cryptospot.analytics.scanner-performance') }}">Reset</a></div>
    </form>
</section>

<section class="section-card">
    <h2>High-Level Summary</h2>
    @if ($scanRunStats['total_scan_runs'] === 0)
        <div class="card"><p class="muted">No scan runs found for this date range.</p></div>
    @endif
    <div class="grid metric-grid">
        @foreach ([
            'Total scan runs' => ['total_scan_runs', false], 'Successful scan runs' => ['successful_scan_runs', false], 'Failed/error scan runs' => ['failed_scan_runs', false],
            'Avg active symbols scanned' => ['avg_active_symbols', false], 'Avg prefilter passed' => ['avg_prefilter_passed', false], 'Avg scored candidates' => ['avg_scored', false], 'Avg selected candidates' => ['avg_selected', false],
            'Avg scan duration seconds' => ['avg_duration_seconds', false], 'Total actual '.number_format($minChange, 0).'%+ gainers' => ['actual_gainers', false], 'Capture rate' => ['capture_rate', true], 'Selection rate' => ['selection_rate', true], 'Trade-plan conversion rate' => ['trade_plan_conversion_rate', true],
        ] as $label => [$key, $isPct])
            <article class="card metric-card"><span>{{ $label }}</span><strong>{{ $isPct ? $pct($scanRunStats[$key]) : $dec($scanRunStats[$key]) }}</strong></article>
        @endforeach
    </div>
</section>

<section class="card section-card">
    <h2>Scanner Funnel</h2>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Stage</th><th>Count</th><th>Conversion from previous</th><th>Conversion from start</th><th>Notes</th></tr></thead>
        <tbody>@foreach ($funnelStats as $row)<tr><td>{{ $row['stage'] }}</td><td>{{ $num($row['count']) }}</td><td>{{ $pct($row['from_previous']) }}</td><td>{{ $pct($row['from_start']) }}</td><td class="muted small">{{ $row['notes'] }}</td></tr>@endforeach</tbody>
    </table></div>
</section>

<section class="card section-card">
    <h2>Latest Scan Runs</h2>
    @if ($latestScanRuns->isEmpty())<p class="muted">No scan runs found for this date range.</p>@else
    <div class="table-wrap"><table class="table scanner-table">
        <thead><tr><th>Scan run ID</th><th>Started at</th><th>Status</th><th>Duration seconds</th><th>Active symbols</th><th>Ticker rows fetched</th><th>Prefilter passed</th><th>Scored</th><th>Selected</th><th>Trade plans</th><th>Errors</th><th>Top selected symbol</th><th>Actions</th></tr></thead>
        <tbody>@foreach ($latestScanRuns as $run)<tr>
            <td>#{{ $run->id }}</td><td class="nowrap">{{ $dt($run->started_at) }}</td><td><span class="{{ $badge($run->status) }}">{{ $run->status ?? 'unknown' }}</span></td><td>{{ $num($run->duration_seconds) }}</td><td>{{ $num($run->total_active_symbols) }}</td><td>{{ $num($run->ticker_rows_fetched) }}</td><td>{{ $num($run->prefilter_passed_count) }}</td><td>{{ $num($run->scored_count_actual ?? $run->scored_count) }}</td><td>{{ $num($run->selected_count_actual ?? $run->watchlist_created_count) }}</td><td>{{ $num($run->trade_plans_created_count) }}</td><td>{{ $run->error_message ? \Illuminate\Support\Str::limit($run->error_message, 80) : '-' }}</td><td>{{ $run->top_selected_symbol ?? $run->top_symbol ?? '-' }}</td><td>@if (Route::has('cryptospot.scans.show'))<a href="{{ route('cryptospot.scans.show', $run->id) }}">Open scan</a>@else - @endif</td>
        </tr>@endforeach</tbody>
    </table></div>@endif
</section>

<section class="card section-card">
    <h2>Daily Gainer Capture</h2>
    @if ($dailyCaptureStats->isEmpty())
        <p class="muted">No daily gainer leaderboard found. Run daily gainer builder.</p>
        <pre>cd python
python scripts/run_daily_gainer_leaderboard_once.py --quote USDT --limit 100
python scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100</pre>
    @else
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Date</th><th>Actual gainers</th><th>Matched in scan</th><th>Capture rate</th><th>Selected</th><th>Selection rate</th><th>Trade plans</th><th>Sim trades</th><th>Missed completely</th><th>Captured not selected</th><th>Top gainer</th><th>Top change</th></tr></thead>
        <tbody>@foreach ($dailyCaptureStats as $row)<tr><td>{{ $row['date'] }}</td><td>{{ $num($row['actual_gainers']) }}</td><td>{{ $num($row['matched']) }}</td><td>{{ $pct($row['capture_rate']) }}</td><td>{{ $num($row['selected']) }}</td><td>{{ $pct($row['selection_rate']) }}</td><td>{{ $num($row['trade_plans']) }}</td><td>{{ $num($row['sim_trades']) }}</td><td>{{ $num($row['missed_completely']) }}</td><td>{{ $num($row['captured_not_selected']) }}</td><td>{{ $row['top_gainer'] }}</td><td>{{ $pct($row['top_change']) }}</td></tr>@endforeach</tbody>
    </table></div>@endif
</section>

<section class="section-card">
    <h2>Miss Reason Breakdown</h2>
    @if ($missedReasonStats['miss_type']->isEmpty())
        <div class="card"><p class="muted">No missed gainer analysis found. Run missed gainer analyzer.</p><pre>cd python
python scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100</pre></div>
    @else
        <div class="grid">
            @foreach (['miss_type' => 'Miss type count', 'miss_reason' => 'Miss reason count', 'action_needed' => 'Action needed count', 'miss_severity' => 'Severity count'] as $key => $title)
                <article class="card"><h3>{{ $title }}</h3><div class="table-wrap"><table class="table"><thead><tr><th>Type/reason/action/severity</th><th>Count</th><th>Avg actual change</th><th>Max actual change</th><th>Avg best score</th></tr></thead><tbody>@foreach ($missedReasonStats[$key] as $row)<tr><td><span class="{{ $badge($row->label) }}">{{ $row->label }}</span></td><td>{{ $num($row->count) }}</td><td>{{ $pct($row->avg_change) }}</td><td>{{ $pct($row->max_change) }}</td><td>{{ $dec($row->avg_best_score) }}</td></tr>@endforeach</tbody></table></div></article>
            @endforeach
        </div>
    @endif
</section>

<section class="card section-card">
    <h2>Top Review Candidates</h2>
    @if ($worstMissedGainers->isEmpty())<p class="muted">No review-needed missed gainers found for this date range.</p>@else
    <div class="table-wrap"><table class="table scanner-table"><thead><tr><th>Date</th><th>Rank</th><th>Symbol</th><th>Actual change</th><th>Matched</th><th>Selected</th><th>Trade plan</th><th>Sim trade</th><th>Best score</th><th>Score label</th><th>Miss type</th><th>Miss reason</th><th>Action needed</th></tr></thead><tbody>@foreach ($worstMissedGainers as $row)<tr><td>{{ $row->analysis_date }}</td><td>#{{ $row->leaderboard_rank ?? '-' }}</td><td>{{ $row->coindcx_symbol }}</td><td>{{ $pct($row->actual_change_24h_percent) }}</td><td>{{ $row->matched_in_scan ? 'Yes' : 'No' }}</td><td>{{ $row->selected_for_watchlist ? 'Yes' : 'No' }}</td><td>{{ $row->trade_plan_created ? 'Yes' : 'No' }}</td><td>{{ $row->simulated_trade_created ? 'Yes' : 'No' }}</td><td>{{ $dec($row->best_final_score) }}</td><td>{{ $row->best_score_label ?? '-' }}</td><td><span class="{{ $badge($row->miss_type) }}">{{ $row->miss_type ?? 'unknown' }}</span></td><td>{{ $row->miss_reason ?? '-' }}</td><td>{{ $row->action_needed ?? '-' }}</td></tr>@endforeach</tbody></table></div>@endif
</section>

<section class="card section-card">
    <h2>Health / Run Reliability</h2>
    <div class="grid metric-grid"><article class="card metric-card"><span>Error count in range</span><strong>{{ $num($scanHealthStats['errors']) }}</strong></article><article class="card metric-card"><span>Warning count in range</span><strong>{{ $num($scanHealthStats['warnings']) }}</strong></article></div>
    @if ($scanHealthStats['latest']->isEmpty())<p class="muted">No system health log rows found for scanner services.</p>@else
    <div class="table-wrap"><table class="table"><thead><tr><th>Service</th><th>Latest status</th><th>Latest message</th><th>Checked at</th></tr></thead><tbody>@foreach ($scanHealthStats['latest'] as $row)<tr><td>{{ $row->service_name }}</td><td><span class="{{ $badge($row->status) }}">{{ $row->status }}</span></td><td>{{ $row->message ?? '-' }}</td><td>{{ $dt($row->checked_at) }}</td></tr>@endforeach</tbody></table></div>@endif
</section>
@endsection
