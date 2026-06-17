@extends('layouts.app')

@php
    $badge = fn ($value) => match ($value) {
        'active' => 'badge-blue', 'tp1_hit' => 'badge-blue', 'tp2_hit' => 'badge-green', 'trailing_active' => 'badge-purple', 'closed_sl' => 'badge-red', 'closed_tp1', 'closed_tp2', 'closed_trailing' => 'badge-green', 'expired' => 'badge-gray', 'cancelled', 'error' => 'badge-red', 'breakout' => 'badge-purple', 'pullback' => 'badge-orange', 'strong' => 'badge-green', 'watchlist' => 'badge-blue', 'weak' => 'badge-gray', default => 'badge-gray',
    };
    $fmt = fn ($value, $decimals = 8) => $value === null || $value === '' ? '-' : rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $pct = fn ($value) => $value === null || $value === '' ? '-' : number_format((float) $value, 2) . '%';
    $pnlClass = fn ($value) => (float) $value > 0 ? 'text-green' : ((float) $value < 0 ? 'text-red' : '');
@endphp

@section('content')
    <header class="page-header page-header-actions"><div><h1>Simulated Trades</h1><p class="subtitle">Read-only review of open and closed simulated trades with TP/SL events.</p></div><a class="secondary-button" href="{{ route('cryptospot.trade-plans.index') }}">Trade Plans</a></header>

    @include('simulated-trades.partials.summary-cards', ['summary' => $summary])

    <section class="card section-card">
        <form class="filters" method="GET" action="{{ route('cryptospot.simulated-trades.index') }}">
            <input name="q" value="{{ $filters['q'] }}" placeholder="Search symbol/base/quote">
            <select name="status"><option value="all">All statuses</option>@foreach (['active','tp1_hit','tp2_hit','trailing_active','closed_sl','closed_tp1','closed_tp2','closed_trailing','expired','cancelled','error'] as $status)<option value="{{ $status }}" @selected($filters['status']===$status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select>
            <select name="entry_strategy"><option value="all">All strategies</option>@foreach (['breakout','pullback'] as $strategy)<option value="{{ $strategy }}" @selected($filters['entry_strategy']===$strategy)>{{ ucfirst($strategy) }}</option>@endforeach</select>
            <select name="score_label"><option value="all">All score labels</option>@foreach (['strong','watchlist','weak'] as $label)<option value="{{ $label }}" @selected($filters['score_label']===$label)>{{ ucfirst($label) }}</option>@endforeach</select>
            <select name="trade_state"><option value="all">Open and closed</option><option value="open" @selected($filters['trade_state']==='open')>Open</option><option value="closed" @selected($filters['trade_state']==='closed')>Closed</option></select>
            <input name="min_pnl" value="{{ $filters['min_pnl'] }}" placeholder="Minimum current P&L %">
            <select name="sort">@foreach (['updated_desc'=>'Updated desc','entry_time_desc'=>'Entry time desc','pnl_desc'=>'P&L desc','pnl_asc'=>'P&L asc','max_gain_desc'=>'Max gain desc','drawdown_asc'=>'Drawdown asc','score_desc'=>'Score desc','symbol_asc'=>'Symbol asc'] as $key=>$label)<option value="{{ $key }}" @selected($filters['sort']===$key)>{{ $label }}</option>@endforeach</select>
            <select name="per_page"><option value="25" @selected($filters['per_page']===25)>25/page</option><option value="50" @selected($filters['per_page']===50)>50/page</option></select>
            <button class="primary-button" type="submit">Apply</button>
        </form>
    </section>

    <section class="card section-card table-wrap">
        @if ($simulatedTrades->isEmpty())
            <p>No simulated trades yet. Trigger a trade plan and run breakout/pullback entry simulator.</p>
        @else
            <table class="table scanner-table">
                <thead><tr><th>Symbol</th><th>Status</th><th>Strategy</th><th>Score</th><th>Score label</th><th>Entry price</th><th>Latest price</th><th>Current P&amp;L %</th><th>Max gain %</th><th>Max drawdown %</th><th>TP1</th><th>TP2</th><th>SL</th><th>TP1 hit at</th><th>TP2 hit at</th><th>SL hit at</th><th>Trailing active</th><th>Final P&amp;L %</th><th>Entry triggered at</th><th>Expires at</th><th>Closed at</th><th>Close reason</th><th>Actions/details</th></tr></thead>
                <tbody>
                    @foreach ($simulatedTrades as $trade)
                        <tr>
                            <td><strong>{{ $trade->coindcx_symbol }}</strong><div class="muted small">{{ $trade->base_asset }}/{{ $trade->quote_asset }}</div></td>
                            <td><span class="badge {{ $badge($trade->status) }}">{{ $trade->status }}</span></td>
                            <td><span class="badge {{ $badge($trade->entry_strategy) }}">{{ $trade->entry_strategy ?: '-' }}</span></td>
                            <td>{{ $fmt($trade->score, 2) }}</td><td><span class="badge {{ $badge($trade->score_label) }}">{{ $trade->score_label ?: '-' }}</span></td>
                            <td>{{ $fmt($trade->entry_price) }}</td><td>{{ $fmt($trade->latest_price) }}</td><td class="{{ $pnlClass($trade->current_pnl_percent) }}">{{ $pct($trade->current_pnl_percent) }}</td><td class="{{ $pnlClass($trade->max_gain_percent) }}">{{ $pct($trade->max_gain_percent) }}</td><td class="{{ $pnlClass($trade->max_drawdown_percent) }}">{{ $pct($trade->max_drawdown_percent) }}</td><td>{{ $fmt($trade->tp1_price) }}</td><td>{{ $fmt($trade->tp2_price) }}</td><td>{{ $fmt($trade->sl_price) }}</td>
                            <td class="nowrap">{{ $trade->tp1_hit_at?->format('Y-m-d H:i') ?: '-' }}</td><td class="nowrap">{{ $trade->tp2_hit_at?->format('Y-m-d H:i') ?: '-' }}</td><td class="nowrap">{{ $trade->sl_hit_at?->format('Y-m-d H:i') ?: '-' }}</td><td>{{ $trade->trailing_active ? 'Yes' : 'No' }}</td><td class="{{ $pnlClass($trade->final_pnl_percent) }}">{{ $pct($trade->final_pnl_percent) }}</td><td class="nowrap">{{ $trade->entry_triggered_at?->format('Y-m-d H:i') ?: '-' }}</td><td class="nowrap">{{ $trade->expires_at?->format('Y-m-d H:i') ?: '-' }}</td><td class="nowrap">{{ $trade->closed_at?->format('Y-m-d H:i') ?: '-' }}</td><td>{{ $trade->close_reason ?: '-' }}</td>
                            <td><a href="{{ route('cryptospot.simulated-trades.show', $trade) }}">Details</a><div class="muted small">Events: {{ $trade->events->count() }}</div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $simulatedTrades->links() }}
        @endif
    </section>
@endsection
