{{-- items: list<array{href: string, label: string, permission: string|null}> --}}
@props([
    'items' => [],
])

{{-- A module whose sidebar entry lands on ONE of its screens needs a
     sub-nav, or the other eight are unreachable - which is exactly what
     happened to the whole procure-to-pay chain and to the ledger. Rendered
     on every screen in the module, so the operator can move between them
     without going back to the sidebar.

     Permission-filtered here rather than by Route::has(): the sidebar's
     contract is that a link is only ever offered to a role allowed to
     follow it, and /settings/licence already shows what Route::has() gets
     wrong. --}}
<nav {{ $attributes->merge(['class' => '-mx-4 border-b border-border-primary px-4 sm:mx-0 sm:px-0']) }}
     aria-label="{{ __('opes.ui.section_navigation') }}">
    <div class="flex flex-wrap items-center gap-1">
        @foreach ($items as $item)
            @if ($item['permission'] === null || Illuminate\Support\Facades\Gate::allows($item['permission']))
                @php $isCurrent = request()->is(ltrim($item['href'], '/')); @endphp
                <a href="{{ $item['href'] }}"
                   @if ($isCurrent) aria-current="page" @endif
                   class="rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition {{ $isCurrent ? 'border-primary text-primary' : 'border-transparent text-charcoal/60 hover:border-border-primary hover:text-charcoal' }}">
                    {{ $item['label'] }}
                </a>
            @endif
        @endforeach
    </div>
</nav>
