@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
        <p class="text-xs text-benguet-charcoal/60 sm:text-sm">
            Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} destinations
        </p>

        <div class="flex max-w-full items-center gap-1 overflow-x-auto py-1">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-boracay-light bg-island-white text-benguet-charcoal/35">
                    <span aria-hidden="true">&lsaquo;</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-boracay-light bg-island-white text-volcanic-teal transition hover:bg-boracay-light">
                    <span aria-hidden="true">&lsaquo;</span>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true" class="inline-flex h-9 min-w-9 shrink-0 items-center justify-center px-1 text-sm text-benguet-charcoal/50">
                        {{ $element }}
                    </span>
                @elseif (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" aria-label="Page {{ $page }}" class="inline-flex h-9 min-w-9 shrink-0 items-center justify-center rounded-md border border-volcanic-teal bg-volcanic-teal px-2 text-sm font-semibold text-white">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}" aria-label="Go to page {{ $page }}" class="inline-flex h-9 min-w-9 shrink-0 items-center justify-center rounded-md border border-boracay-light bg-island-white px-2 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay-light">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-boracay-light bg-island-white text-volcanic-teal transition hover:bg-boracay-light">
                    <span aria-hidden="true">&rsaquo;</span>
                </a>
            @else
                <span aria-disabled="true" aria-label="{{ __('pagination.next') }}" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-boracay-light bg-island-white text-benguet-charcoal/35">
                    <span aria-hidden="true">&rsaquo;</span>
                </span>
            @endif
        </div>
    </nav>
@endif