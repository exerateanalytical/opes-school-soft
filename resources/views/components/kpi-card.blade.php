@props([
    'label' => '',
    'value' => null,
    'delta' => null,
    'href' => null,
])

@php
    // 09-ui section 3.3: a null value renders an em dash, never 0. "No fee has
    // been collected" and "the figure has not been recorded" are different
    // facts, and printing 0 for the second one is how a dashboard starts lying.
    $hasValue = $value !== null && $value !== '';
    $isLink = is_string($href) && $href !== '';
@endphp

<div {{ $attributes->merge(['class' => 'rounded border border-sand bg-white'.($isLink ? ' transition hover:border-primary/50 hover:shadow-sm' : '')]) }}>
    @if ($isLink)
        <a href="{{ $href }}" class="block px-4 py-3">
    @else
        <div class="px-4 py-3">
    @endif

    <p class="text-xs font-medium uppercase tracking-wide text-charcoal/60">{{ $label }}</p>

    <p class="mt-1 text-2xl font-semibold text-charcoal">
        @if ($hasValue)
            {{ $value }}
        @else
            <span title="{{ __('opes.ui.no_data') }}">—</span>
            <span class="sr-only">{{ __('opes.ui.no_data') }}</span>
        @endif
    </p>

    @if ($delta !== null && $delta !== '')
        <p class="mt-0.5 text-xs text-charcoal/60">{{ $delta }}</p>
    @endif

    {{ $slot }}

    @if ($isLink)
        </a>
    @else
        </div>
    @endif
</div>
