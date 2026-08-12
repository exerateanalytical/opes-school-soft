@props([
    'title',
    'icon' => null,
    'tone' => 'primary',
    'action' => null,
    'href' => null,
])

{{--
    The section header the reference screens use: an icon circle, the title,
    and an optional "View All"/"Manage" link pushed to the right.

    Rendered as a heading with an id so the surrounding card can point
    `aria-labelledby` at it - the designs are heavily card-based, and without
    labelled regions a screen reader hears a dozen unnamed groups.
--}}
@php
    $headingId = 'portal-sec-'.\Illuminate\Support\Str::slug($title).'-'.\Illuminate\Support\Str::random(4);
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    @if ($icon)
        <x-portal.icon :name="$icon" :tone="$tone" size="sm"/>
    @endif

    <h2 id="{{ $headingId }}" class="min-w-0 flex-1 truncate text-base font-semibold text-charcoal">
        {{ $title }}
    </h2>

    @if ($action && $href)
        <a href="{{ $href }}"
           class="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-primary hover:underline">
            {{ $action }}
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    @elseif ($action)
        <span class="shrink-0 text-sm text-charcoal/60">{{ $action }}</span>
    @endif
</div>
