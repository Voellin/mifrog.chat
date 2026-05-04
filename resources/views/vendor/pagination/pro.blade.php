@if ($paginator->hasPages())
<nav class="pro-pager" aria-label="{{ __('Pagination Navigation') }}">
    <div class="pro-pager-meta">
        共 {{ $paginator->total() }} 条 · 第 {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} 页
    </div>
    <div class="pro-pager-buttons">
        @if ($paginator->onFirstPage())
            <span class="pro-pager-btn is-disabled">‹</span>
        @else
            <a class="pro-pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">‹</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pro-pager-btn is-disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pro-pager-btn is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pro-pager-btn" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="pro-pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">›</a>
        @else
            <span class="pro-pager-btn is-disabled">›</span>
        @endif
    </div>
</nav>
@endif
