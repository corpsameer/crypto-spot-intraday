@extends('layouts.app')

@php
    $badge = fn (?string $value, array $map = []) => $map[$value] ?? 'badge-gray';
    $missTypeBadge = fn (?string $value) => $badge($value, [
        'missed_completely' => 'badge-red', 'captured_not_selected' => 'badge-orange', 'selected_no_trade_plan' => 'badge-yellow',
        'trade_plan_not_triggered' => 'badge-purple', 'captured_trade_created' => 'badge-green', 'captured_trade_underperformed' => 'badge-blue',
    ]);
    $severityBadge = fn (?string $value) => $badge($value, ['critical' => 'badge-red', 'high' => 'badge-red', 'medium' => 'badge-yellow', 'low' => 'badge-blue', 'none' => 'badge-green']);
    $yesNo = fn ($value) => $value ? 'badge-green' : 'badge-gray';
    $dash = fn ($value) => filled($value) ? $value : '-';
@endphp

@section('content')
    <header class="page-header page-header-actions">
        <div>
            <h1>Missed Gainers</h1>
            <p class="subtitle">Review actual daily top gainers against scanner selection, trade plans, and simulated trades.</p>
        </div>
        <div class="actions">
            <a class="secondary-button" href="{{ route('cryptospot.dashboard') }}">Dashboard</a>
            <a class="secondary-button" href="{{ route('cryptospot.daily-gainers.index', ['date' => $date, 'quote_filter' => $quoteFilter === 'ALL' ? null : $quoteFilter]) }}">Daily Gainers</a>
        </div>
    </header>

    <section class="grid metric-grid">
        <article class="card metric-card"><span>Analysis date</span><strong>{{ $summary['analysis_date'] }}</strong></article>
        <article class="card metric-card"><span>Total analyzed rows</span><strong>{{ $summary['rows_count'] }}</strong></article>
        <article class="card metric-card"><span>Missed completely</span><strong>{{ $summary['missed_completely'] }}</strong></article>
        <article class="card metric-card"><span>Captured not selected</span><strong>{{ $summary['captured_not_selected'] }}</strong></article>
        <article class="card metric-card"><span>Selected no trade plan</span><strong>{{ $summary['selected_no_trade_plan'] }}</strong></article>
        <article class="card metric-card"><span>Trade plan not triggered</span><strong>{{ $summary['trade_plan_not_triggered'] }}</strong></article>
        <article class="card metric-card"><span>Trade created</span><strong>{{ $summary['captured_trade_created'] }}</strong></article>
        <article class="card metric-card"><span>Critical/high misses</span><strong>{{ $summary['critical_high'] }}</strong></article>
        <article class="card metric-card"><span>Top actual gainer</span><strong>{{ $summary['top_gainer'] ? $summary['top_gainer']->coindcx_symbol.' / '.number_format((float) $summary['top_gainer']->actual_change_24h_percent, 2).'%' : '-' }}</strong></article>
        <article class="card metric-card"><span>Average best score</span><strong>{{ $summary['avg_best_score'] !== null ? number_format((float) $summary['avg_best_score'], 2) : '-' }}</strong></article>
    </section>

    <section class="card section-card">
        <form method="GET" action="{{ route('cryptospot.missed-gainers.index') }}" class="filters">
            <label>Date <input type="date" name="date" value="{{ $date }}"></label>
            <label>Quote <select name="quote">@foreach ($quoteOptions as $option)<option value="{{ $option }}" @selected($quoteFilter === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Search symbol <input name="q" value="{{ request('q') }}" placeholder="ESPORTSUSDT"></label>
            <label>Miss type <select name="miss_type">@foreach ($missTypes as $option)<option value="{{ $option }}" @selected(request('miss_type', 'all') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Severity <select name="severity">@foreach ($severities as $option)<option value="{{ $option }}" @selected(request('severity', 'all') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Action needed <select name="action_needed">@foreach ($actions as $option)<option value="{{ $option }}" @selected(request('action_needed', 'all') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Matched in scan <select name="matched">@foreach (['all','yes','no'] as $option)<option value="{{ $option }}" @selected(request('matched', 'all') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Selected <select name="selected">@foreach (['all','yes','no'] as $option)<option value="{{ $option }}" @selected(request('selected', 'all') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Trade plan <select name="trade_plan">@foreach (['all','yes','no'] as $option)<option value="{{ $option }}" @selected(request('trade_plan', 'all') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Sim trade <select name="simulated_trade">@foreach (['all','yes','no'] as $option)<option value="{{ $option }}" @selected(request('simulated_trade', 'all') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Minimum actual change <input name="min_change" value="{{ request('min_change') }}" placeholder="10"></label>
            <label>Sort <select name="sort">@foreach ($sortOptions as $option)<option value="{{ $option }}" @selected(request('sort', 'severity_rank') === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Per page <select name="per_page">@foreach ([25, 50] as $option)<option value="{{ $option }}" @selected((int) request('per_page', 25) === $option)>{{ $option }}</option>@endforeach</select></label>
            <div class="actions"><button class="primary-button" type="submit">Filter</button><a class="secondary-button" href="{{ route('cryptospot.missed-gainers.index') }}">Reset</a></div>
        </form>
    </section>

    @if ($rows->isEmpty())
        <section class="card section-card">
            <p class="muted">No missed gainer analysis found yet. Run the missed gainer analyzer after building the daily gainer leaderboard.</p>
            <pre>cd python
python scripts/run_daily_gainer_leaderboard_once.py --quote USDT --limit 100
python scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100</pre>
        </section>
    @else
        <section class="card table-wrap">
            <table class="table scanner-table">
                <thead><tr><th>Rank</th><th>Symbol</th><th>Actual 24h change %</th><th>Actual last price</th><th>Actual quote volume</th><th>Matched</th><th>Selected</th><th>Trade plan</th><th>Sim trade</th><th>Entry triggered</th><th>Best score</th><th>Score label</th><th>Miss type</th><th>Miss reason</th><th>Severity</th><th>Action needed</th><th>Best scan run</th><th>Best scan result</th><th>Trade plan ID</th><th>Sim trade ID</th><th>Analyzed at</th><th>Actions/details</th></tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $dash($row->leaderboard_rank) }}</td>
                        <td><strong>{{ $row->coindcx_symbol }}</strong><div class="muted small">{{ $row->base_asset }}/{{ $row->quote_asset }}</div></td>
                        <td class="text-green">{{ $row->actual_change_24h_percent !== null ? number_format((float) $row->actual_change_24h_percent, 2).'%' : '-' }}</td>
                        <td>{{ $row->actual_last_price !== null ? rtrim(rtrim(number_format((float) $row->actual_last_price, 12, '.', ''), '0'), '.') : '-' }}</td>
                        <td>{{ $row->actual_quote_volume_24h !== null ? number_format((float) $row->actual_quote_volume_24h, 2) : '-' }}</td>
                        <td><span class="badge {{ $yesNo($row->matched_in_scan) }}">{{ $row->matched_in_scan ? 'Yes' : 'No' }}</span></td>
                        <td><span class="badge {{ $yesNo($row->selected_for_watchlist) }}">{{ $row->selected_for_watchlist ? 'Yes' : 'No' }}</span></td>
                        <td><span class="badge {{ $yesNo($row->trade_plan_created) }}">{{ $row->trade_plan_created ? 'Yes' : 'No' }}</span></td>
                        <td><span class="badge {{ $yesNo($row->simulated_trade_created) }}">{{ $row->simulated_trade_created ? 'Yes' : 'No' }}</span></td>
                        <td><span class="badge {{ $yesNo($row->entry_triggered) }}">{{ $row->entry_triggered ? 'Yes' : 'No' }}</span></td>
                        <td>{{ $row->best_final_score !== null ? number_format((float) $row->best_final_score, 2) : '-' }}</td>
                        <td>{{ $dash($row->best_score_label) }}</td>
                        <td><span class="badge {{ $missTypeBadge($row->miss_type) }}">{{ $dash($row->miss_type) }}</span></td>
                        <td>{{ $dash($row->miss_reason) }}</td>
                        <td><span class="badge {{ $severityBadge($row->miss_severity) }}">{{ $dash($row->miss_severity) }}</span></td>
                        <td>{{ $dash($row->action_needed) }}</td>
                        <td>@if ($row->best_scan_run_id && Route::has('cryptospot.scans.show'))<a href="{{ route('cryptospot.scans.show', $row->best_scan_run_id) }}">#{{ $row->best_scan_run_id }}</a>@else {{ $row->best_scan_run_id ? '#'.$row->best_scan_run_id : '-' }} @endif</td>
                        <td>@if ($row->best_scan_run_id && $row->best_scan_result_id && Route::has('cryptospot.scans.show'))<a href="{{ route('cryptospot.scans.show', $row->best_scan_run_id) }}#scan-result-{{ $row->best_scan_result_id }}">#{{ $row->best_scan_result_id }}</a>@else {{ $row->best_scan_result_id ? '#'.$row->best_scan_result_id : '-' }} @endif</td>
                        <td>@if ($row->best_trade_plan_id && Route::has('cryptospot.trade-plans.index'))<a href="{{ route('cryptospot.trade-plans.index', ['q' => $row->coindcx_symbol]) }}">#{{ $row->best_trade_plan_id }}</a>@else {{ $row->best_trade_plan_id ? '#'.$row->best_trade_plan_id : '-' }} @endif</td>
                        <td>@if ($row->best_simulated_trade_id && Route::has('cryptospot.simulated-trades.show'))<a href="{{ route('cryptospot.simulated-trades.show', $row->best_simulated_trade_id) }}">#{{ $row->best_simulated_trade_id }}</a>@else {{ $row->best_simulated_trade_id ? '#'.$row->best_simulated_trade_id : '-' }} @endif</td>
                        <td class="nowrap">{{ optional($row->analyzed_at)->format('Y-m-d H:i') ?: '-' }}</td>
                        <td><a href="{{ route('cryptospot.missed-gainers.show', $row) }}">Details</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $rows->links() }}
        </section>
    @endif
@endsection
