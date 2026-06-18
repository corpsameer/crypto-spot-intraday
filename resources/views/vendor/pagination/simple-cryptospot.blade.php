@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination Navigation">
        <ul class="pagination__list">
            @if ($paginator->onFirstPage())
                <li><span class="pagination__link pagination__link--disabled" aria-disabled="true">Previous</span></li>
            @else
                <li><a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a></li>
            @endif

            @if ($paginator->hasMorePages())
                <li><a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a></li>
            @else
                <li><span class="pagination__link pagination__link--disabled" aria-disabled="true">Next</span></li>
            @endif
        </ul>
    </nav>
@endif
