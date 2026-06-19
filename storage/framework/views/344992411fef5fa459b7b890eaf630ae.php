

<?php $__env->startSection('content'); ?>
    <header class="page-header">
        <h1>CoinDCX Spot Symbols</h1>
        <p class="subtitle">View the local spot universe and manually refresh it from the CoinDCX public market details API.</p>
    </header>

    <section class="grid" aria-label="Spot symbol summary">
        <article class="card">
            <h2>Total Symbols</h2>
            <div class="status"><?php echo e($symbolCount); ?></div>
        </article>
        <article class="card">
            <h2>Active Symbols</h2>
            <div class="status"><?php echo e($activeCount); ?></div>
        </article>
        <article class="card">
            <h2>Latest Sync</h2>
            <?php if($latestSyncLog): ?>
                <p><?php echo e($latestSyncLog->checked_at->format('Y-m-d H:i:s')); ?> UTC</p>
                <span class="badge <?php echo e($latestSyncLog->status === 'ok' ? 'badge-active' : 'badge-inactive'); ?>"><?php echo e(strtoupper($latestSyncLog->status)); ?></span>
                <p class="subtitle"><?php echo e($latestSyncLog->message); ?></p>
            <?php else: ?>
                <p class="subtitle">No sync has been run yet.</p>
            <?php endif; ?>
        </article>
    </section>

    <section class="card" style="margin-top: 1rem;">
        <div class="actions">
            <form method="POST" action="<?php echo e(route('cryptospot.spot-symbols.sync')); ?>">
                <?php echo csrf_field(); ?>
                <button class="primary-button" type="submit">Sync CoinDCX Spot Universe</button>
            </form>
            <span class="subtitle">This only updates symbols. It does not poll prices, candles, or trades.</span>
        </div>
    </section>

    <section class="card" style="margin-top: 1rem; overflow-x: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>CoinDCX Symbol</th>
                    <th>API Pair</th>
                    <th>Base</th>
                    <th>Quote</th>
                    <th>Status</th>
                    <th>Min Quantity</th>
                    <th>Last Synced</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $symbols; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $symbol): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr>
                        <td><strong><?php echo e($symbol->coindcx_symbol); ?></strong></td>
                        <td><?php echo e($symbol->api_pair ?? '—'); ?></td>
                        <td><?php echo e($symbol->base_asset ?? '—'); ?></td>
                        <td><?php echo e($symbol->quote_asset ?? '—'); ?></td>
                        <td><span class="badge <?php echo e($symbol->is_active ? 'badge-active' : 'badge-inactive'); ?>"><?php echo e($symbol->status); ?></span></td>
                        <td><?php echo e($symbol->min_quantity ?? '—'); ?></td>
                        <td><?php echo e($symbol->last_synced_at?->format('Y-m-d H:i:s') ?? '—'); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr>
                        <td colspan="7">No symbols synced yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php echo e($symbols->links()); ?>

    </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Personal\projects\crypto-spot-intraday\resources\views/spot-symbols/index.blade.php ENDPATH**/ ?>