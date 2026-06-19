

<?php $__env->startSection('content'); ?>
    <?php
        $statusClasses = [
            'scored' => 'badge-green',
            'prefilter_rejected' => 'badge-red',
            'failed' => 'badge-red',
            'candles_fetched' => 'badge-blue',
            'metrics_calculated' => 'badge-blue',
            'discovered' => 'badge-yellow',
            'prefilter_passed' => 'badge-yellow',
        ];
        $fmt = fn ($value, $decimals = 2) => $value === null ? '-' : number_format((float) $value, $decimals);
    ?>

    <header class="page-header page-header-actions">
        <div>
            <h1>Market Scanner / Latest Scan Results</h1>
            <p class="subtitle">Read-only review of scheduled/manual CoinDCX spot scan runs.</p>
        </div>
        <a class="secondary-button" href="<?php echo e(route('cryptospot.dashboard')); ?>">Dashboard</a>
    </header>

    <?php if(! $scanRun): ?>
        <section class="card">
            <h2>No scan runs found yet</h2>
            <p>No scan runs found yet. Run <code>python scripts/run_manual_scan_once.py</code> from the python folder to generate scan results.</p>
        </section>
    <?php else: ?>
        <section class="card section-card">
            <h2>Recent scan runs</h2>
            <div class="run-list">
                <?php $__currentLoopData = $recentScanRuns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $recentScanRun): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <a class="run-link <?php echo e($recentScanRun->is($scanRun) ? 'active' : ''); ?>" href="<?php echo e(route('cryptospot.scans.show', $recentScanRun)); ?>">
                        #<?php echo e($recentScanRun->id); ?> - <?php echo e($recentScanRun->scan_name ?: 'Unnamed scan'); ?> - <?php echo e($recentScanRun->status); ?> - <?php echo e(optional($recentScanRun->started_at)->format('Y-m-d H:i') ?: 'not started'); ?> - <?php echo e($recentScanRun->top_symbol ?: '-'); ?>/<?php echo e($fmt($recentScanRun->top_score)); ?>

                    </a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </section>

        <section class="grid metric-grid" aria-label="Scan run summary">
            <?php $__currentLoopData = [
                'Scan' => '#' . $scanRun->id . ' ' . ($scanRun->scan_name ?: 'Unnamed'),
                'Status / Type' => $scanRun->status . ' / ' . $scanRun->scan_type,
                'Started / Completed' => (optional($scanRun->started_at)->format('Y-m-d H:i:s') ?: '-') . ' / ' . (optional($scanRun->completed_at)->format('Y-m-d H:i:s') ?: '-'),
                'Duration Seconds' => $scanRun->duration_seconds ?? '-',
                'Quote Filter' => $scanRun->quote_filter ?: '-',
                'Total Active Symbols' => $scanRun->total_active_symbols,
                'Ticker Rows Fetched' => $scanRun->ticker_rows_fetched,
                'Total Results' => $summary['total_results'],
                'Prefilter Passed / Rejected' => $summary['prefilter_passed_count'] . ' / ' . $summary['prefilter_rejected_count'],
                'Candles Fetched' => $summary['candles_fetched_count'],
                'Metrics Calculated' => $summary['metrics_calculated_count'],
                'Scored / Score Passed' => $summary['scored_count'] . ' / ' . $summary['score_passed_count'],
                'Watchlist Selected' => $summary['selected_for_watchlist_count'],
                'Candidates Created' => $summary['candidate_created_count'],
                'Threshold / Fallback' => $summary['threshold_selected_count'] . ' / ' . $summary['fallback_selected_count'],
                'Top Symbol / Score' => ($scanRun->top_symbol ?: '-') . ' / ' . $fmt($scanRun->top_score),
            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <article class="card metric-card"><span><?php echo e($label); ?></span><strong><?php echo e($value); ?></strong></article>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </section>

        <section class="card section-card">
            <h2>Market Context</h2>
            <?php if($marketContext): ?>
                <div class="grid metric-grid">
                    <div><span>BTC Symbol</span><strong><?php echo e(data_get($marketContext, 'btc_symbol', '-')); ?></strong></div>
                    <div><span>BTC Price</span><strong><?php echo e($fmt(data_get($marketContext, 'btc_price'))); ?></strong></div>
                    <div><span>ETH Symbol</span><strong><?php echo e(data_get($marketContext, 'eth_symbol', '-')); ?></strong></div>
                    <div><span>ETH Price</span><strong><?php echo e($fmt(data_get($marketContext, 'eth_price'))); ?></strong></div>
                    <div><span>Market Condition</span><strong><?php echo e(data_get($marketContext, 'market_condition', '-')); ?></strong></div>
                    <div><span>Market Snapshot ID</span><strong><?php echo e(data_get($marketContext, 'market_snapshot_id', '-')); ?></strong></div>
                </div>
            <?php else: ?>
                <p>Not available.</p>
            <?php endif; ?>
        </section>

        <section class="card section-card">
            <h2>Scan Results</h2>
            <form class="filters" method="GET" action="<?php echo e(route('cryptospot.scans.show', $scanRun)); ?>">
                <input name="q" value="<?php echo e($filters['q']); ?>" placeholder="Search symbol/base/quote">
                <select name="status">
                    <?php $__currentLoopData = ['all', 'discovered', 'prefilter_rejected', 'prefilter_passed', 'candles_fetched', 'metrics_calculated', 'scored', 'failed']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($option); ?>" <?php if($filters['status'] === $option): echo 'selected'; endif; ?>><?php echo e(str_replace('_', ' ', ucfirst($option))); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <select name="selection">
                    <?php $__currentLoopData = ['all', 'selected', 'threshold', 'fallback', 'not_selected']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($option); ?>" <?php if($filters['selection'] === $option): echo 'selected'; endif; ?>><?php echo e(str_replace('_', ' ', ucfirst($option))); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <select name="candidate_created">
                    <?php $__currentLoopData = ['all', 'yes', 'no']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($option); ?>" <?php if($filters['candidate_created'] === $option): echo 'selected'; endif; ?>>Candidate <?php echo e(ucfirst($option)); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <select name="score_label">
                    <?php $__currentLoopData = ['all', 'strong', 'watchlist', 'weak']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($option); ?>" <?php if($filters['score_label'] === $option): echo 'selected'; endif; ?>><?php echo e(ucfirst($option)); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <select name="rejection_reason">
                    <option value="all">All rejection reasons</option>
                    <?php $__currentLoopData = $rejectionReasons; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reason): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($reason); ?>" <?php if($filters['rejection_reason'] === $reason): echo 'selected'; endif; ?>><?php echo e($reason); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <input name="min_score" value="<?php echo e($filters['min_score']); ?>" placeholder="Min score" type="number" step="0.0001">
                <select name="sort">
                    <?php $__currentLoopData = ['final_score_desc', 'final_score_asc', 'volume_desc', 'change_1h_desc', 'change_15m_desc', 'volume_spike_desc', 'spread_asc', 'selection_rank_asc', 'symbol_asc']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($option); ?>" <?php if($filters['sort'] === $option): echo 'selected'; endif; ?>><?php echo e(str_replace('_', ' ', ucfirst($option))); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <select name="per_page">
                    <option value="25" <?php if($filters['per_page'] === 25): echo 'selected'; endif; ?>>25/page</option>
                    <option value="50" <?php if($filters['per_page'] === 50): echo 'selected'; endif; ?>>50/page</option>
                </select>
                <button class="primary-button" type="submit">Apply</button>
                <a class="secondary-button" href="<?php echo e(route('cryptospot.scans.show', $scanRun)); ?>">Reset</a>
            </form>

            <div class="table-wrap">
                <table class="table scanner-table">
                    <thead><tr><th>Rank</th><th>Selected</th><th>Candidate</th><th>Symbol</th><th>Status</th><th>Score</th><th>15m %</th><th>1h %</th><th>4h %</th><th>24h %</th><th>Vol Spike 15m</th><th>Vol Spike 1h</th><th>Spread %</th><th>Depth USDT</th><th>Dist 24h High %</th><th>Close Strength</th><th>RS vs BTC</th><th>Risk Penalty</th><th>Rejection Reason</th><th>Evaluated</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php $__empty_1 = true; $__currentLoopData = $results; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $result): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <tr>
                                <td><?php echo e($result->selected_for_watchlist ? ($result->selection_rank ?: '-') : '-'); ?></td>
                                <td><span class="badge <?php echo e($result->selection_type === 'threshold' ? 'badge-green' : ($result->selection_type === 'fallback' ? 'badge-yellow' : 'badge-gray')); ?>"><?php echo e($result->selection_type ?: 'no'); ?></span></td>
                                <td><?php echo e($result->candidate_created ? 'Yes' : 'No'); ?><br><small>ID: <?php echo e($result->candidate_watchlist_id ?: '-'); ?></small></td>
                                <td><strong><?php echo e($result->coindcx_symbol); ?></strong><br><small><?php echo e($result->base_asset); ?>/<?php echo e($result->quote_asset); ?></small></td>
                                <td><span class="badge <?php echo e($statusClasses[$result->status] ?? 'badge-gray'); ?>"><?php echo e($result->status); ?></span></td>
                                <td><?php echo e($fmt($result->final_score)); ?><br><span class="badge <?php echo e($result->score_label === 'strong' ? 'badge-green' : ($result->score_label === 'watchlist' ? 'badge-blue' : 'badge-gray')); ?>"><?php echo e($result->score_label ?: '-'); ?></span></td>
                                <td><?php echo e($fmt($result->change_15m_percent)); ?></td><td><?php echo e($fmt($result->change_1h_percent)); ?></td><td><?php echo e($fmt($result->change_4h_percent)); ?></td><td><?php echo e($fmt($result->change_24h_percent)); ?></td>
                                <td><?php echo e($fmt($result->volume_spike_15m)); ?></td><td><?php echo e($fmt($result->volume_spike_1h)); ?></td><td><?php echo e($fmt($result->spread_percent, 4)); ?></td><td><?php echo e($fmt($result->orderbook_depth_usdt, 0)); ?></td>
                                <td><?php echo e($fmt($result->distance_from_24h_high_percent)); ?></td><td><?php echo e($fmt($result->candle_close_strength)); ?></td><td><?php echo e($fmt($result->relative_strength_vs_btc)); ?></td><td><?php echo e($fmt($result->risk_penalty)); ?></td>
                                <td><?php echo e($result->rejection_reason ?: '-'); ?></td><td><?php echo e(optional($result->evaluated_at)->format('Y-m-d H:i:s') ?: '-'); ?></td>
                                <td><?php echo $__env->make('scan-results.partials.score-breakdown', ['result' => $result], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr><td colspan="21">No scan results match the current filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php echo e($results->links()); ?>

        </section>
    <?php endif; ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Personal\projects\crypto-spot-intraday\resources\views/scan-results/index.blade.php ENDPATH**/ ?>