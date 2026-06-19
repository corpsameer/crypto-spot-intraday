<?php if($paginator->hasPages()): ?>
    <nav class="pagination" role="navigation" aria-label="Pagination Navigation">
        <div class="pagination__summary">
            Showing
            <?php if($paginator->firstItem()): ?>
                <span><?php echo e($paginator->firstItem()); ?></span>
                to
                <span><?php echo e($paginator->lastItem()); ?></span>
            <?php else: ?>
                <span><?php echo e($paginator->count()); ?></span>
            <?php endif; ?>
            of
            <span><?php echo e($paginator->total()); ?></span>
            results
        </div>

        <ul class="pagination__list">
            <?php if($paginator->onFirstPage()): ?>
                <li><span class="pagination__link pagination__link--disabled" aria-disabled="true">Previous</span></li>
            <?php else: ?>
                <li><a class="pagination__link" href="<?php echo e($paginator->previousPageUrl()); ?>" rel="prev">Previous</a></li>
            <?php endif; ?>

            <?php $__currentLoopData = $elements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $element): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php if(is_string($element)): ?>
                    <li><span class="pagination__link pagination__link--disabled" aria-disabled="true"><?php echo e($element); ?></span></li>
                <?php endif; ?>

                <?php if(is_array($element)): ?>
                    <?php $__currentLoopData = $element; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $page => $url): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php if($page == $paginator->currentPage()): ?>
                            <li><span class="pagination__link pagination__link--active" aria-current="page"><?php echo e($page); ?></span></li>
                        <?php else: ?>
                            <li><a class="pagination__link" href="<?php echo e($url); ?>"><?php echo e($page); ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                <?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

            <?php if($paginator->hasMorePages()): ?>
                <li><a class="pagination__link" href="<?php echo e($paginator->nextPageUrl()); ?>" rel="next">Next</a></li>
            <?php else: ?>
                <li><span class="pagination__link pagination__link--disabled" aria-disabled="true">Next</span></li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
<?php /**PATH C:\Personal\projects\crypto-spot-intraday\resources\views/vendor/pagination/cryptospot.blade.php ENDPATH**/ ?>