@extends('layouts.app')

@section('content')
    @php
        $statusClasses = [
            'scored' => 'badge-green',
            'prefilter_rejected' => 'badge-red',
            'failed' => 'badge-red',
            'candles_fetched' => 'badge-blue',
            'metrics_calculated' => 'badge-blue',
            'discovered' => 'badge-yellow',
            'prefilter_passed' => 'badge-yellow',
        ];
        $fmt = fn ($value, $decimals = 2) => $value === null ? '-' : number_format((float) $value, $decimals);
    @endphp

    <header class="page-header page-header-actions">
        <div>
            <h1>Market Scanner / Latest Scan Results</h1>
            <p class="subtitle">Read-only review of scheduled/manual CoinDCX spot scan runs.</p>
        </div>
        <a class="secondary-button" href="{{ route('cryptospot.dashboard') }}">Dashboard</a>
    </header>

    @if (! $scanRun)
        <section class="card">
            <h2>No scan runs found yet</h2>
            <p>No scan runs found yet. Run <code>python scripts/run_manual_scan_once.py</code> from the python folder to generate scan results.</p>
        </section>
    @else
        <section class="card section-card">
            <h2>Recent scan runs</h2>
            <div class="run-list">
                @foreach ($recentScanRuns as $recentScanRun)
                    <a class="run-link {{ $recentScanRun->is($scanRun) ? 'active' : '' }}" href="{{ route('cryptospot.scans.show', $recentScanRun) }}">
                        #{{ $recentScanRun->id }} - {{ $recentScanRun->scan_name ?: 'Unnamed scan' }} - {{ $recentScanRun->status }} - {{ optional($recentScanRun->started_at)->format('Y-m-d H:i') ?: 'not started' }} - {{ $recentScanRun->top_symbol ?: '-' }}/{{ $fmt($recentScanRun->top_score) }}
                    </a>
                @endforeach
            </div>
        </section>

        <section class="grid metric-grid" aria-label="Scan run summary">
            @foreach ([
                'Scan' => '#' . $scanRun->id . ' ' . ($scanRun->scan_name ?: 'Unnamed'),
                'Status / Type' => $scanRun->status . ' / ' . $scanRun->scan_type,
                'Started / Completed' => (optional($scanRun->started_at)->format('Y-m-d H:i:s') ?: '-') . ' / ' . (optional($scanRun->completed_at)->format('Y-m-d H:i:s') ?: '-'),
                'Duration Seconds' => $scanRun->duration_seconds ?? '-',
                'Quote Filter' => $scanRun->quote_filter ?: '-',
                'Total Active Symbols' => $scanRun->total_active_symbols,
                'Ticker Rows Fetched' => $scanRun->ticker_rows_fetched,
                'Total Results' => $summary['total_results'],
                'Prefilter Passed / Rejected' => $summary['prefilter_passed_count'] . ' / ' . $summary['prefilter_rejected_count'],
                'Candles Fetched' => $summary['candles_fetched_count'],
                'Metrics Calculated' => $summary['metrics_calculated_count'],
                'Scored / Score Passed' => $summary['scored_count'] . ' / ' . $summary['score_passed_count'],
                'Watchlist Selected' => $summary['selected_for_watchlist_count'],
                'Threshold / Fallback' => $summary['threshold_selected_count'] . ' / ' . $summary['fallback_selected_count'],
                'Top Symbol / Score' => ($scanRun->top_symbol ?: '-') . ' / ' . $fmt($scanRun->top_score),
            ] as $label => $value)
                <article class="card metric-card"><span>{{ $label }}</span><strong>{{ $value }}</strong></article>
            @endforeach
        </section>

        <section class="card section-card">
            <h2>Market Context</h2>
            @if ($marketContext)
                <div class="grid metric-grid">
                    <div><span>BTC Symbol</span><strong>{{ data_get($marketContext, 'btc_symbol', '-') }}</strong></div>
                    <div><span>BTC Price</span><strong>{{ $fmt(data_get($marketContext, 'btc_price')) }}</strong></div>
                    <div><span>ETH Symbol</span><strong>{{ data_get($marketContext, 'eth_symbol', '-') }}</strong></div>
                    <div><span>ETH Price</span><strong>{{ $fmt(data_get($marketContext, 'eth_price')) }}</strong></div>
                    <div><span>Market Condition</span><strong>{{ data_get($marketContext, 'market_condition', '-') }}</strong></div>
                    <div><span>Market Snapshot ID</span><strong>{{ data_get($marketContext, 'market_snapshot_id', '-') }}</strong></div>
                </div>
            @else
                <p>Not available.</p>
            @endif
        </section>

        <section class="card section-card">
            <h2>Scan Results</h2>
            <form class="filters" method="GET" action="{{ route('cryptospot.scans.show', $scanRun) }}">
                <input name="q" value="{{ $filters['q'] }}" placeholder="Search symbol/base/quote">
                <select name="status">
                    @foreach (['all', 'discovered', 'prefilter_rejected', 'prefilter_passed', 'candles_fetched', 'metrics_calculated', 'scored', 'failed'] as $option)
                        <option value="{{ $option }}" @selected($filters['status'] === $option)>{{ str_replace('_', ' ', ucfirst($option)) }}</option>
                    @endforeach
                </select>
                <select name="selection">
                    @foreach (['all', 'selected', 'threshold', 'fallback', 'not_selected'] as $option)
                        <option value="{{ $option }}" @selected($filters['selection'] === $option)>{{ str_replace('_', ' ', ucfirst($option)) }}</option>
                    @endforeach
                </select>
                <select name="score_label">
                    @foreach (['all', 'strong', 'watchlist', 'weak'] as $option)
                        <option value="{{ $option }}" @selected($filters['score_label'] === $option)>{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
                <select name="rejection_reason">
                    <option value="all">All rejection reasons</option>
                    @foreach ($rejectionReasons as $reason)
                        <option value="{{ $reason }}" @selected($filters['rejection_reason'] === $reason)>{{ $reason }}</option>
                    @endforeach
                </select>
                <input name="min_score" value="{{ $filters['min_score'] }}" placeholder="Min score" type="number" step="0.0001">
                <select name="sort">
                    @foreach (['final_score_desc', 'final_score_asc', 'volume_desc', 'change_1h_desc', 'change_15m_desc', 'volume_spike_desc', 'spread_asc', 'selection_rank_asc', 'symbol_asc'] as $option)
                        <option value="{{ $option }}" @selected($filters['sort'] === $option)>{{ str_replace('_', ' ', ucfirst($option)) }}</option>
                    @endforeach
                </select>
                <select name="per_page">
                    <option value="25" @selected($filters['per_page'] === 25)>25/page</option>
                    <option value="50" @selected($filters['per_page'] === 50)>50/page</option>
                </select>
                <button class="primary-button" type="submit">Apply</button>
                <a class="secondary-button" href="{{ route('cryptospot.scans.show', $scanRun) }}">Reset</a>
            </form>

            <div class="table-wrap">
                <table class="table scanner-table">
                    <thead><tr><th>Rank</th><th>Selected</th><th>Symbol</th><th>Status</th><th>Score</th><th>15m %</th><th>1h %</th><th>4h %</th><th>24h %</th><th>Vol Spike 15m</th><th>Vol Spike 1h</th><th>Spread %</th><th>Depth USDT</th><th>Dist 24h High %</th><th>Close Strength</th><th>RS vs BTC</th><th>Risk Penalty</th><th>Rejection Reason</th><th>Evaluated</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($results as $result)
                            <tr>
                                <td>{{ $result->selected_for_watchlist ? ($result->selection_rank ?: '-') : '-' }}</td>
                                <td><span class="badge {{ $result->selection_type === 'threshold' ? 'badge-green' : ($result->selection_type === 'fallback' ? 'badge-yellow' : 'badge-gray') }}">{{ $result->selection_type ?: 'no' }}</span></td>
                                <td><strong>{{ $result->coindcx_symbol }}</strong><br><small>{{ $result->base_asset }}/{{ $result->quote_asset }}</small></td>
                                <td><span class="badge {{ $statusClasses[$result->status] ?? 'badge-gray' }}">{{ $result->status }}</span></td>
                                <td>{{ $fmt($result->final_score) }}<br><span class="badge {{ $result->score_label === 'strong' ? 'badge-green' : ($result->score_label === 'watchlist' ? 'badge-blue' : 'badge-gray') }}">{{ $result->score_label ?: '-' }}</span></td>
                                <td>{{ $fmt($result->change_15m_percent) }}</td><td>{{ $fmt($result->change_1h_percent) }}</td><td>{{ $fmt($result->change_4h_percent) }}</td><td>{{ $fmt($result->change_24h_percent) }}</td>
                                <td>{{ $fmt($result->volume_spike_15m) }}</td><td>{{ $fmt($result->volume_spike_1h) }}</td><td>{{ $fmt($result->spread_percent, 4) }}</td><td>{{ $fmt($result->orderbook_depth_usdt, 0) }}</td>
                                <td>{{ $fmt($result->distance_from_24h_high_percent) }}</td><td>{{ $fmt($result->candle_close_strength) }}</td><td>{{ $fmt($result->relative_strength_vs_btc) }}</td><td>{{ $fmt($result->risk_penalty) }}</td>
                                <td>{{ $result->rejection_reason ?: '-' }}</td><td>{{ optional($result->evaluated_at)->format('Y-m-d H:i:s') ?: '-' }}</td>
                                <td>@include('scan-results.partials.score-breakdown', ['result' => $result])</td>
                            </tr>
                        @empty
                            <tr><td colspan="20">No scan results match the current filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $results->links() }}
        </section>
    @endif
@endsection
