{{--
    ═══════════════════════════════════════════════════════════════════════════
    x-list-screen - the ONE list layout every module screen composes (09-ui 4).
    ═══════════════════════════════════════════════════════════════════════════

    breadcrumb · title · header actions (primary is a green split-button)
    KPI strip            (2-up scrollable < md, 3-up md, 5-up lg)
    filter bar           (always ends with Filter | Reset)
    status tabs          (optional, with counts)
    table                |  right rail (optional)
    "Showing X to Y of Z" · pagination · page-size selector

    ── The binding rules, enforced HERE rather than trusted to callers ────────

    1. `paginator` MUST be a LengthAwarePaginator. Passing a collection throws.
       00-core 6.2 rule 8 says no unbounded collection query reaches a view; a
       contract a caller can forget is not a contract, so the component refuses.
    2. Filters are URL state. The caller marks its filter properties `#[Url]`;
       this component only draws the bar and the Reset control.
    3. Reset clears the filters AND returns to page 1. `resetAction` names the
       Livewire method that must do both (default `resetFilters`).
    4. The rail collapses first at narrow widths, and the page body NEVER
       scrolls horizontally - the table scrolls inside its own overflow-x-auto.
    5. Below `md` the rows render as cards, one per row - not a shrunken grid.
    6. An empty result renders x-empty-state naming the reason and offering the
       primary action, never a bare header row.

    ── MOBILE CARDS: read this before you use the component ──────────────────

    A Blade component cannot re-render arbitrary row markup two different ways:
    the `slot` you fill with <tr> elements is already-rendered HTML by the time
    it arrives here, and <tr> outside a <table> is not renderable as a card.

    So there are TWO row slots and the caller fills both:

        <x-slot:slot>   ...<tr> rows, shown from md up...      </x-slot:slot>
        <x-slot:cards>  ...<article> cards, shown below md...  </x-slot:cards>

    A caller who fills only the default slot still gets a correct screen - the
    table simply stays a table on a phone and scrolls sideways inside its own
    container. That is acceptable (nothing is lost or clipped) but it is NOT
    the target experience, and every screen shipped to a field school should
    fill `cards`. The duplication is real; it is the honest price of letting
    each screen decide which two or three columns matter on a 360px handset,
    which is a judgement no generic component can make.

    ── Slots ─────────────────────────────────────────────────────────────────

    actions · kpis · filters · tabs · head · rail · cards · slot (table rows)

    `breadcrumb` is a PROP (an array of labels), not a slot: it is always plain
    text, and an array keeps the separators and the aria-current consistent.
--}}

@props([
    'title' => '',
    'breadcrumb' => [],
    'paginator' => null,
    'emptyMessage' => null,
    'railTitle' => null,
    'resetAction' => 'resetFilters',
    'resetUrl' => null,
    'perPageProperty' => 'perPage',
    'perPageOptions' => [25, 50, 100],
    'showPerPage' => true,
])

@php
    use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;

    // Binding rule 1. Fail loudly at the boundary rather than quietly render a
    // screen that loaded every row in the table.
    if (! $paginator instanceof LengthAwarePaginatorContract) {
        throw new \InvalidArgumentException(
            'x-list-screen requires a LengthAwarePaginator so that every list is '
            .'server-paginated (00-core 6.2 rule 8). '.get_debug_type($paginator).' given.'
        );
    }

    $crumbs = is_array($breadcrumb) ? array_values(array_filter($breadcrumb, 'is_string')) : [];

    $isEmpty = $paginator->total() === 0 || $paginator->isEmpty();

    $first = $paginator->firstItem() ?? 0;
    $last = $paginator->lastItem() ?? 0;

    $hasRail = isset($rail);
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0 space-y-4']) }}>

    {{-- ── Breadcrumb ───────────────────────────────────────────────────── --}}
    @if ($crumbs !== [])
        <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
            <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
                @foreach ($crumbs as $index => $crumb)
                    <li class="flex items-center gap-1">
                        @if ($index > 0)
                            <span aria-hidden="true" class="text-charcoal/30">/</span>
                        @endif
                        @if ($loop->last)
                            <span aria-current="page" class="font-medium text-charcoal/80">{{ $crumb }}</span>
                        @else
                            <span>{{ $crumb }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    {{-- ── Title + header actions ───────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">{{ $title }}</h1>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>

    {{-- ── KPI strip. 2-up and scrollable below md, 3-up at md, 5-up at lg. --}}
    @isset($kpis)
        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <div class="grid min-w-max grid-cols-2 gap-3 sm:min-w-0 md:grid-cols-3 lg:grid-cols-5">
                {{ $kpis }}
            </div>
        </div>
    @endisset

    {{-- ── Filter bar. ALWAYS ends with Filter | Reset (09-ui 4). ───────── --}}
    @isset($filters)
        <section aria-label="{{ __('opes.ui.filters') }}"
                 class="rounded border border-sand bg-white p-3">
            <div class="flex flex-wrap items-end gap-3">
                {{ $filters }}

                <div class="ml-auto flex items-center gap-2">
                    <button type="submit" wire:click="$refresh"
                            class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('opes.ui.filter') }}
                    </button>

                    {{-- Binding rule 3: Reset clears the filters AND goes back
                         to page 1. Page 3 of a filter you just cleared is a
                         blank screen the operator reads as "no data". --}}
                    @if (is_string($resetUrl) && $resetUrl !== '')
                        <a href="{{ $resetUrl }}"
                           class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.ui.reset') }}
                        </a>
                    @else
                        <button type="button" wire:click="{{ $resetAction }}"
                                class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.ui.reset') }}
                        </button>
                    @endif
                </div>
            </div>
        </section>
    @endisset

    {{-- ── Status tabs ──────────────────────────────────────────────────── --}}
    @isset($tabs)
        <div class="-mx-4 overflow-x-auto border-b border-sand px-4 sm:mx-0 sm:px-0">
            <div class="flex min-w-max items-center gap-1">
                {{ $tabs }}
            </div>
        </div>
    @endisset

    {{-- ── Body: table (+ optional rail). The rail collapses first. ─────── --}}
    <div class="flex min-w-0 flex-col gap-4 {{ $hasRail ? 'lg:flex-row' : '' }}">
        <div class="min-w-0 flex-1">
            @if ($isEmpty)
                {{-- Binding rule 6. A header row with nothing under it does not
                     tell the operator whether the filter is wrong or the data
                     is absent, so it is never rendered. --}}
                <x-empty-state :message="$emptyMessage ?? __('opes.ui.no_results')">
                    @isset($actions)
                        <x-slot:action>{{ $actions }}</x-slot:action>
                    @endisset
                </x-empty-state>
            @else
                {{-- Rule 5: cards below md, when the caller supplied them. --}}
                @isset($cards)
                    <div class="space-y-2 md:hidden">
                        {{ $cards }}
                    </div>
                @endisset

                {{-- Rule 4: the wide thing scrolls inside ITSELF. The page body
                     never scrolls horizontally (09-ui 10). --}}
                <div class="{{ isset($cards) ? 'hidden md:block ' : '' }}min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        @isset($head)
                            <thead class="border-b border-sand bg-sand/40 text-left">
                                {{ $head }}
                            </thead>
                        @endisset
                        <tbody class="divide-y divide-sand">
                            {{ $slot }}
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @isset($rail)
            <aside class="w-full shrink-0 lg:w-72"
                   @if (is_string($railTitle) && $railTitle !== '') aria-label="{{ $railTitle }}" @endif>
                @if (is_string($railTitle) && $railTitle !== '')
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                        {{ $railTitle }}
                    </h2>
                @endif
                {{ $rail }}
            </aside>
        @endisset
    </div>

    {{-- ── Footer: range · pagination · page size ───────────────────────── --}}
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-charcoal/70">
        <p>
            {{ __('opes.ui.showing', [
                'first' => $first,
                'last' => $last,
                'total' => $paginator->total(),
            ]) }}
        </p>

        <div class="flex flex-wrap items-center gap-3">
            @if ($paginator->lastPage() > 1)
                <x-pagination :paginator="$paginator"/>
            @endif

            @if ($showPerPage)
                <label for="list-screen-per-page" class="flex items-center gap-2">
                    <span class="whitespace-nowrap">{{ __('opes.ui.per_page') }}</span>
                    <select id="list-screen-per-page" wire:model.live="{{ $perPageProperty }}"
                            class="rounded border border-sand bg-white px-2 py-1 text-sm text-charcoal">
                        @foreach ($perPageOptions as $option)
                            <option value="{{ $option }}"
                                    @if ((int) $option === $paginator->perPage()) selected @endif>
                                {{ $option }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
    </div>
</div>
