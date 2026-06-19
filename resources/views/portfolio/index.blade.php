@extends('layouts.app')

@section('content')
@php
    $inr = fn ($v) => $v === null ? '-' : '₹' . number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '-' : number_format((float) $v, 2) . '%';
    $num = fn ($v) => $v === null ? '-' : number_format((float) $v, 2);
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d H:i') : '-';
    $age = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->diffForHumans(null, true) : '-';
    $pnlClass = fn ($v) => (float) ($v ?? 0) > 0 ? 'text-success' : ((float) ($v ?? 0) < 0 ? 'text-danger' : 'text-muted');
    $badge = function ($value) { $value = strtolower((string) $value); return match (true) {
        in_array($value, ['active','approved','capital_reserved','trade_entry','trade_exit','capital_released'], true) => 'badge badge-green',
        in_array($value, ['pending','watching','triggered','tp1_hit','tp2_hit','trailing_active','capital_reserved'], true) => 'badge badge-yellow',
        in_array($value, ['rejected','expired','closed_sl','error'], true) => 'badge badge-red',
        default => 'badge badge-gray',
    };};
    $setup = fn ($row) => $row->setup_type ?? $row->entry_strategy ?? '-';
    $tradePnl = fn ($row) => $row->net_pnl_amount ?? $row->realized_pnl_amount;
@endphp
<header class="page-header page-header-actions"><div><h1>Portfolio</h1><p class="subtitle">Read-only INR paper portfolio dashboard and monthly capital growth analytics.</p></div><a class="secondary-button" href="{{ route('cryptospot.dashboard') }}">Dashboard</a></header>

@if (! $portfolio)
    <section class="card section-card"><h2>No active portfolio</h2><p class="muted">No active portfolio account was found. Seed or activate a portfolio account to see INR paper portfolio analytics.</p></section>
@else
    @if ($reconciliation['mismatch'])
        <div class="card section-card"><p class="text-danger"><strong>Portfolio reconciliation warning:</strong> calculated values differ from stored account totals. Run realtime monitor/release manager.</p></div>
    @endif

    <section class="section-card"><h2>Portfolio Summary</h2><div class="grid metric-grid">
        @foreach ([
            ['Portfolio name', $summary['name'], null], ['Currency', $summary['currency'], null], ['Starting capital', $inr($summary['starting_capital']), null], ['Current cash', $inr($summary['current_cash']), null], ['Reserved cash', $inr($summary['reserved_cash']), null], ['Deployed capital', $inr($summary['deployed_capital']), null], ['Realized P&L', $inr($summary['realized_pnl']), $summary['realized_pnl']], ['Unrealized P&L', $inr($summary['unrealized_pnl']), $summary['unrealized_pnl']], ['Total equity', $inr($summary['total_equity']), null], ['Total return %', $pct($summary['total_return_percent']), $summary['total_return_percent']], ['Updated at', $dt($summary['updated_at']), null]
        ] as $card)
            <article class="card metric-card"><span>{{ $card[0] }}</span><strong class="{{ $card[2] === null ? '' : $pnlClass($card[2]) }}">{{ $card[1] }}</strong></article>
        @endforeach
    </div></section>

    <section class="section-card"><h2>Capital Usage</h2><div class="grid metric-grid">
        @foreach ([
            'Max open trades'=>$capitalUsage['max_open_trades'], 'Open trades count'=>$capitalUsage['open_trades_count'], 'Preferred open trades'=>$capitalUsage['preferred_open_trades'], 'Max pending trade plans'=>$capitalUsage['max_pending_trade_plans'], 'Pending trade plans count'=>$capitalUsage['pending_trade_plans_count'], 'Max total open opportunities'=>$capitalUsage['max_total_open_opportunities'], 'Total open opportunities'=>$capitalUsage['total_open_opportunities'], 'Available slots'=>$capitalUsage['available_slots'], 'Reserved cash %'=>$pct($capitalUsage['reserved_cash_percent']), 'Available cash for new plans'=>$inr($capitalUsage['tradable_cash'])
        ] as $label => $value)<article class="card metric-card"><span>{{ $label }}</span><strong>{{ $value }}</strong></article>@endforeach
    </div><p class="muted small">Available cash: {{ $inr($capitalUsage['available_cash']) }} · Reserve cash amount: {{ $inr($capitalUsage['reserve_cash_amount']) }}</p></section>

    <section class="section-card"><h2>Monthly Growth</h2><div class="grid metric-grid">
        @foreach ([
            'Month'=>$monthlyGrowth['month'], 'Starting equity for month'=>$inr($monthlyGrowth['starting_equity']), 'Current equity'=>$inr($monthlyGrowth['current_equity']), 'Monthly return %'=>$pct($monthlyGrowth['monthly_return_percent']), 'Monthly realized P&L'=>$inr($monthlyGrowth['monthly_realized_pnl']), 'Monthly unrealized P&L'=>$inr($monthlyGrowth['monthly_unrealized_pnl']), 'Monthly closed trades'=>$monthlyGrowth['monthly_closed_trades'], 'Monthly winning trades'=>$monthlyGrowth['monthly_winning_trades'], 'Monthly losing trades'=>$monthlyGrowth['monthly_losing_trades'], 'Monthly win rate'=>$pct($monthlyGrowth['monthly_win_rate']), 'Best trade INR P&L'=>($monthlyGrowth['best_trade']?->coindcx_symbol ?? '-') . ' / ' . $inr($monthlyGrowth['best_trade_pnl']), 'Worst trade INR P&L'=>($monthlyGrowth['worst_trade']?->coindcx_symbol ?? '-') . ' / ' . $inr($monthlyGrowth['worst_trade_pnl'])
        ] as $label => $value)<article class="card metric-card"><span>{{ $label }}</span><strong>{{ $value }}</strong></article>@endforeach
    </div><p class="muted small">{{ $monthlyGrowth['estimated_note'] }}</p></section>

    <section class="section-card"><h2>Open Portfolio Trades</h2><div class="card table-wrap"><table class="table scanner-table"><thead><tr><th>Symbol</th><th>Setup/strategy</th><th>Status</th><th>Entry</th><th>Current</th><th>Allocated</th><th>Quantity</th><th>Current value</th><th>Unrealized P&L</th><th>P&L %</th><th>Entry time</th><th>Age</th></tr></thead><tbody>@forelse($openTrades as $t)<tr><td>{{ $t->coindcx_symbol }}</td><td>{{ $setup($t) }}</td><td><span class="{{ $badge($t->status) }}">{{ $t->status }}</span></td><td>{{ $num($t->entry_price) }}</td><td>{{ $num($t->latest_price) }}</td><td>{{ $inr($t->allocated_capital) }}</td><td>{{ $num($t->quantity) }}</td><td>{{ $inr($t->current_value) }}</td><td class="{{ $pnlClass($t->unrealized_pnl_amount) }}">{{ $inr($t->unrealized_pnl_amount) }}</td><td class="{{ $pnlClass($t->current_pnl_percent) }}">{{ $pct($t->current_pnl_percent) }}</td><td>{{ $dt($t->entry_triggered_at) }}</td><td>{{ $age($t->entry_triggered_at) }}</td></tr>@empty<tr><td colspan="12" class="muted">No open trades.</td></tr>@endforelse</tbody></table></div></section>

    <section class="section-card"><h2>Pending Reserved Trade Plans</h2><div class="card table-wrap"><table class="table scanner-table"><thead><tr><th>Symbol</th><th>Setup type</th><th>Status</th><th>Score</th><th>Allocated</th><th>Portfolio status</th><th>Trigger</th><th>TP1</th><th>TP2</th><th>SL</th><th>Reserved at</th><th>Scan run</th></tr></thead><tbody>@forelse($pendingPlans as $p)<tr><td>{{ $p->coindcx_symbol }}</td><td>{{ $setup($p) }}</td><td><span class="{{ $badge($p->status) }}">{{ $p->status }}</span></td><td>{{ $num($p->score) }}</td><td>{{ $inr($p->allocated_capital) }}</td><td><span class="{{ $badge($p->portfolio_status) }}">{{ $p->portfolio_status }}</span></td><td>{{ $num($p->trigger_price) }}</td><td>{{ $num($p->tp1_price) }}</td><td>{{ $num($p->tp2_price) }}</td><td>{{ $num($p->sl_price) }}</td><td>{{ $dt($p->capital_reserved_at) }}</td><td>{{ $p->scan_run_id ? '#'.$p->scan_run_id : '-' }}</td></tr>@empty<tr><td colspan="12" class="muted">No pending plans.</td></tr>@endforelse</tbody></table></div></section>

    <section class="section-card"><h2>Recently Closed Portfolio Trades</h2><div class="card table-wrap"><table class="table scanner-table"><thead><tr><th>Symbol</th><th>Setup</th><th>Status</th><th>Close reason</th><th>Entry</th><th>Close</th><th>Allocated</th><th>Close value</th><th>Realized P&L</th><th>Net P&L</th><th>Final P&L %</th><th>Released at</th></tr></thead><tbody>@forelse($recentClosedTrades as $t)<tr><td>{{ $t->coindcx_symbol }}</td><td>{{ $setup($t) }}</td><td><span class="{{ $badge($t->status) }}">{{ $t->status }}</span></td><td>{{ $t->close_reason ?? $t->exit_reason ?? '-' }}</td><td>{{ $num($t->entry_price) }}</td><td>{{ $num($t->close_price) }}</td><td>{{ $inr($t->allocated_capital) }}</td><td>{{ $inr($t->close_value) }}</td><td class="{{ $pnlClass($t->realized_pnl_amount) }}">{{ $inr($t->realized_pnl_amount) }}</td><td class="{{ $pnlClass($t->net_pnl_amount) }}">{{ $inr($t->net_pnl_amount) }}</td><td class="{{ $pnlClass($t->final_pnl_percent) }}">{{ $pct($t->final_pnl_percent) }}</td><td>{{ $dt($t->capital_released_at) }}</td></tr>@empty<tr><td colspan="12" class="muted">No closed trades yet.</td></tr>@endforelse</tbody></table></div></section>

    <section class="section-card"><h2>Recent Portfolio Transactions</h2><div class="card table-wrap"><table class="table scanner-table"><thead><tr><th>Time</th><th>Type</th><th>Symbol/reference</th><th>Amount</th><th>Balance before</th><th>Balance after</th><th>Reserved before/after</th><th>Deployed before/after</th><th>Description</th></tr></thead><tbody>@forelse($recentTransactions as $tx)<tr><td>{{ $dt($tx->transaction_time) }}</td><td><span class="{{ $badge($tx->transaction_type) }}">{{ $tx->transaction_type }}</span></td><td>{{ $tx->simulatedTrade?->coindcx_symbol ?? $tx->tradePlan?->coindcx_symbol ?? ($tx->reference_type ? $tx->reference_type.' #'.$tx->reference_id : '-') }}</td><td class="{{ $pnlClass($tx->amount) }}">{{ $inr($tx->amount) }}</td><td>{{ $inr($tx->balance_before) }}</td><td>{{ $inr($tx->balance_after) }}</td><td>{{ $inr($tx->reserved_before) }} / {{ $inr($tx->reserved_after) }}</td><td>{{ $inr($tx->deployed_before) }} / {{ $inr($tx->deployed_after) }}</td><td>{{ $tx->description ?? '-' }}</td></tr>@empty<tr><td colspan="9" class="muted">No recent transactions.</td></tr>@endforelse</tbody></table></div></section>

    <section class="section-card"><h2>Allocation Summary ({{ $allocationSummary['month'] }})</h2><div class="grid">
        @foreach(['by_setup_type'=>'Allocation by setup type','by_score_bucket'=>'Allocation by score bucket','by_symbol'=>'Allocation by symbol','portfolio_status_counts'=>'Approved/rejected status counts','rejection_reasons'=>'Rejection reasons'] as $key => $title)
        <article class="card"><h3>{{ $title }}</h3><div class="table-wrap"><table class="table"><thead><tr><th>Group</th><th>Count</th><th>Allocated</th></tr></thead><tbody>@forelse($allocationSummary[$key] as $group => $row)<tr><td>{{ $group ?: '-' }}</td><td>{{ is_array($row) ? $row['count'] : $row }}</td><td>{{ is_array($row) ? $inr($row['allocated']) : '-' }}</td></tr>@empty<tr><td colspan="3" class="muted">No rows for this month.</td></tr>@endforelse</tbody></table></div></article>
        @endforeach
    </div></section>
@endif
@endsection
