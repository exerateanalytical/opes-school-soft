{{-- One titled group of related settings. The `hint` says what the whole
     group AFFECTS, which is the question a settings screen most often fails
     to answer ("does this print, or is it only on screen?"). --}}
@props([
    'heading',
    'hint' => null,
    'columns' => 2,
])
<section class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
    <h2 class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">{{ $heading }}</h2>
    @if ($hint !== null)
        <p class="mt-1 text-xs text-text-secondary">{{ $hint }}</p>
    @endif
    <div @class([
        'mt-4 grid gap-4',
        'sm:grid-cols-2' => (int) $columns === 2,
        'sm:grid-cols-3' => (int) $columns === 3,
    ])>
        {{ $slot }}
    </div>
</section>
