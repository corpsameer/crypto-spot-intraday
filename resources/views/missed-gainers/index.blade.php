@extends('layouts.app')

@section('content')
    <header class="page-header page-header-actions">
        <div>
            <h1>Missed Gainers</h1>
            <p class="subtitle">Review daily top gainers against stored scan results, watchlist, trade plans, and simulated trades.</p>
        </div>
        <a class="secondary-button" href="{{ route('cryptospot.daily-gainers.index', ['date' => $date, 'quote_filter' => $quoteFilter]) }}">Daily Gainers</a>
    </header>

    <section class="grid metric-grid">
        <article class="card metric-card"><span>Analyzed rows</span><strong>{{ $summary['rows_count'] }}</strong></article>
        <article class="card metric-card"><span>Missed completely</span><strong>{{ $summary['missed_completely'] }}</strong></article>
        <article class="card metric-card"><span>Captured not selected</span><strong>{{ $summary['captured_not_selected'] }}</strong></article>
        <article class="card metric-card"><span>Selected no plan</span><strong>{{ $summary['selected_no_trade_plan'] }}</strong></article>
        <article class="card metric-card"><span>Plan not triggered</span><strong>{{ $summary['trade_plan_not_triggered'] }}</strong></article>
        <article class="card metric-card"><span>Trade created</span><strong>{{ $summary['captured_trade_created'] }}</strong></article>
        <article class="card metric-card"><span>Critical/high</span><strong>{{ $summary['critical_high'] }}</strong></article>
    </section>

    <section class="card section-card">
        <form method="GET" class="filters">
            <label>Date <input type="date" name="date" value="{{ $date }}"></label>
            <label>Quote <input name="quote_filter" value="{{ $quoteFilter }}"></label>
            <label>Symbol <input name="q" value="{{ request('q') }}" placeholder="BTCUSDT"></label>
            <label>Min change % <input name="min_change" value="{{ request('min_change') }}" placeholder="10"></label>
            <label>Miss type
                <select name="miss_type"><option value="">All</option>@foreach ($filterOptions['missTypes'] as $option)<option value="{{ $option }}" @selected(request('miss_type') === $option)>{{ $option }}</option>@endforeach</select>
            </label>
            <label>Severity
                <select name="miss_severity"><option value="">All</option>@foreach ($filterOptions['severities'] as $option)<option value="{{ $option }}" @selected(request('miss_severity') === $option)>{{ $option }}</option>@endforeach</select>
            </label>
            <label>Action
                <select name="action_needed"><option value="">All</option>@foreach ($filterOptions['actions'] as $option)<option value="{{ $option }}" @selected(request('action_needed') === $option)>{{ $option }}</option>@endforeach</select>
            </label>
            <div class="actions"><button class="primary-button" type="submit">Filter</button><a class="secondary-button" href="{{ route('cryptospot.missed-gainers.index') }}">Reset</a></div>
        </form>
    </section>

    <section class="card table-wrap">
        <table class="table scanner-table">
            <thead><tr><th>Rank</th><th>Symbol</th><th>Actual 24h %</th><th>Last price</th><th>Matched</th><th>Selected</th><th>Plan</th><th>Sim trade</th><th>Best score</th><th>Label</th><th>Miss type</th><th>Reason</th><th>Severity</th><th>Action</th><th>Links/IDs</th><th>Analyzed</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row->leaderboard_rank }}</td>
                    <td><strong>{{ $row->coindcx_symbol }}</strong></td>
                    <td class="text-green">{{ $row->actual_change_24h_percent !== null ? number_format((float) $row->actual_change_24h_percent, 2).'%' : '-' }}</td>
                    <td>{{ $row->actual_last_price }}</td>
                    <td><span class="badge {{ $row->matched_in_scan ? 'badge-green' : 'badge-red' }}">{{ $row->matched_in_scan ? 'yes' : 'no' }}</span></td>
                    <td><span class="badge {{ $row->selected_for_watchlist ? 'badge-green' : 'badge-gray' }}">{{ $row->selected_for_watchlist ? 'yes' : 'no' }}</span></td>
                    <td><span class="badge {{ $row->trade_plan_created ? 'badge-green' : 'badge-gray' }}">{{ $row->trade_plan_created ? 'yes' : 'no' }}</span></td>
                    <td><span class="badge {{ $row->simulated_trade_created ? 'badge-green' : 'badge-gray' }}">{{ $row->simulated_trade_created ? 'yes' : 'no' }}</span></td>
                    <td>{{ $row->best_final_score !== null ? number_format((float) $row->best_final_score, 2) : '-' }}</td>
                    <td>{{ $row->best_score_label ?: '-' }}</td>
                    <td>{{ $row->miss_type }}</td>
                    <td>{{ $row->miss_reason }}</td>
                    <td><span class="badge badge-{{ in_array($row->miss_severity, ['critical','high'], true) ? 'red' : ($row->miss_severity === 'medium' ? 'yellow' : 'gray') }}">{{ $row->miss_severity }}</span></td>
                    <td>{{ $row->action_needed }}</td>
                    <td class="small">Scan #{{ $row->best_scan_result_id ?: '-' }} / Watch #{{ $row->best_candidate_watchlist_id ?: '-' }} / Plan #{{ $row->best_trade_plan_id ?: '-' }} / Trade #{{ $row->best_simulated_trade_id ?: '-' }}</td>
                    <td class="nowrap">{{ optional($row->analyzed_at)->format('Y-m-d H:i') ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="16" class="muted">No missed gainer analysis rows found for these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $rows->links() }}
    </section>
@endsection
