@extends('layouts.app')

@section('content')
    @php
        $latestScan = $dashboard['latestScan'];
        $scanStats = $dashboard['scanStats'];
        $market = $dashboard['marketSnapshot'];
        $plans = $dashboard['tradePlanStats'];
        $trades = $dashboard['simulatedTradeStats'];
        $daily = $dashboard['dailyGainerStats'];
        $missed = $dashboard['missedGainerStats'];
        $fmtNum = fn ($value) => $value === null ? '-' : number_format((float) $value);
        $fmtPct = fn ($value) => $value === null ? '-' : number_format((float) $value, 2) . '%';
        $fmtPrice = fn ($value) => $value === null ? '-' : rtrim(rtrim(number_format((float) $value, 12), '0'), '.');
        $fmtDate = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '-';
        $badge = function ($value) {
            $value = strtolower((string) $value);
            return match (true) {
                in_array($value, ['ok', 'active', 'open', 'completed', 'converted_to_trade', 'closed_tp1', 'closed_tp2'], true) => 'badge badge-green',
                in_array($value, ['warning', 'high', 'medium', 'pending', 'watching', 'triggered', 'tp1_hit', 'tp2_hit', 'trailing_active'], true) => 'badge badge-yellow',
                in_array($value, ['error', 'critical', 'failed', 'closed_sl', 'closed_trailing', 'expired', 'cancelled'], true) => 'badge badge-red',
                default => 'badge badge-gray',
            };
        };
    @endphp

    <header class="page-header page-header-actions">
        <div>
            <h1>Crypto Spot Intraday Dashboard</h1>
            <p class="subtitle">Scan-based CoinDCX spot gainer scanner, watchlist, simulated trades, and missed-gainer review.</p>
            <p class="muted small">Server time: {{ now()->format('Y-m-d H:i:s T') }} · Latest scan: {{ $fmtDate($latestScan?->started_at) }}</p>
        </div>
        <div class="actions quick-actions">
            <a class="secondary-button" href="{{ route('cryptospot.scans.latest') }}">Latest Scanner</a>
            <a class="secondary-button" href="{{ route('cryptospot.trade-plans.index') }}">Watchlist / Trade Plans</a>
            <a class="secondary-button" href="{{ route('cryptospot.simulated-trades.index') }}">Simulated Trades</a>
            <a class="secondary-button" href="{{ route('cryptospot.daily-gainers.index') }}">Daily Gainers</a>
            <a class="secondary-button" href="{{ route('cryptospot.missed-gainers.index') }}">Missed Gainers</a>
            <a class="secondary-button" href="{{ route('cryptospot.analytics.scanner-performance') }}">Scanner Analytics</a>
            <a class="secondary-button" href="{{ route('cryptospot.analytics.trade-performance') }}">Trade Analytics</a>
            <a class="secondary-button" href="{{ route('cryptospot.analytics.score-buckets') }}">Score Buckets</a>
            @if ($dashboard['latestHealth']->isNotEmpty())<a class="secondary-button" href="#system-health">System Health</a>@endif
        </div>
    </header>

    <section class="section-card" aria-label="Latest scan snapshot">
        <h2>Latest Scan Snapshot</h2>
        <div class="grid metric-grid">
            <article class="card metric-card"><span>Latest scan run ID</span><strong>{{ $latestScan ? '#'.$latestScan->id : '-' }}</strong></article>
            <article class="card metric-card"><span>Status</span><strong><span class="{{ $badge($latestScan?->status) }}">{{ $latestScan?->status ?? '-' }}</span></strong></article>
            <article class="card metric-card"><span>Started at</span><strong>{{ $fmtDate($latestScan?->started_at) }}</strong></article>
            <article class="card metric-card"><span>Duration</span><strong>{{ $latestScan?->duration_seconds !== null ? $fmtNum($latestScan->duration_seconds) . 's' : '-' }}</strong></article>
            <article class="card metric-card"><span>Symbols scanned</span><strong>{{ $fmtNum($scanStats['symbols_scanned']) }}</strong></article>
            <article class="card metric-card"><span>Ticker rows fetched</span><strong>{{ $fmtNum($scanStats['ticker_rows_fetched']) }}</strong></article>
            <article class="card metric-card"><span>Prefilter passed</span><strong>{{ $fmtNum($scanStats['prefilter_passed']) }}</strong></article>
            <article class="card metric-card"><span>Scored</span><strong>{{ $fmtNum($scanStats['scored']) }}</strong></article>
            <article class="card metric-card"><span>Selected</span><strong>{{ $fmtNum($scanStats['selected']) }}</strong></article>
            <article class="card metric-card"><span>Trade plans</span><strong>{{ $fmtNum($scanStats['trade_plans']) }}</strong></article>
            <article class="card metric-card"><span>Errors</span><strong>{{ $fmtNum($scanStats['errors']) }}</strong></article>
        </div>
    </section>

    <section class="section-card" aria-label="Market context">
        <h2>Market Context</h2>
        <div class="grid metric-grid">
            <article class="card metric-card"><span>BTC price</span><strong>{{ $fmtPrice($market?->btc_price) }}</strong></article>
            <article class="card metric-card"><span>BTC 24h</span><strong>{{ $fmtPct($market?->btc_change_24h_percent) }}</strong></article>
            <article class="card metric-card"><span>ETH price</span><strong>{{ $fmtPrice($market?->eth_price) }}</strong></article>
            <article class="card metric-card"><span>ETH 24h</span><strong>{{ $fmtPct($market?->eth_change_24h_percent) }}</strong></article>
            <article class="card metric-card"><span>Market condition</span><strong>{{ $market?->market_condition ?? '-' }}</strong></article>
            <article class="card metric-card"><span>Snapshot time</span><strong>{{ $fmtDate($market?->snapshot_time) }}</strong></article>
        </div>
    </section>

    <section class="section-card" aria-label="Watchlist and trade plans">
        <h2>Watchlist and Trade Plans</h2>
        <div class="grid metric-grid">
            @foreach ([
                'Active watchlist candidates' => 'active_watchlist', 'Pending/watching plans' => 'open_plans', 'Triggered plans' => 'triggered', 'Converted plans' => 'converted', 'Expired plans' => 'expired', 'Breakout plans' => 'breakout', 'Pullback plans' => 'pullback', 'Plans created in 24h' => 'last_24h'
            ] as $label => $key)
                <article class="card metric-card"><span>{{ $label }}</span><strong>{{ $fmtNum($plans[$key]) }}</strong></article>
            @endforeach
        </div>
    </section>

    <section class="section-card" aria-label="Simulated trades summary">
        <h2>Simulated Trades Summary</h2>
        <div class="grid metric-grid">
            <article class="card metric-card"><span>Open simulated trades</span><strong>{{ $fmtNum($trades['open_count']) }}</strong></article>
            <article class="card metric-card"><span>Closed simulated trades</span><strong>{{ $fmtNum($trades['closed_count']) }}</strong></article>
            <article class="card metric-card"><span>Open unrealized P&amp;L sum</span><strong>{{ $fmtPct($trades['open_unrealized_sum']) }}</strong></article>
            <article class="card metric-card"><span>Open unrealized P&amp;L avg</span><strong>{{ $fmtPct($trades['open_unrealized_avg']) }}</strong></article>
            <article class="card metric-card"><span>Closed realized P&amp;L sum</span><strong>{{ $fmtPct($trades['closed_realized_sum']) }}</strong></article>
            <article class="card metric-card"><span>Closed realized P&amp;L avg</span><strong>{{ $fmtPct($trades['closed_realized_avg']) }}</strong></article>
            <article class="card metric-card"><span>Best open max gain</span><strong>{{ $fmtPct($trades['best_open_max_gain']) }}</strong></article>
            <article class="card metric-card"><span>Worst open drawdown</span><strong>{{ $fmtPct($trades['worst_open_drawdown']) }}</strong></article>
            <article class="card metric-card"><span>TP1 hit count</span><strong>{{ $fmtNum($trades['tp1_hit_count']) }}</strong></article>
            <article class="card metric-card"><span>TP2 hit count</span><strong>{{ $fmtNum($trades['tp2_hit_count']) }}</strong></article>
            <article class="card metric-card"><span>SL closed count</span><strong>{{ $fmtNum($trades['sl_closed_count']) }}</strong></article>
            <article class="card metric-card"><span>Trailing closed count</span><strong>{{ $fmtNum($trades['trailing_closed_count']) }}</strong></article>
            <article class="card metric-card"><span>Expired count</span><strong>{{ $fmtNum($trades['expired_count']) }}</strong></article>
        </div>
    </section>

    <section class="section-card" aria-label="Daily gainer capture summary">
        <h2>Daily Gainer Capture Summary</h2>
        <div class="grid metric-grid">
            <article class="card metric-card"><span>Leaderboard date</span><strong>{{ $daily['leaderboard_date'] ?? '-' }}</strong></article>
            <article class="card metric-card"><span>Top gainer</span><strong>{{ $daily['top_gainer']?->coindcx_symbol ?? '-' }}</strong></article>
            <article class="card metric-card"><span>Top gainer change</span><strong>{{ $fmtPct($daily['top_gainer']?->change_24h_percent) }}</strong></article>
            <article class="card metric-card"><span>Top gainers count</span><strong>{{ $fmtNum($daily['total_count']) }}</strong></article>
            <article class="card metric-card"><span>10%+ gainers count</span><strong>{{ $fmtNum($daily['ten_percent_count']) }}</strong></article>
            <article class="card metric-card"><span>Matched in scan</span><strong>{{ $fmtNum($daily['matched_count']) }}</strong></article>
            <article class="card metric-card"><span>Selected for watchlist</span><strong>{{ $fmtNum($daily['selected_count']) }}</strong></article>
            <article class="card metric-card"><span>Trade plan created</span><strong>{{ $fmtNum($daily['trade_plan_count']) }}</strong></article>
            <article class="card metric-card"><span>Simulated trade created</span><strong>{{ $fmtNum($daily['simulated_trade_count']) }}</strong></article>
            <article class="card metric-card"><span>Capture rate</span><strong>{{ $fmtPct($daily['capture_rate']) }}</strong></article>
            <article class="card metric-card"><span>Selection rate</span><strong>{{ $fmtPct($daily['selection_rate']) }}</strong></article>
        </div>
    </section>

    <section class="section-card" aria-label="Missed gainer summary">
        <h2>Missed Gainer Summary</h2>
        <div class="grid metric-grid">
            @foreach ([
                'Analysis date' => 'analysis_date', 'Total analyzed' => 'total_analyzed', 'Missed completely' => 'missed_completely', 'Captured not selected' => 'captured_not_selected', 'Selected no trade plan' => 'selected_no_trade_plan', 'Trade plan not triggered' => 'trade_plan_not_triggered', 'Captured trade created' => 'captured_trade_created', 'Critical/high misses' => 'critical_high'
            ] as $label => $key)
                <article class="card metric-card"><span>{{ $label }}</span><strong>{{ $key === 'analysis_date' ? ($missed[$key] ?? '-') : $fmtNum($missed[$key]) }}</strong></article>
            @endforeach
        </div>
        <div class="card table-wrap">
            <h3>Top Review-Needed Gainers</h3>
            @if ($dashboard['latestMissedGainers']->isEmpty())
                <p class="muted">No missed gainer analysis rows available.</p>
            @else
                <table class="table">
                    <thead><tr><th>Rank</th><th>Symbol</th><th>Actual change</th><th>Best score</th><th>Score label</th><th>Miss type</th><th>Miss reason</th><th>Severity</th><th>Action needed</th></tr></thead>
                    <tbody>
                        @foreach ($dashboard['latestMissedGainers'] as $row)
                            <tr>
                                <td>{{ $row->leaderboard_rank ?? '-' }}</td><td>{{ $row->coindcx_symbol }}</td><td>{{ $fmtPct($row->actual_change_24h_percent) }}</td><td>{{ $row->best_final_score !== null ? number_format((float) $row->best_final_score, 2) : '-' }}</td><td>{{ $row->best_score_label ?? '-' }}</td><td>{{ $row->miss_type ?? '-' }}</td><td>{{ $row->miss_reason ?? '-' }}</td><td><span class="{{ $badge($row->miss_severity) }}">{{ $row->miss_severity ?? '-' }}</span></td><td>{{ $row->action_needed ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    <section class="section-card" aria-label="Latest open trades">
        <h2>Latest Open Trades</h2>
        <div class="card table-wrap">
            @if ($dashboard['latestOpenTrades']->isEmpty())<p class="muted">No open simulated trades.</p>@else
                <table class="table">
                    <thead><tr><th>Symbol</th><th>Status</th><th>Strategy</th><th>Entry</th><th>Latest</th><th>Current P&amp;L</th><th>Max gain</th><th>Max drawdown</th><th>TP1</th><th>TP2</th><th>SL</th><th>Expires at</th></tr></thead>
                    <tbody>@foreach ($dashboard['latestOpenTrades'] as $trade)<tr><td><a href="{{ route('cryptospot.simulated-trades.show', $trade) }}">{{ $trade->coindcx_symbol }}</a></td><td><span class="{{ $badge($trade->status) }}">{{ $trade->status }}</span></td><td>{{ $trade->entry_strategy ?? '-' }}</td><td>{{ $fmtPrice($trade->entry_price ?? $trade->planned_entry_price) }}</td><td>{{ $fmtPrice($trade->latest_price) }}</td><td>{{ $fmtPct($trade->current_pnl_percent) }}</td><td>{{ $fmtPct($trade->max_gain_percent) }}</td><td>{{ $fmtPct($trade->max_drawdown_percent) }}</td><td>{{ $fmtPrice($trade->tp1_price) }}</td><td>{{ $fmtPrice($trade->tp2_price) }}</td><td>{{ $fmtPrice($trade->sl_price) }}</td><td>{{ $fmtDate($trade->expires_at) }}</td></tr>@endforeach</tbody>
                </table>
            @endif
        </div>
    </section>

    <section class="section-card" aria-label="Latest trade events">
        <h2>Latest Trade Events</h2>
        <div class="card table-wrap">
            @if ($dashboard['latestTradeEvents']->isEmpty())<p class="muted">No trade events recorded.</p>@else
                <table class="table">
                    <thead><tr><th>Time</th><th>Symbol</th><th>Event type</th><th>Event price</th><th>P&amp;L</th><th>New status</th><th>Message</th></tr></thead>
                    <tbody>@foreach ($dashboard['latestTradeEvents'] as $event)<tr><td>{{ $fmtDate($event->event_time) }}</td><td>@if ($event->simulated_trade_id)<a href="{{ route('cryptospot.simulated-trades.show', $event->simulated_trade_id) }}">{{ $event->coindcx_symbol }}</a>@else{{ $event->coindcx_symbol }}@endif</td><td>{{ $event->event_type }}</td><td>{{ $fmtPrice($event->event_price) }}</td><td>{{ $fmtPct($event->pnl_percent) }}</td><td><span class="{{ $badge($event->new_status) }}">{{ $event->new_status ?? '-' }}</span></td><td>{{ $event->message ?? '-' }}</td></tr>@endforeach</tbody>
                </table>
            @endif
        </div>
    </section>

    <section id="system-health" class="section-card" aria-label="System health">
        <h2>System Health</h2>
        <div class="card table-wrap">
            @if ($dashboard['latestHealth']->isEmpty())<p class="muted">No system health rows available.</p>@else
                <table class="table"><thead><tr><th>Service</th><th>Status</th><th>Message</th><th>Checked at</th></tr></thead><tbody>@foreach ($dashboard['latestHealth'] as $health)<tr><td>{{ $health->service_name }}</td><td><span class="{{ $badge($health->status) }}">{{ $health->status }}</span></td><td>{{ $health->message ?? '-' }}</td><td>{{ $fmtDate($health->checked_at) }}</td></tr>@endforeach</tbody></table>
            @endif
        </div>
    </section>
@endsection
