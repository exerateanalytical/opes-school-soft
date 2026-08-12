@props([
    'size' => 'md',
    'tone' => 'gold',
])

{{--
    The school crest from the reference screens: a shield bearing the school's
    initial, framed by laurel branches under a crown.

    Drawn as an SVG rather than shipped as a PNG so it recolours with the
    school's brand colour - the platform already lets a school pick one
    (`feat(branding)`), and one crest image per school would undo that.

    It is a simplified rendering of the artwork in mobile/*.png, not a trace:
    there is no source vector in the repository, and inventing intricate detail
    at 40px would only produce mud. The silhouette - crown, shield, laurels -
    is what reads at portal sizes.
--}}
@php
    $box = match ($size) {
        'sm' => 'h-8 w-8',
        'lg' => 'h-16 w-16',
        'xl' => 'h-24 w-24',
        default => 'h-11 w-11',
    };

    $stroke = $tone === 'gold' ? 'var(--color-portal-gold)' : 'currentColor';
@endphp

<svg {{ $attributes->merge(['class' => $box.' shrink-0']) }}
     viewBox="0 0 64 72" fill="none" aria-hidden="true">
    {{-- crown --}}
    <path d="M26 11l2.5 4 3.5-5 3.5 5 2.5-4 1 6H25l1-6z"
          fill="{{ $stroke }}" opacity="0.95"/>

    {{-- laurel branches --}}
    <path d="M17 26c-4 6-4 14 1 20M47 26c4 6 4 14-1 20"
          stroke="{{ $stroke }}" stroke-width="2" stroke-linecap="round"/>
    <path d="M16 30c-3 1-4 3-4 5M16 36c-3 1-4 3-4 5M18 42c-3 1-4 3-4 5
             M48 30c3 1 4 3 4 5M48 36c3 1 4 3 4 5M46 42c3 1 4 3 4 5"
          stroke="{{ $stroke }}" stroke-width="1.6" stroke-linecap="round"/>

    {{-- shield --}}
    <path d="M21 21h22v20c0 10-7 16-11 19-4-3-11-9-11-19V21z"
          fill="var(--color-portal-green)" stroke="{{ $stroke }}" stroke-width="2.5"
          stroke-linejoin="round"/>

    <text x="32" y="41" text-anchor="middle" font-size="18" font-weight="700"
          font-family="Georgia, serif" fill="{{ $stroke }}">
        {{ mb_substr(__('opes.shell.brand'), 0, 1) }}
    </text>
</svg>
