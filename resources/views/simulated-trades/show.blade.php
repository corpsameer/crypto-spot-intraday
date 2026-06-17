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
    <header class="page-header page-header-actions">
        <div>
            <h1>{{ $simulatedTrade->coindcx_symbol }} Simulated Trade</h1>
            <p class="subtitle">
                <span class="badge {{ $badge($simulatedTrade->status) }}">{{ $simulatedTrade->status }}</span>
                <span class="badge {{ $badge($simulatedTrade->entry_strategy) }}">{{ $simulatedTrade->entry_strategy ?: '-' }}</span>
                Score {{ $fmt($simulatedTrade->score, 2) }} / {{ $simulatedTrade->score_label ?: '-' }}
                · Current P&amp;L <strong class="{{ $pnlClass($simulatedTrade->current_pnl_percent) }}">{{ $pct($simulatedTrade->current_pnl_percent) }}</strong>
                @if ($simulatedTrade->closed_at) · Final P&amp;L <strong class="{{ $pnlClass($simulatedTrade->final_pnl_percent) }}">{{ $pct($simulatedTrade->final_pnl_percent) }}</strong>@endif
            </p>
        </div>
        <a class="secondary-button" href="{{ route('cryptospot.simulated-trades.index') }}">Back to Simulated Trades</a>
    </header>

    <section class="grid metric-grid">
        @foreach ([
            'Entry price' => $fmt($simulatedTrade->entry_price), 'Latest price' => $fmt($simulatedTrade->latest_price), 'TP1 price' => $fmt($simulatedTrade->tp1_price), 'TP2 price' => $fmt($simulatedTrade->tp2_price), 'SL price' => $fmt($simulatedTrade->sl_price), 'Trailing start price' => $fmt($simulatedTrade->trailing_start_price), 'Current trailing SL' => $fmt($simulatedTrade->current_trailing_sl_price), 'Highest price' => $fmt($simulatedTrade->highest_price), 'Lowest price' => $fmt($simulatedTrade->lowest_price),
        ] as $label => $value)
            <article class="card metric-card"><span>{{ $label }}</span><strong>{{ $value }}</strong></article>
        @endforeach
    </section>

    <section class="grid metric-grid">
        @foreach ([
            'Current P&L %' => $pct($simulatedTrade->current_pnl_percent), 'Max gain %' => $pct($simulatedTrade->max_gain_percent), 'Max drawdown %' => $pct($simulatedTrade->max_drawdown_percent), 'Final P&L %' => $pct($simulatedTrade->final_pnl_percent), 'Close reason' => $simulatedTrade->close_reason ?: '-',
        ] as $label => $value)
            <article class="card metric-card"><span>{{ $label }}</span><strong>{{ $value }}</strong></article>
        @endforeach
    </section>

    <section class="card section-card">
        <h2>Timeline timestamps</h2>
        <div class="details-grid">
            @foreach ([
                'Entry triggered at' => $simulatedTrade->entry_triggered_at?->format('Y-m-d H:i:s') ?: '-', 'TP1 hit at' => $simulatedTrade->tp1_hit_at?->format('Y-m-d H:i:s') ?: '-', 'TP2 hit at' => $simulatedTrade->tp2_hit_at?->format('Y-m-d H:i:s') ?: '-', 'SL hit at' => $simulatedTrade->sl_hit_at?->format('Y-m-d H:i:s') ?: '-', 'Trailing started at' => $simulatedTrade->trailing_started_at?->format('Y-m-d H:i:s') ?: '-', 'Trailing stopped at' => $simulatedTrade->trailing_stopped_at?->format('Y-m-d H:i:s') ?: '-', 'Closed at' => $simulatedTrade->closed_at?->format('Y-m-d H:i:s') ?: '-', 'Expires at' => $simulatedTrade->expires_at?->format('Y-m-d H:i:s') ?: '-',
            ] as $label => $value)
                <div><strong>{{ $label }}</strong><p>{{ $value }}</p></div>
            @endforeach
        </div>
    </section>

    <section class="card section-card">
        <h2>Source links</h2>
        <div class="actions">
            @if ($simulatedTrade->trade_plan_id)<a href="{{ route('cryptospot.trade-plans.index', ['q' => $simulatedTrade->coindcx_symbol]) }}">Trade plan #{{ $simulatedTrade->trade_plan_id }}</a>@endif
            @if ($simulatedTrade->candidate_watchlist_id)<a href="{{ route('cryptospot.watchlist.index', ['q' => $simulatedTrade->coindcx_symbol]) }}">Candidate #{{ $simulatedTrade->candidate_watchlist_id }}</a>@endif
            @if ($simulatedTrade->scan_run_id)<a href="{{ route('cryptospot.scans.show', $simulatedTrade->scan_run_id) }}">Scan run #{{ $simulatedTrade->scan_run_id }}</a>@endif
            @if ($simulatedTrade->scan_result_id && $simulatedTrade->scan_run_id)<a href="{{ route('cryptospot.scans.show', $simulatedTrade->scan_run_id) }}#scan-result-{{ $simulatedTrade->scan_result_id }}">Scan result #{{ $simulatedTrade->scan_result_id }}</a>@endif
            @if (! $simulatedTrade->trade_plan_id && ! $simulatedTrade->candidate_watchlist_id && ! $simulatedTrade->scan_run_id && ! $simulatedTrade->scan_result_id)<span class="muted">No source links available.</span>@endif
        </div>
    </section>

    <section class="card section-card">
        <h2>Trade events timeline</h2>
        @include('simulated-trades.partials.events', ['events' => $simulatedTrade->events])
    </section>

    <section class="card section-card">
        <h2>Raw payload</h2>
        <details class="details-panel" open><summary>Simulated trade payload</summary><pre>{{ json_encode($simulatedTrade->raw_payload, JSON_PRETTY_PRINT) ?: '-' }}</pre></details>
    </section>
@endsection
