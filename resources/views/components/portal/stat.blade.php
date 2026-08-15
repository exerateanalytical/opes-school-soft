@props([
    'label',
    'value',
    'caption' => null,
    'icon' => null,
    'tone' => 'primary',
    'onDark' => false,
])

{{--
    The overview tile from the dashboard and the fees header: an icon circle,
    a label, a large value, and a caption underneath.

    `tone` colours the VALUE as well as the circle, because the designs render
    a balance due in red and a healthy average in green - the colour carries
    meaning here, not decoration, so it is chosen by the caller from the data
    rather than fixed.
--}}
@php
    $valueTone = match ($tone) {
        'danger' => 'text-portal-danger',
        'warning' => 'text-warning-text',
        'success' => 'text-portal-success',
        default => $onDark ? 'text-white' : 'text-charcoal',
    };
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-1 flex-col items-center gap-1 px-2 py-3 text-center']) }}>
    @if ($icon)
        <x-portal.icon :name="$icon" :tone="$onDark ? 'onChrome' : $tone" class="mb-1"/>
    @endif

    <p @class(['text-xs', 'text-white/70' => $onDark, 'text-charcoal/60' => ! $onDark])>
        {{ $label }}
    </p>

    <p class="text-lg font-bold {{ $valueTone }}">{{ $value }}</p>

    @if ($caption)
        <p @class(['text-[11px]', 'text-white/60' => $onDark, 'text-charcoal/50' => ! $onDark])>
            {{ $caption }}
        </p>
    @endif
</div>
