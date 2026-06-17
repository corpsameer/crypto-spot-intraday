@php $pct = fn ($value) => number_format((float) ($value ?? 0), 2) . '%'; @endphp
<section class="grid metric-grid">
    <article class="card metric-card"><span>Open trades</span><strong>{{ $summary['open_count'] }}</strong></article>
    <article class="card metric-card"><span>Closed trades</span><strong>{{ $summary['closed_count'] }}</strong></article>
    <article class="card metric-card"><span>TP1 hit</span><strong>{{ $summary['tp1_hit_count'] }}</strong></article>
    <article class="card metric-card"><span>TP2 hit</span><strong>{{ $summary['tp2_hit_count'] }}</strong></article>
    <article class="card metric-card"><span>SL closed</span><strong>{{ $summary['sl_closed_count'] }}</strong></article>
    <article class="card metric-card"><span>Open unrealized P&amp;L</span><strong>{{ $pct($summary['unrealized_pnl']) }}</strong></article>
    <article class="card metric-card"><span>Closed realized P&amp;L</span><strong>{{ $pct($summary['realized_pnl']) }}</strong></article>
    <article class="card metric-card"><span>Best open max gain</span><strong>{{ $summary['best_open_trade'] ? $summary['best_open_trade']->coindcx_symbol . ' / ' . $pct($summary['best_open_trade']->max_gain_percent) : '-' }}</strong></article>
    <article class="card metric-card"><span>Worst open drawdown</span><strong>{{ $summary['worst_open_trade'] ? $summary['worst_open_trade']->coindcx_symbol . ' / ' . $pct($summary['worst_open_trade']->max_drawdown_percent) : '-' }}</strong></article>
</section>
