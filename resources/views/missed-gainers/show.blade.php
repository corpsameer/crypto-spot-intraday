@extends('layouts.app')

@php
    $badge = fn (?string $value, array $map = []) => $map[$value] ?? 'badge-gray';
    $missTypeBadge = fn (?string $value) => $badge($value, ['missed_completely'=>'badge-red','captured_not_selected'=>'badge-orange','selected_no_trade_plan'=>'badge-yellow','trade_plan_not_triggered'=>'badge-purple','captured_trade_created'=>'badge-green','captured_trade_underperformed'=>'badge-blue']);
    $severityBadge = fn (?string $value) => $badge($value, ['critical'=>'badge-red','high'=>'badge-red','medium'=>'badge-yellow','low'=>'badge-blue','none'=>'badge-green']);
    $yesNo = fn ($value) => $value ? 'badge-green' : 'badge-gray';
    $dash = fn ($value) => filled($value) ? $value : '-';
    $money = fn ($value) => $value !== null ? rtrim(rtrim(number_format((float) $value, 12, '.', ''), '0'), '.') : '-';
    $pct = fn ($value) => $value !== null ? number_format((float) $value, 2).'%' : '-';
@endphp

@section('content')
    <header class="page-header page-header-actions">
        <div>
            <h1>{{ $missedGainer->coindcx_symbol }}</h1>
            <p class="subtitle">Rank #{{ $dash($missedGainer->leaderboard_rank) }} · <span class="text-green">{{ $pct($missedGainer->actual_change_24h_percent) }}</span> actual 24h change</p>
            <div class="actions">
                <span class="badge {{ $missTypeBadge($missedGainer->miss_type) }}">{{ $dash($missedGainer->miss_type) }}</span>
                <span class="badge {{ $severityBadge($missedGainer->miss_severity) }}">{{ $dash($missedGainer->miss_severity) }}</span>
                <span class="badge badge-blue">{{ $dash($missedGainer->action_needed) }}</span>
            </div>
        </div>
        <div class="actions"><a class="secondary-button" href="{{ route('cryptospot.missed-gainers.index', ['date' => optional($missedGainer->analysis_date)->toDateString(), 'quote' => $missedGainer->quote_asset]) }}">Back to Missed Gainers</a></div>
    </header>

    <section class="grid metric-grid">
        <article class="card metric-card"><span>Actual last price</span><strong>{{ $money($missedGainer->actual_last_price) }}</strong></article>
        <article class="card metric-card"><span>Actual quote volume</span><strong>{{ $missedGainer->actual_quote_volume_24h !== null ? number_format((float) $missedGainer->actual_quote_volume_24h, 2) : '-' }}</strong></article>
        <article class="card metric-card"><span>Actual spread %</span><strong>{{ $pct($missedGainer->actual_spread_percent) }}</strong></article>
        <article class="card metric-card"><span>Leaderboard rank/date</span><strong>#{{ $dash($missedGainer->leaderboard_rank) }} / {{ optional($missedGainer->analysis_date)->toDateString() ?: '-' }}</strong></article>
    </section>

    <section class="grid">
        <article class="card">
            <h2>Scanner status</h2>
            <div class="details-grid">
                @foreach (['matched_in_scan'=>'Matched in scan','selected_for_watchlist'=>'Selected for watchlist','trade_plan_created'=>'Trade plan created','simulated_trade_created'=>'Simulated trade created','entry_triggered'=>'Entry triggered','tp1_hit'=>'TP1 hit','tp2_hit'=>'TP2 hit','sl_hit'=>'SL hit','trailing_hit'=>'Trailing hit','expired'=>'Expired'] as $field => $label)
                    <p><span class="muted small">{{ $label }}</span><br><span class="badge {{ $yesNo($missedGainer->{$field}) }}">{{ $missedGainer->{$field} ? 'Yes' : 'No' }}</span></p>
                @endforeach
            </div>
        </article>
        <article class="card">
            <h2>Best scan details</h2>
            <div class="details-grid">
                <p><span class="muted small">Best final score</span><br>{{ $missedGainer->best_final_score !== null ? number_format((float) $missedGainer->best_final_score, 2) : '-' }}</p>
                <p><span class="muted small">Score label</span><br>{{ $dash($missedGainer->best_score_label) }}</p>
                <p><span class="muted small">Best rank</span><br>{{ $dash($missedGainer->best_rank) }}</p>
                <p><span class="muted small">Prefilter passed</span><br><span class="badge {{ $yesNo($missedGainer->prefilter_passed) }}">{{ $missedGainer->prefilter_passed ? 'Yes' : 'No' }}</span></p>
                <p><span class="muted small">Score passed</span><br><span class="badge {{ $yesNo($missedGainer->score_passed) }}">{{ $missedGainer->score_passed ? 'Yes' : 'No' }}</span></p>
                <p><span class="muted small">Fallback selected</span><br><span class="badge {{ $yesNo($missedGainer->fallback_selected) }}">{{ $missedGainer->fallback_selected ? 'Yes' : 'No' }}</span></p>
                <p><span class="muted small">Rejection reason</span><br>{{ $dash($missedGainer->rejection_reason) }}</p>
                <p><span class="muted small">Setup type</span><br>{{ $dash($missedGainer->setup_type) }}</p>
            </div>
        </article>
    </section>

    <section class="grid" style="margin-top: 1rem;">
        <article class="card">
            <h2>Trade plan / simulated trade details</h2>
            <div class="details-grid">
                <p><span class="muted small">Entry strategy</span><br>{{ $dash($missedGainer->entry_strategy) }}</p>
                <p><span class="muted small">Planned entry price</span><br>{{ $money($missedGainer->planned_entry_price) }}</p>
                <p><span class="muted small">Trigger price</span><br>{{ $money($missedGainer->trigger_price) }}</p>
                <p><span class="muted small">TP1</span><br>{{ $money($missedGainer->tp1_price) }}</p>
                <p><span class="muted small">TP2</span><br>{{ $money($missedGainer->tp2_price) }}</p>
                <p><span class="muted small">SL</span><br>{{ $money($missedGainer->sl_price) }}</p>
                <p><span class="muted small">Latest trade status</span><br>{{ $dash($missedGainer->latest_trade_status) }}</p>
                <p><span class="muted small">Current P&L</span><br>{{ $pct($missedGainer->current_pnl_percent) }}</p>
                <p><span class="muted small">Max gain</span><br>{{ $pct($missedGainer->max_gain_percent) }}</p>
                <p><span class="muted small">Final P&L</span><br>{{ $pct($missedGainer->final_pnl_percent) }}</p>
            </div>
        </article>
        <article class="card">
            <h2>Source links</h2>
            <p>@if (Route::has('cryptospot.daily-gainers.index'))<a href="{{ route('cryptospot.daily-gainers.index', ['date' => optional($missedGainer->analysis_date)->toDateString(), 'quote_filter' => $missedGainer->quote_asset]) }}">Daily leaderboard</a>@else Daily leaderboard #{{ $dash($missedGainer->leaderboard_id) }} @endif</p>
            <p>@if ($missedGainer->best_scan_run_id && Route::has('cryptospot.scans.show'))<a href="{{ route('cryptospot.scans.show', $missedGainer->best_scan_run_id) }}">Scan run #{{ $missedGainer->best_scan_run_id }}</a>@else Scan run #{{ $dash($missedGainer->best_scan_run_id) }} @endif</p>
            <p>@if ($missedGainer->best_scan_run_id && $missedGainer->best_scan_result_id && Route::has('cryptospot.scans.show'))<a href="{{ route('cryptospot.scans.show', $missedGainer->best_scan_run_id) }}#scan-result-{{ $missedGainer->best_scan_result_id }}">Scan result #{{ $missedGainer->best_scan_result_id }}</a>@else Scan result #{{ $dash($missedGainer->best_scan_result_id) }} @endif</p>
            <p>@if ($missedGainer->best_candidate_watchlist_id && Route::has('cryptospot.watchlist.index'))<a href="{{ route('cryptospot.watchlist.index', ['q' => $missedGainer->coindcx_symbol]) }}">Candidate #{{ $missedGainer->best_candidate_watchlist_id }}</a>@else Candidate #{{ $dash($missedGainer->best_candidate_watchlist_id) }} @endif</p>
            <p>@if ($missedGainer->best_trade_plan_id && Route::has('cryptospot.trade-plans.index'))<a href="{{ route('cryptospot.trade-plans.index', ['q' => $missedGainer->coindcx_symbol]) }}">Trade plan #{{ $missedGainer->best_trade_plan_id }}</a>@else Trade plan #{{ $dash($missedGainer->best_trade_plan_id) }} @endif</p>
            <p>@if ($missedGainer->best_simulated_trade_id && Route::has('cryptospot.simulated-trades.show'))<a href="{{ route('cryptospot.simulated-trades.show', $missedGainer->best_simulated_trade_id) }}">Simulated trade #{{ $missedGainer->best_simulated_trade_id }}</a>@else Simulated trade #{{ $dash($missedGainer->best_simulated_trade_id) }} @endif</p>
        </article>
    </section>

    <section class="card section-card" style="margin-top: 1rem;">
        <details>
            <summary>Raw payload</summary>
            <pre>{{ json_encode($missedGainer->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '-' }}</pre>
        </details>
    </section>
@endsection
