@props([
    'paginator' => null,
    'window' => 2,
])

@php
    use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;

    // The same contract x-list-screen enforces, restated here because this
    // component is also usable on its own: a paginator, never a collection.
    if (! $paginator instanceof LengthAwarePaginatorContract) {
        throw new \InvalidArgumentException(
            'x-pagination requires a LengthAwarePaginator, '.get_debug_type($paginator).' given.'
        );
    }

    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $window = max(1, (int) $window);

    /*
     * A page window rather than every page number. A school with 4,000 students
     * paginates to 160 pages, and 160 links is a wall, not a control.
     */
    $pages = [];
    $previous = null;

    foreach (range(1, $last) as $page) {
        $inWindow = $page === 1
            || $page === $last
            || abs($page - $current) <= $window;

        if (! $inWindow) {
            continue;
        }

        if ($previous !== null && $page - $previous > 1) {
            $pages[] = null; // gap
        }

        $pages[] = $page;
        $previous = $page;
    }

    $link = 'inline-flex min-w-9 items-center justify-center rounded border px-2.5 py-1.5 text-sm';
    $idle = $link.' border-border-primary bg-white text-charcoal hover:border-primary/50 hover:text-primary';
    $active = $link.' border-primary bg-primary text-white font-semibold';
    $disabled = $link.' border-border-primary bg-sand/40 text-charcoal/40 cursor-not-allowed';
@endphp

<nav role="navigation" aria-label="{{ __('opes.ui.pagination') }}"
     {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1']) }}>

    @if ($paginator->onFirstPage())
        <span class="{{ $disabled }}" aria-disabled="true">{{ __('opes.ui.previous') }}</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $idle }}">
            {{ __('opes.ui.previous') }}
        </a>
    @endif

    @foreach ($pages as $page)
        @if ($page === null)
            <span class="px-1 text-sm text-charcoal/50" aria-hidden="true">&hellip;</span>
        @elseif ($page === $current)
            {{-- aria-current, not colour alone: the active page has to be
                 announced, and the pill still reads on a mono printout. --}}
            <a href="{{ $paginator->url($page) }}" aria-current="page" class="{{ $active }}">{{ $page }}</a>
        @else
            <a href="{{ $paginator->url($page) }}"
               aria-label="{{ __('opes.ui.go_to_page', ['page' => $page]) }}"
               class="{{ $idle }}">{{ $page }}</a>
        @endif
    @endforeach

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $idle }}">
            {{ __('opes.ui.next') }}
        </a>
    @else
        <span class="{{ $disabled }}" aria-disabled="true">{{ __('opes.ui.next') }}</span>
    @endif
</nav>
