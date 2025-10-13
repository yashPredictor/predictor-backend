@if ($paginator->hasPages())
    <nav class="api-pagination">
        @if ($paginator->onFirstPage())
            <span class="api-pagination__link disabled" aria-disabled="true">Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="api-pagination__link" data-page="{{ $paginator->currentPage() - 1 }}">Prev</a>
        @endif

        @php
            $start = max(1, $paginator->currentPage() - 2);
            $end = min($paginator->lastPage(), $paginator->currentPage() + 2);
        @endphp

        @if ($start > 1)
            <a href="{{ $paginator->url(1) }}" class="api-pagination__link" data-page="1">1</a>
            @if ($start > 2)
                <span class="api-pagination__ellipsis">…</span>
            @endif
        @endif

        @for ($page = $start; $page <= $end; $page++)
            @if ($page == $paginator->currentPage())
                <span class="api-pagination__link active">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="api-pagination__link" data-page="{{ $page }}">{{ $page }}</a>
            @endif
        @endfor

        @if ($end < $paginator->lastPage())
            @if ($end + 1 < $paginator->lastPage())
                <span class="api-pagination__ellipsis">…</span>
            @endif
            <a href="{{ $paginator->url($paginator->lastPage()) }}" class="api-pagination__link" data-page="{{ $paginator->lastPage() }}">{{ $paginator->lastPage() }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="api-pagination__link" data-page="{{ $paginator->currentPage() + 1 }}">Next</a>
        @else
            <span class="api-pagination__link disabled" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
