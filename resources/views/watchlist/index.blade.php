@extends('layouts.app')

@php
    $badge = fn ($value) => match ($value) {
        'active', 'strong' => 'badge-green',
        'refreshed', 'watchlist' => 'badge-blue',
        'pending' => 'badge-blue',
        'watching' => 'badge-yellow',
        'converted_to_trade_plan', 'converted_to_trade' => 'badge-green',
        'rejected', 'cancelled' => 'badge-red',
        default => 'badge-gray',
    };
    $fmt = fn ($value, $decimals = 4) => $value === null || $value === '' ? '-' : number_format((float) $value, $decimals);
    $metric = fn ($candidate, $key) => data_get($candidate->raw_payload, 'metrics.' . $key) ?? data_get($candidate->raw_payload, $key);
@endphp

@section('content')
    <header class="page-header page-header-actions">
        <div>
            <h1>Watchlist Candidates</h1>
            <p class="subtitle">Read-only active/refreshed scan candidates and their pending trade plans.</p>
        </div>
        <a class="secondary-button" href="{{ route('cryptospot.scans.latest') }}">Latest Scan Results</a>
    </header>

    <section class="grid metric-grid">
        <article class="card metric-card"><span>Active candidates</span><strong>{{ $summary['active_count'] }}</strong></article>
        <article class="card metric-card"><span>Refreshed candidates</span><strong>{{ $summary['refreshed_count'] }}</strong></article>
        <article class="card metric-card"><span>Updated today</span><strong>{{ $summary['updated_today_count'] }}</strong></article>
        <article class="card metric-card"><span>With pending plans</span><strong>{{ $summary['with_pending_trade_plans_count'] }}</strong></article>
        <article class="card metric-card"><span>Highest score</span><strong>{{ $summary['highest'] ? $summary['highest']->coindcx_symbol . ' / ' . $fmt($summary['highest']->score, 2) : '-' }}</strong></article>
        <article class="card metric-card"><span>Latest scan</span><strong>{{ $summary['latest_scan_run'] ? '#' . $summary['latest_scan_run']->id . ' ' . ($summary['latest_scan_run']->scan_name ?: $summary['latest_scan_run']->started_at?->diffForHumans()) : '-' }}</strong></article>
    </section>

    <section class="card section-card">
        <form class="filters" method="GET" action="{{ route('cryptospot.watchlist.index') }}">
            <input name="q" value="{{ $filters['q'] }}" placeholder="Search symbol/base/quote">
            <select name="status"><option value="all">All statuses</option>@foreach (['active','refreshed','stale','rejected','converted_to_trade_plan'] as $status)<option value="{{ $status }}" @selected($filters['status']===$status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select>
            <select name="score_label"><option value="all">All score labels</option>@foreach (['strong','watchlist','weak'] as $label)<option value="{{ $label }}" @selected($filters['score_label']===$label)>{{ ucfirst($label) }}</option>@endforeach</select>
            <input name="min_score" value="{{ $filters['min_score'] }}" placeholder="Minimum score">
            <select name="has_trade_plan"><option value="all">Any plan</option><option value="yes" @selected($filters['has_trade_plan']==='yes')>Has trade plan</option><option value="no" @selected($filters['has_trade_plan']==='no')>No trade plan</option></select>
            <select name="sort">@foreach (['updated_desc'=>'Updated desc','score_desc'=>'Score desc','symbol_asc'=>'Symbol asc','created_desc'=>'Created desc'] as $key=>$label)<option value="{{ $key }}" @selected($filters['sort']===$key)>{{ $label }}</option>@endforeach</select>
            <select name="per_page"><option value="25" @selected($filters['per_page']===25)>25/page</option><option value="50" @selected($filters['per_page']===50)>50/page</option></select>
            <button class="primary-button" type="submit">Apply</button>
        </form>
    </section>

    <section class="card section-card table-wrap">
        @if ($candidates->isEmpty())
            <p>No watchlist candidates yet. Run a manual scan after Task 18.</p>
        @else
            <table class="table scanner-table">
                <thead><tr><th>Symbol</th><th>Status</th><th>Score</th><th>Score label</th><th>Selection</th><th>Rank</th><th>Last/ref price</th><th>15m %</th><th>1h %</th><th>Vol spike 15m</th><th>Vol spike 1h</th><th>Spread %</th><th>Dist 24h high %</th><th>Risk penalty</th><th>Pending plans</th><th>Updated</th><th>Actions/details</th></tr></thead>
                <tbody>
                    @foreach ($candidates as $candidate)
                        @php
                            $payload = $candidate->raw_payload ?? [];
                            $scoreLabel = data_get($payload, 'score_label', data_get($payload, 'score.score_label'));
                            $selectionType = data_get($payload, 'selection_type', data_get($payload, 'selection.selection_type', '-'));
                            $selectionRank = data_get($payload, 'selection_rank', data_get($payload, 'selection.selection_rank'));
                            $scanResultId = data_get($payload, 'scan_result_id', data_get($payload, 'latest_scan_result_id'));
                            $scanRunId = data_get($payload, 'scan_run_id', data_get($payload, 'latest_scan_run_id'));
                        @endphp
                        <tr>
                            <td><strong>{{ $candidate->coindcx_symbol }}</strong><div class="muted small">{{ $candidate->spotSymbol?->base_asset }}/{{ $candidate->spotSymbol?->quote_asset }}</div></td>
                            <td><span class="badge {{ $badge($candidate->status) }}">{{ $candidate->status }}</span></td>
                            <td>{{ $fmt($candidate->score, 2) }}</td>
                            <td><span class="badge {{ $badge($scoreLabel) }}">{{ $scoreLabel ?: '-' }}</span></td>
                            <td><span class="badge badge-blue">{{ $selectionType }}</span></td>
                            <td>{{ $selectionRank ?? '-' }}</td>
                            <td>{{ $fmt($candidate->last_price ?? $metric($candidate, 'reference_price')) }}</td>
                            <td>{{ $fmt($metric($candidate, 'change_15m_percent'), 2) }}</td>
                            <td>{{ $fmt($metric($candidate, 'change_1h_percent'), 2) }}</td>
                            <td>{{ $fmt($metric($candidate, 'volume_spike_15m'), 2) }}</td>
                            <td>{{ $fmt($metric($candidate, 'volume_spike_1h'), 2) }}</td>
                            <td>{{ $fmt($metric($candidate, 'spread_percent'), 3) }}</td>
                            <td>{{ $fmt($metric($candidate, 'distance_from_24h_high_percent'), 2) }}</td>
                            <td>{{ $fmt($metric($candidate, 'risk_penalty'), 2) }}</td>
                            <td>{{ $candidate->pending_trade_plans_count }}</td>
                            <td class="nowrap">{{ $candidate->updated_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <div class="actions"><a href="{{ route('cryptospot.trade-plans.index', ['candidate_id' => $candidate->id]) }}">Plans</a>@if ($scanRunId)<a href="{{ route('cryptospot.scans.show', $scanRunId) }}">Scan</a>@endif</div>
                                <details class="details-panel"><summary>Details</summary><div class="details-grid"><div><strong>Score</strong><pre>{{ json_encode(data_get($payload, 'score', ['score' => $candidate->score, 'score_label' => $scoreLabel]), JSON_PRETTY_PRINT) }}</pre></div><div><strong>Metrics</strong><pre>{{ json_encode(data_get($payload, 'metrics', []), JSON_PRETTY_PRINT) }}</pre></div><div><strong>History</strong><pre>{{ json_encode(data_get($payload, 'history', []), JSON_PRETTY_PRINT) }}</pre></div><div><strong>Source</strong><pre>{{ json_encode(['latest_scan_run_id' => $scanRunId, 'latest_scan_result_id' => $scanResultId], JSON_PRETTY_PRINT) }}</pre></div></div></details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $candidates->links() }}
        @endif
    </section>
@endsection
