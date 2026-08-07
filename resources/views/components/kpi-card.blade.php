@props([
    'label' => '',
    'value' => null,
    'delta' => null,
    'href' => null,
    'icon' => null,
    'iconBg' => 'bg-primary',
    'trend' => null, // 'up' | 'down' | null - only set this when $delta traces to real data
])

@php
    // 09-ui section 3.3: a null value renders an em dash, never 0. "No fee has
    // been collected" and "the figure has not been recorded" are different
    // facts, and printing 0 for the second one is how a dashboard starts lying.
    $hasValue = $value !== null && $value !== '';
    $isLink = is_string($href) && $href !== '';

    $trend = in_array($trend, ['up', 'down'], true) ? $trend : null;
    $deltaTone = match ($trend) {
        'up' => 'text-primary',
        'down' => 'text-heritage-red',
        default => 'text-charcoal/60',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded border border-sand bg-white'.($isLink ? ' transition hover:border-primary/50 hover:shadow-sm' : '')]) }}>
    @if ($isLink)
        <a href="{{ $href }}" class="flex items-start gap-3 px-4 py-3">
    @else
        <div class="flex items-start gap-3 px-4 py-3">
    @endif

    @if ($icon !== null)
        {{-- The coloured circle badge from the mockups - varies per card
             ($iconBg), never used on page chrome (00-core 8). --}}
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $iconBg }} text-white">
            {!! $icon !!}
        </span>
    @endif

    <div class="min-w-0">
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
            <p class="mt-0.5 flex items-center gap-1 text-xs font-medium {{ $deltaTone }}">
                @if ($trend === 'up')
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M9 6h9v9"/></svg>
                @elseif ($trend === 'down')
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M15 18H6V9"/></svg>
                @endif
                {{ $delta }}
            </p>
        @endif

        {{ $slot }}
    </div>

    @if ($isLink)
        </a>
    @else
        </div>
    @endif
</div>
