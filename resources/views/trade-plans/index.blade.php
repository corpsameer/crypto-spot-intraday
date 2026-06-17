@extends('layouts.app')

@php
    $badge = fn ($value) => match ($value) {
        'pending', 'watchlist' => 'badge-blue', 'watching' => 'badge-yellow', 'triggered', 'converted_to_trade', 'strong' => 'badge-green', 'expired' => 'badge-gray', 'cancelled', 'rejected' => 'badge-red', 'breakout' => 'badge-purple', 'pullback' => 'badge-orange', default => 'badge-gray',
    };
    $fmt = fn ($value, $decimals = 4) => $value === null || $value === '' ? '-' : number_format((float) $value, $decimals);
@endphp

@section('content')
    <header class="page-header page-header-actions"><div><h1>Trade Plans</h1><p class="subtitle">Read-only pending/refreshed breakout and pullback plans generated from scan candidates.</p></div><a class="secondary-button" href="{{ route('cryptospot.watchlist.index') }}">Watchlist</a></header>

    <section class="grid metric-grid">
        <article class="card metric-card"><span>Pending plans</span><strong>{{ $summary['pending_count'] }}</strong></article>
        <article class="card metric-card"><span>Watching plans</span><strong>{{ $summary['watching_count'] }}</strong></article>
        <article class="card metric-card"><span>Breakout plans</span><strong>{{ $summary['breakout_count'] }}</strong></article>
        <article class="card metric-card"><span>Pullback plans</span><strong>{{ $summary['pullback_count'] }}</strong></article>
        <article class="card metric-card"><span>Expiring within 1h</span><strong>{{ $summary['expiring_1h_count'] }}</strong></article>
        <article class="card metric-card"><span>Expired plans</span><strong>{{ $summary['expired_count'] }}</strong></article>
        <article class="card metric-card"><span>Highest pending</span><strong>{{ $summary['highest_pending'] ? $summary['highest_pending']->coindcx_symbol . ' / ' . $fmt($summary['highest_pending']->score, 2) : '-' }}</strong></article>
    </section>

    <section class="card section-card">
        <form class="filters" method="GET" action="{{ route('cryptospot.trade-plans.index') }}">
            <input name="q" value="{{ $filters['q'] }}" placeholder="Search symbol/base/quote">
            @if ($filters['candidate_id'])<input type="hidden" name="candidate_id" value="{{ $filters['candidate_id'] }}">@endif
            <select name="status"><option value="all">All statuses</option>@foreach (['pending','watching','triggered','expired','cancelled','rejected','converted_to_trade'] as $status)<option value="{{ $status }}" @selected($filters['status']===$status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select>
            <select name="entry_strategy"><option value="all">All strategies</option>@foreach (['breakout','pullback'] as $strategy)<option value="{{ $strategy }}" @selected($filters['entry_strategy']===$strategy)>{{ ucfirst($strategy) }}</option>@endforeach</select>
            <select name="score_label"><option value="all">All score labels</option>@foreach (['strong','watchlist','weak'] as $label)<option value="{{ $label }}" @selected($filters['score_label']===$label)>{{ ucfirst($label) }}</option>@endforeach</select>
            <input name="min_score" value="{{ $filters['min_score'] }}" placeholder="Minimum score">
            <select name="expiry"><option value="all">Any expiry</option><option value="active" @selected($filters['expiry']==='active')>Active</option><option value="expiring_1h" @selected($filters['expiry']==='expiring_1h')>Expiring 1h</option><option value="expired" @selected($filters['expiry']==='expired')>Expired</option></select>
            <select name="sort">@foreach (['updated_desc'=>'Updated desc','score_desc'=>'Score desc','expires_asc'=>'Expires asc','symbol_asc'=>'Symbol asc','trigger_price_asc'=>'Trigger asc'] as $key=>$label)<option value="{{ $key }}" @selected($filters['sort']===$key)>{{ $label }}</option>@endforeach</select>
            <select name="per_page"><option value="25" @selected($filters['per_page']===25)>25/page</option><option value="50" @selected($filters['per_page']===50)>50/page</option></select>
            <button class="primary-button" type="submit">Apply</button>
        </form>
    </section>

    <section class="card section-card table-wrap">
        @if ($tradePlans->isEmpty())
            <p>No trade plans yet. Run a scan after Task 19.</p>
        @else
            <table class="table scanner-table">
                <thead><tr><th>Symbol</th><th>Status</th><th>Strategy</th><th>Score</th><th>Score label</th><th>Reference</th><th>Trigger</th><th>Entry</th><th>TP1</th><th>TP2</th><th>SL</th><th>Risk/reward</th><th>Valid from</th><th>Expires at</th><th>Time to expiry</th><th>Source scan</th><th>Candidate</th><th>Actions/details</th></tr></thead>
                <tbody>
                    @foreach ($tradePlans as $plan)
                        <tr>
                            <td><strong>{{ $plan->coindcx_symbol }}</strong><div class="muted small">{{ $plan->base_asset }}/{{ $plan->quote_asset }}</div></td>
                            <td><span class="badge {{ $badge($plan->status) }}">{{ $plan->status }}</span></td>
                            <td><span class="badge {{ $badge($plan->entry_strategy) }}">{{ $plan->entry_strategy }}</span></td>
                            <td>{{ $fmt($plan->score, 2) }}</td>
                            <td><span class="badge {{ $badge($plan->score_label) }}">{{ $plan->score_label ?: '-' }}</span></td>
                            <td>{{ $fmt($plan->reference_price) }}</td><td>{{ $fmt($plan->trigger_price) }}</td><td>{{ $fmt($plan->entry_price) }}</td><td>{{ $fmt($plan->tp1_price) }}</td><td>{{ $fmt($plan->tp2_price) }}</td><td>{{ $fmt($plan->sl_price) }}</td><td>{{ $fmt($plan->risk_reward_ratio, 2) }}</td>
                            <td class="nowrap">{{ $plan->valid_from?->format('Y-m-d H:i') ?: '-' }}</td><td class="nowrap">{{ $plan->expires_at?->format('Y-m-d H:i') ?: '-' }}</td><td class="nowrap">{{ $plan->expires_at ? ($plan->expires_at->isPast() ? 'Expired' : $plan->expires_at->diffForHumans()) : '-' }}</td>
                            <td>@if ($plan->scan_run_id)<a href="{{ route('cryptospot.scans.show', $plan->scan_run_id) }}">#{{ $plan->scan_run_id }}</a>@else - @endif</td>
                            <td>@if ($plan->candidate_watchlist_id)<a href="{{ route('cryptospot.watchlist.index', ['q' => $plan->coindcx_symbol]) }}">#{{ $plan->candidate_watchlist_id }}</a>@else - @endif</td>
                            <td>
                                <div class="actions">@if ($plan->candidate_watchlist_id)<a href="{{ route('cryptospot.watchlist.index', ['q' => $plan->coindcx_symbol]) }}">Candidate</a>@endif @if ($plan->scan_run_id)<a href="{{ route('cryptospot.scans.show', $plan->scan_run_id) }}">Scan</a>@endif</div>
                                <details class="details-panel"><summary>Details</summary><div class="details-grid"><div><strong>Reason</strong><pre>{{ $plan->plan_reason ?: '-' }}</pre></div><div><strong>Notes</strong><pre>{{ $plan->notes ?: '-' }}</pre></div><div><strong>Plan calculation</strong><pre>{{ json_encode(data_get($plan->raw_payload, 'plan_calculation', []), JSON_PRETTY_PRINT) }}</pre></div><div><strong>Metrics</strong><pre>{{ json_encode(data_get($plan->raw_payload, 'metrics', []), JSON_PRETTY_PRINT) }}</pre></div><div><strong>History</strong><pre>{{ json_encode(data_get($plan->raw_payload, 'history', []), JSON_PRETTY_PRINT) }}</pre></div></div></details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $tradePlans->links() }}
        @endif
    </section>
@endsection
