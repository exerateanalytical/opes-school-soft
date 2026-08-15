@props([
    'title',
    'subtitle' => null,
    'icon' => null,
    'tone' => 'primary',
    'href' => null,
    'trailing' => null,
    'trailingTone' => null,
    'chevron' => true,
    'unread' => false,
])

{{--
    The list row the reference screens repeat everywhere: icon circle, a title
    with an optional second line, an optional value or status pill on the
    right, and a chevron when the row goes somewhere.

    Rendered as an `<a>` only when it has a destination. A div that merely
    looks tappable is worse than a plain row - it invites a click that does
    nothing, which is the complaint this whole restyle came out of.
--}}
@php
    $pill = [
        'success' => 'bg-portal-chip text-portal-success',
        'danger' => 'bg-portal-danger-soft text-portal-danger',
        'warning' => 'bg-warning-bg text-warning-text',
        'gold' => 'bg-gold-100 text-gold-700',
        'primary' => 'bg-portal-tint text-primary',
        'neutral' => 'bg-surface-secondary text-charcoal/70',
    ];

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge([
        'class' => 'flex items-center gap-3 px-4 py-3 '.($href ? 'hover:bg-surface-secondary' : ''),
    ]) }}>

    @if ($icon)
        <x-portal.icon :name="$icon" :tone="$tone"/>
    @endif

    <span class="min-w-0 flex-1">
        <span class="flex items-center gap-2">
            <span @class([
                'truncate text-sm text-charcoal',
                'font-semibold' => $unread,
                'font-medium' => ! $unread,
            ])>{{ $title }}</span>

            @if ($unread)
                <span class="h-2 w-2 shrink-0 rounded-full bg-danger" aria-hidden="true"></span>
            @endif
        </span>

        @if ($subtitle)
            <span class="mt-0.5 block truncate text-xs text-charcoal/60">{{ $subtitle }}</span>
        @endif
    </span>

    @if ($trailing !== null)
        @if ($trailingTone)
            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $pill[$trailingTone] ?? $pill['neutral'] }}">
                {{ $trailing }}
            </span>
        @else
            <span class="shrink-0 text-sm font-semibold tabular-nums text-charcoal">{{ $trailing }}</span>
        @endif
    @endif

    @if ($href && $chevron)
        <svg class="h-4 w-4 shrink-0 text-charcoal/30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 5l7 7-7 7"/>
        </svg>
    @endif
</{{ $tag }}>
