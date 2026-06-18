@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination Navigation">
        <div class="pagination__summary">
            Showing
            @if ($paginator->firstItem())
                <span>{{ $paginator->firstItem() }}</span>
                to
                <span>{{ $paginator->lastItem() }}</span>
            @else
                <span>{{ $paginator->count() }}</span>
            @endif
            of
            <span>{{ $paginator->total() }}</span>
            results
        </div>

        <ul class="pagination__list">
            @if ($paginator->onFirstPage())
                <li><span class="pagination__link pagination__link--disabled" aria-disabled="true">Previous</span></li>
            @else
                <li><a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pagination__link pagination__link--disabled" aria-disabled="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span class="pagination__link pagination__link--active" aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a class="pagination__link" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a></li>
            @else
                <li><span class="pagination__link pagination__link--disabled" aria-disabled="true">Next</span></li>
            @endif
        </ul>
    </nav>
@endif
