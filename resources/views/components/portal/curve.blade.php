@props([
    'color' => 'var(--color-portal-green)',
    'accent' => 'var(--color-portal-gold)',
])

{{--
    The gold-edged wave that closes the green header on every reference screen.

    Two stacked paths, not a background image: the green sweep and the gold
    edge riding it. Because it is an SVG using CSS variables, it follows the
    school's brand colour rather than needing one exported PNG per school.

    `preserveAspectRatio="none"` lets it stretch to any viewport width without
    the wave changing shape vertically - at 28px tall the distortion is not
    perceptible, and it avoids a media query per breakpoint.
--}}
<div {{ $attributes->merge(['class' => 'pointer-events-none -mt-px h-7 w-full select-none']) }} aria-hidden="true">
    <svg class="h-full w-full" viewBox="0 0 375 28" preserveAspectRatio="none" fill="none">
        <path d="M0,0 H375 V9 C280,29 95,3 0,23 Z" fill="{{ $color }}"/>
        <path d="M0,23 C95,3 280,29 375,9 V14 C280,34 95,8 0,28 Z" fill="{{ $accent }}"/>
    </svg>
</div>
