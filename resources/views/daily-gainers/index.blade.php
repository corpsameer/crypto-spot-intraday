@extends('layouts.app')

@section('content')
    <header class="page-header page-header-actions">
        <div>
            <h1>Daily Gainer Leaderboard</h1>
            <p class="subtitle">Actual CoinDCX spot 24h top gainers captured from a one-shot ticker fetch.</p>
        </div>
        <a class="secondary-button" href="{{ route('cryptospot.dashboard') }}">Dashboard</a>
    </header>

    <section class="grid metric-grid">
        <article class="card metric-card"><span>Date</span><strong>{{ $summary['leaderboard_date'] }}</strong></article>
        <article class="card metric-card"><span>Top gainer</span><strong>{{ $summary['top_gainer'] ?: '-' }}</strong></article>
        <article class="card metric-card"><span>Top change</span><strong>{{ $summary['top_change'] !== null ? number_format((float) $summary['top_change'], 2) . '%' : '-' }}</strong></article>
        <article class="card metric-card"><span>Rows</span><strong>{{ $summary['rows_count'] }}</strong></article>
        <article class="card metric-card"><span>Matched scans</span><strong>{{ $summary['matched_in_scan_count'] }}</strong></article>
        <article class="card metric-card"><span>Selected</span><strong>{{ $summary['selected_for_watchlist_count'] }}</strong></article>
        <article class="card metric-card"><span>Trade plans</span><strong>{{ $summary['trade_plan_created_count'] }}</strong></article>
    </section>

    <section class="card section-card">
        <form class="filters" method="GET" action="{{ route('cryptospot.daily-gainers.index') }}">
            <label>Date <input type="date" name="date" value="{{ $date }}"></label>
            <label>Quote
                <select name="quote_filter">
                    @foreach (['USDT', 'INR', 'ALL'] as $quote)
                        <option value="{{ $quote }}" @selected($quoteFilter === $quote)>{{ $quote }}</option>
                    @endforeach
                </select>
            </label>
            <label>Symbol <input type="search" name="q" value="{{ request('q') }}" placeholder="BTCUSDT"></label>
            <label>Matched
                <select name="matched_in_scan"><option value="all">All</option><option value="yes" @selected(request('matched_in_scan') === 'yes')>Yes</option><option value="no" @selected(request('matched_in_scan') === 'no')>No</option></select>
            </label>
            <label>Selected
                <select name="selected_for_watchlist"><option value="all">All</option><option value="yes" @selected(request('selected_for_watchlist') === 'yes')>Yes</option><option value="no" @selected(request('selected_for_watchlist') === 'no')>No</option></select>
            </label>
            <label>Min change <input type="number" step="0.01" name="min_change" value="{{ request('min_change') }}"></label>
            <label>Sort
                <select name="sort"><option value="rank" @selected(request('sort', 'rank') === 'rank')>Rank</option><option value="change" @selected(request('sort') === 'change')>Change</option><option value="volume" @selected(request('sort') === 'volume')>Volume</option></select>
            </label>
            <div class="actions"><button class="primary-button" type="submit">Filter</button></div>
        </form>
    </section>

    <section class="card table-wrap">
        <table class="table scanner-table">
            <thead><tr><th>Rank</th><th>Symbol</th><th>Last price</th><th>24h change</th><th>24h quote volume</th><th>Spread</th><th>Matched</th><th>Selected</th><th>Trade plan</th><th>Best score</th><th>Score label</th><th>Scan run</th><th>Scan result</th><th>Updated</th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row->rank }}</td>
                        <td><strong>{{ $row->coindcx_symbol }}</strong></td>
                        <td>{{ $row->last_price !== null ? rtrim(rtrim(number_format((float) $row->last_price, 12, '.', ''), '0'), '.') : '-' }}</td>
                        <td class="{{ (float) $row->change_24h_percent >= 0 ? 'text-green' : 'text-red' }}">{{ $row->change_24h_percent !== null ? number_format((float) $row->change_24h_percent, 2) . '%' : '-' }}</td>
                        <td>{{ $row->quote_volume_24h !== null ? number_format((float) $row->quote_volume_24h, 2) : '-' }}</td>
                        <td>{{ $row->spread_percent !== null ? number_format((float) $row->spread_percent, 3) . '%' : '-' }}</td>
                        <td><span class="badge {{ $row->matched_in_scan ? 'badge-green' : 'badge-gray' }}">{{ $row->matched_in_scan ? 'Yes' : 'No' }}</span></td>
                        <td><span class="badge {{ $row->selected_for_watchlist ? 'badge-green' : 'badge-gray' }}">{{ $row->selected_for_watchlist ? 'Yes' : 'No' }}</span></td>
                        <td><span class="badge {{ $row->trade_plan_created ? 'badge-blue' : 'badge-gray' }}">{{ $row->trade_plan_created ? 'Yes' : 'No' }}</span></td>
                        <td>{{ $row->best_final_score !== null ? number_format((float) $row->best_final_score, 2) : '-' }}</td>
                        <td>{{ $row->best_score_label ?: '-' }}</td>
                        <td>{{ $row->best_scan_run_id ?: '-' }}</td>
                        <td>{{ $row->best_scan_result_id ?: '-' }}</td>
                        <td class="nowrap">{{ optional($row->updated_at)->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="14">No daily gainer rows found for these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $rows->links() }}
    </section>
@endsection
