@extends('layouts.app')

@section('content')
    <header class="page-header">
        <h1>Crypto Spot Intraday Gainer Scanner</h1>
        <p class="subtitle">CoinDCX Spot Research &amp; Simulation Tool</p>
    </header>

    <section class="grid metric-grid" aria-label="Watchlist and trade plan summary">
        <article class="card metric-card"><span>Active watchlist candidates</span><strong>{{ $dashboardStats['active_watchlist_count'] }}</strong></article>
        <article class="card metric-card"><span>Pending trade plans</span><strong>{{ $dashboardStats['pending_trade_plan_count'] }}</strong></article>
        <article class="card metric-card"><span>Latest top plan</span><strong>{{ $dashboardStats['latest_top_plan'] ? $dashboardStats['latest_top_plan']->coindcx_symbol . ' / ' . number_format((float) $dashboardStats['latest_top_plan']->score, 2) : '-' }}</strong></article>
        <article class="card metric-card"><span>Expiring soon</span><strong>{{ $dashboardStats['expiring_soon_count'] }}</strong></article>
        <article class="card metric-card"><span>Active simulated trades</span><strong>{{ $dashboardStats['active_simulated_trade_count'] }}</strong></article>
        <article class="card metric-card"><span>Open simulated trades</span><strong>{{ $dashboardStats['open_simulated_trade_count'] }}</strong></article>
        <article class="card metric-card"><span>Closed simulated trades</span><strong>{{ $dashboardStats['closed_simulated_trade_count'] }}</strong></article>
        <article class="card metric-card"><span>Trade events</span><strong>{{ $dashboardStats['trade_event_count'] }}</strong></article>
        <article class="card metric-card"><span>Open unrealized P&amp;L</span><strong>{{ number_format((float) $dashboardStats['open_unrealized_pnl'], 2) }}%</strong></article>
        <article class="card metric-card"><span>Latest trade event</span><strong>{{ $dashboardStats['latest_trade_event'] ? $dashboardStats['latest_trade_event']->coindcx_symbol . ' / ' . $dashboardStats['latest_trade_event']->event_type . ' / ' . optional($dashboardStats['latest_trade_event']->event_time)->format('Y-m-d H:i') : '-' }}</strong></article>
    </section>

    <section class="grid" aria-label="Future modules">
        @foreach ($modules as $module)
            @if ($module === 'Universe Sync')
                <a class="card card-link" href="{{ route('cryptospot.spot-symbols.index') }}">
                    <h2>{{ $module }}</h2>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Settings')
                <a class="card card-link" href="{{ route('cryptospot.settings.index') }}">
                    <h2>{{ $module }}</h2>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Candidate Watchlist')
                <a class="card card-link" href="{{ route('cryptospot.watchlist.index') }}">
                    <h2>Watchlist</h2>
                    <p>Review active/refreshed candidates from selected scan results.</p>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Daily Gainers')
                <a class="card card-link" href="{{ route('cryptospot.daily-gainers.index') }}">
                    <h2>Daily Gainers</h2>
                    <p>Review actual CoinDCX 24h top gainers captured by the one-shot ticker leaderboard.</p>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Simulated Trades')
                <a class="card card-link" href="{{ route('cryptospot.simulated-trades.index') }}">
                    <h2>Simulated Trades</h2>
                    <p>Review open/closed simulated trades, P&amp;L, TP/SL events, and raw payloads.</p>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Trade Plans')
                <a class="card card-link" href="{{ route('cryptospot.trade-plans.index') }}">
                    <h2>Trade Plans</h2>
                    <p>Review pending breakout/pullback plans with entry, TP, SL, and expiry.</p>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Scan Results')
                <a class="card card-link" href="{{ route('cryptospot.scans.latest') }}">
                    <h2>Market Scanner / Latest Scan Results</h2>
                    @if ($latestScanRun)
                        <p>#{{ $latestScanRun->id }} - {{ $latestScanRun->status }}</p>
                        <p>Top: {{ $latestScanRun->top_symbol ?: '-' }} / {{ $latestScanRun->top_score !== null ? number_format((float) $latestScanRun->top_score, 2) : '-' }}</p>
                        <p>Selected candidates: {{ $latestScanRun->scanResults()->where('selected_for_watchlist', true)->count() }}</p>
                    @else
                        <p>No scans yet.</p>
                    @endif
                    <div class="status">Open</div>
                </a>
            @else
                <article class="card">
                    <h2>{{ $module }}</h2>
                    <div class="status">Coming Soon</div>
                </article>
            @endif
        @endforeach
    </section>
@endsection
