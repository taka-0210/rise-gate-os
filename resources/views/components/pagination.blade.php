@if ($paginator->hasPages())
    <nav class="pagination" aria-label="ページ送り">
        <div class="pagination-summary">
            {{ $paginator->firstItem() }}〜{{ $paginator->lastItem() }}件目 / 全{{ $paginator->total() }}件
        </div>
        <div class="pagination-actions">
            @if ($paginator->onFirstPage())
                <span class="pagination-link disabled" aria-disabled="true">前へ</span>
            @else
                <a class="pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">前へ</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pagination-ellipsis">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pagination-link active" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pagination-link" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">次へ</a>
            @else
                <span class="pagination-link disabled" aria-disabled="true">次へ</span>
            @endif
        </div>
    </nav>
@endif
