@extends('layouts.app')

@php
    $num = fn ($v) => $v === null ? '-' : number_format((float) $v);
    $pct = fn ($v) => $v === null ? '-' : number_format((float) $v, 2) . '%';
    $dec = fn ($v) => $v === null ? '-' : number_format((float) $v, 2);
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d H:i') : '-';
    $pnlClass = fn ($v) => (float) $v > 0 ? 'text-green' : ((float) $v < 0 ? 'text-red' : 'muted');
    $bucketBadge = fn ($b) => match ($b) {'No score' => 'badge badge-gray','0-29' => 'badge badge-red','30-39' => 'badge badge-red','40-49' => 'badge badge-orange','50-59' => 'badge badge-blue','60-69' => 'badge badge-green','70-79' => 'badge badge-green','80+' => 'badge badge-purple', default => 'badge badge-gray'};
    $labelBadge = fn ($v) => match ((string) $v) {'strong' => 'badge badge-green','watchlist' => 'badge badge-blue','weak' => 'badge badge-gray','fallback' => 'badge badge-yellow', default => 'badge badge-gray'};
    $yn = fn ($v) => $v ? 'Yes' : 'No';
@endphp

@section('content')
<header class="page-header page-header-actions">
    <div>
        <h1>Score Bucket Analytics</h1>
        <p class="subtitle">Validate whether scanner scores and labels are correctly identifying actual gainers and better simulated trades.</p>
        <p class="muted small">Read-only analytics only. This page does not execute Python, call CoinDCX, change scoring, create trades, or add real trading.</p>
    </div>
    <div class="actions quick-actions">
        <a class="secondary-button" href="{{ route('cryptospot.dashboard') }}">Dashboard</a>
        <a class="secondary-button" href="{{ route('cryptospot.analytics.scanner-performance') }}">Scanner Performance</a>
        <a class="secondary-button" href="{{ route('cryptospot.analytics.trade-performance') }}">Trade Performance</a>
        <a class="secondary-button" href="{{ route('cryptospot.daily-gainers.index') }}">Daily Gainers</a>
        <a class="secondary-button" href="{{ route('cryptospot.missed-gainers.index') }}">Missed Gainers</a>
    </div>
</header>

<section class="card section-card">
    <form class="filters" method="GET" action="{{ route('cryptospot.analytics.score-buckets') }}">
        <label>From date<input type="date" name="from" value="{{ $filters['from'] }}"></label>
        <label>To date<input type="date" name="to" value="{{ $filters['to'] }}"></label>
        <label>Quote<input name="quote" value="{{ $filters['quote'] }}" placeholder="USDT or ALL"></label>
        <label>Min actual gain %<input type="number" step="0.01" name="min_change" value="{{ $filters['min_change'] }}"></label>
        <label>Score label<select name="score_label"><option value="all">All labels</option>@foreach(['strong','watchlist','weak','fallback'] as $l)<option value="{{ $l }}" @selected($filters['score_label']===$l)>{{ ucfirst($l) }}</option>@endforeach</select></label>
        <label>Selected<select name="selected"><option value="all">All</option><option value="yes" @selected($filters['selected']==='yes')>Yes</option><option value="no" @selected($filters['selected']==='no')>No</option></select></label>
        <label>Has trade plan<select name="has_trade_plan"><option value="all">All</option><option value="yes" @selected($filters['has_trade_plan']==='yes')>Yes</option><option value="no" @selected($filters['has_trade_plan']==='no')>No</option></select></label>
        <label>Has simulated trade<select name="has_simulated_trade"><option value="all">All</option><option value="yes" @selected($filters['has_simulated_trade']==='yes')>Yes</option><option value="no" @selected($filters['has_simulated_trade']==='no')>No</option></select></label>
        <div class="actions"><button class="primary-button" type="submit">Apply</button><a class="secondary-button" href="{{ route('cryptospot.analytics.score-buckets') }}">Reset</a></div>
    </form>
</section>

<section class="section-card"><h2>Summary Cards</h2><div class="grid metric-grid">
@foreach ([
'Total scored scan results'=>[$summaryStats['scored'],false], 'No-score scan results'=>[$summaryStats['no_score'],false], 'Selected candidates'=>[$summaryStats['selected'],false], 'Trade plans created'=>[$summaryStats['trade_plans'],false], 'Simulated trades created'=>[$summaryStats['sim_trades'],false], 'Actual '.$summaryStats['min_change'].'%+ gainers analyzed'=>[$summaryStats['actual_gainers'],false], $summaryStats['min_change'].'%+ gainers with score'=>[$summaryStats['gainers_with_score'],false], $summaryStats['min_change'].'%+ gainers without score'=>[$summaryStats['gainers_without_score'],false], 'Best-performing score bucket'=>[($summaryStats['best_bucket'] ?: '-') . ' / ' . $pct($summaryStats['best_bucket_avg']),false], 'Most captured-not-selected bucket'=>[($summaryStats['most_captured_not_selected_bucket'] ?: '-') . ' / ' . $num($summaryStats['most_captured_not_selected']),false], 'Average score of selected candidates'=>[$dec($summaryStats['avg_selected_score']),false], 'Average score of actual gainers'=>[$dec($summaryStats['avg_gainer_score']),false]
] as $label => [$value,$unused])<article class="card metric-card"><span>{{ $label }}</span><strong>{{ is_numeric($value) ? $num($value) : $value }}</strong></article>@endforeach
</div></section>

<section class="card section-card"><h2>Scan Result Score Buckets</h2>@if($summaryStats['scored'] + $summaryStats['no_score'] === 0)<p class="muted">No scan results found for this date range.</p>@else<div class="table-wrap"><table class="table scanner-table"><thead><tr><th>Score bucket</th><th>Scan result rows</th><th>Distinct symbols</th><th>Avg score</th><th>Selected count</th><th>Selected %</th><th>Watchlist rows</th><th>Trade plans</th><th>Avg 15m momentum</th><th>Avg 1h momentum</th><th>Avg volume spike</th><th>Avg spread</th><th>Avg liquidity</th></tr></thead><tbody>@foreach($scanScoreBuckets as $r)<tr><td><span class="{{ $bucketBadge($r['bucket']) }}">{{ $r['bucket'] }}</span></td><td>{{ $num($r['rows']) }}</td><td>{{ $num($r['symbols']) }}</td><td>{{ $dec($r['avg_score']) }}</td><td>{{ $num($r['selected']) }}</td><td>{{ $pct($r['selected_pct']) }}</td><td>{{ $num($r['watchlist']) }}</td><td>{{ $num($r['trade_plans']) }}</td><td>{{ $pct($r['avg_15m']) }}</td><td>{{ $pct($r['avg_1h']) }}</td><td>{{ $dec($r['avg_volume_spike']) }}</td><td>{{ $pct($r['avg_spread']) }}</td><td>{{ $dec($r['avg_liquidity']) }}</td></tr>@endforeach</tbody></table></div>@endif</section>

<section class="card section-card"><h2>Daily Gainer Score Buckets</h2>@if($summaryStats['actual_gainers'] === 0)<p class="muted">No missed gainer analysis found. Run daily gainer leaderboard and missed gainer analyzer.</p><pre>cd python
python scripts/run_daily_gainer_leaderboard_once.py --quote USDT --limit 100
python scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100</pre>@else<div class="table-wrap"><table class="table scanner-table"><thead><tr><th>Score bucket</th><th>Actual gainers</th><th>Avg actual 24h change</th><th>Max actual 24h change</th><th>Matched in scan</th><th>Selected</th><th>Selected %</th><th>Trade plans</th><th>Sim trades</th><th>Captured not selected</th><th>Missed completely</th><th>Avg best score</th></tr></thead><tbody>@foreach($dailyGainerScoreBuckets as $r)<tr><td><span class="{{ $bucketBadge($r['bucket']) }}">{{ $r['bucket'] }}</span></td><td>{{ $num($r['count']) }}</td><td>{{ $pct($r['avg_change']) }}</td><td>{{ $pct($r['max_change']) }}</td><td>{{ $num($r['matched']) }}</td><td>{{ $num($r['selected']) }}</td><td>{{ $pct($r['selected_pct']) }}</td><td>{{ $num($r['plans']) }}</td><td>{{ $num($r['sim_trades']) }}</td><td>{{ $num($r['captured_not_selected']) }}</td><td>{{ $num($r['missed_completely']) }}</td><td>{{ $dec($r['avg_score']) }}</td></tr>@endforeach</tbody></table></div>@endif</section>

<section class="card section-card"><h2>Simulated Trade Score Buckets</h2>@if($summaryStats['sim_trades'] === 0)<p class="muted">No simulated trades found yet.</p>@else<div class="table-wrap"><table class="table scanner-table"><thead><tr><th>Score bucket</th><th>Trades</th><th>Open</th><th>Closed</th><th>Win rate</th><th>TP1 hit</th><th>TP2 hit</th><th>SL hit</th><th>Trailing close</th><th>Expired</th><th>Avg current P&L</th><th>Avg final P&L</th><th>Avg max gain</th><th>Avg max drawdown</th><th>Best final/max gain</th><th>Worst final/max drawdown</th></tr></thead><tbody>@foreach($tradeScoreBuckets as $r)<tr><td><span class="{{ $bucketBadge($r['bucket']) }}">{{ $r['bucket'] }}</span></td><td>{{ $num($r['trades']) }}</td><td>{{ $num($r['open']) }}</td><td>{{ $num($r['closed']) }}</td><td>{{ $pct($r['win_rate']) }}</td><td>{{ $num($r['tp1']) }}</td><td>{{ $num($r['tp2']) }}</td><td>{{ $num($r['sl']) }}</td><td>{{ $num($r['trailing_closed']) }}</td><td>{{ $num($r['expired']) }}</td><td class="{{ $pnlClass($r['avg_current']) }}">{{ $pct($r['avg_current']) }}</td><td class="{{ $pnlClass($r['avg_final']) }}">{{ $pct($r['avg_final']) }}</td><td>{{ $pct($r['avg_max_gain']) }}</td><td>{{ $pct($r['avg_max_drawdown']) }}</td><td>{{ $pct($r['best_final']) }}</td><td>{{ $pct($r['worst_final']) }}</td></tr>@endforeach</tbody></table></div>@endif</section>

<section class="card section-card"><h2>Score Label Comparison</h2><div class="table-wrap"><table class="table"><thead><tr><th>Score label</th><th>Scan results</th><th>Selected count</th><th>Actual gainers</th><th>Simulated trades</th><th>Win rate</th><th>Avg final P&L</th><th>Avg actual gainer change</th><th>Captured not selected</th></tr></thead><tbody>@foreach($scoreLabelStats as $r)<tr><td><span class="{{ $labelBadge($r['label']) }}">{{ $r['label'] }}</span></td><td>{{ $num($r['scan_results']) }}</td><td>{{ $num($r['selected']) }}</td><td>{{ $num($r['actual_gainers']) }}</td><td>{{ $num($r['trades']) }}</td><td>{{ $pct($r['win_rate']) }}</td><td class="{{ $pnlClass($r['avg_final']) }}">{{ $pct($r['avg_final']) }}</td><td>{{ $pct($r['avg_change']) }}</td><td>{{ $num($r['captured_not_selected']) }}</td></tr>@endforeach</tbody></table></div></section>

<section class="card section-card"><h2>High-Gainer Low-Score Review</h2><div class="table-wrap"><table class="table scanner-table"><thead><tr><th>Date</th><th>Rank</th><th>Symbol</th><th>Actual change</th><th>Best score</th><th>Score label</th><th>Matched</th><th>Selected</th><th>Trade plan</th><th>Sim trade</th><th>Miss type</th><th>Miss reason</th><th>Action needed</th></tr></thead><tbody>@forelse($lowScoreHighGainers as $r)<tr><td>{{ $r->analysis_date }}</td><td>{{ $r->leaderboard_rank ? '#'.$r->leaderboard_rank : '-' }}</td><td>{{ $r->coindcx_symbol }}</td><td>{{ $pct($r->actual_change_24h_percent) }}</td><td>{{ $dec($r->best_final_score) }}</td><td><span class="{{ $labelBadge($r->best_score_label) }}">{{ $r->best_score_label ?: '-' }}</span></td><td>{{ $yn($r->matched_in_scan) }}</td><td>{{ $yn($r->selected_for_watchlist) }}</td><td>{{ $yn($r->trade_plan_created) }}</td><td>{{ $yn($r->simulated_trade_created) }}</td><td>{{ $r->miss_type ?: '-' }}</td><td>{{ $r->miss_reason ?: '-' }}</td><td>{{ $r->action_needed ?: '-' }}</td></tr>@empty<tr><td colspan="13" class="muted">No high-gainer low-score rows found.</td></tr>@endforelse</tbody></table></div></section>

<section class="card section-card"><h2>High-Score Non-Performers</h2><div class="table-wrap"><table class="table scanner-table"><thead><tr><th>Symbol</th><th>Score</th><th>Score label</th><th>Strategy</th><th>Status</th><th>Current P&L</th><th>Final P&L</th><th>Max gain</th><th>Max drawdown</th><th>Entry triggered at</th><th>Closed at</th></tr></thead><tbody>@forelse($highScoreNonPerformers as $t)<tr><td>{{ $t->coindcx_symbol }}</td><td>{{ $dec($t->score) }}</td><td><span class="{{ $labelBadge($t->score_label) }}">{{ $t->score_label ?: '-' }}</span></td><td>{{ $t->entry_strategy ?: '-' }}</td><td>{{ $t->status }}</td><td class="{{ $pnlClass($t->current_pnl_percent) }}">{{ $pct($t->current_pnl_percent) }}</td><td class="{{ $pnlClass($t->final_pnl_percent) }}">{{ $pct($t->final_pnl_percent) }}</td><td>{{ $pct($t->max_gain_percent) }}</td><td>{{ $pct($t->max_drawdown_percent) }}</td><td>{{ $dt($t->entry_triggered_at) }}</td><td>{{ $dt($t->closed_at) }}</td></tr>@empty<tr><td colspan="11" class="muted">No high-score non-performer rows found.</td></tr>@endforelse</tbody></table></div></section>

<section class="card section-card"><h2>Interpretation Notes</h2><ul><li>If many {{ $summaryStats['min_change'] }}%+ gainers are in No score bucket, check prefilter/metrics availability.</li><li>If many {{ $summaryStats['min_change'] }}%+ gainers are scored 40-49 but not selected, review threshold/fallback rules later.</li><li>If high-score trades underperform, review scoring weights or entry trigger logic later.</li><li>This page is analytics only; no scoring changes are made here.</li></ul></section>
@endsection
