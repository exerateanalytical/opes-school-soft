@props([
    'tone' => 'gold',
    'opacity' => '0.5',
    'scale' => '40',
])

{{--
    The woven geometric band that edges the login artwork - the left margin
    strip and the wide footer course.

    An SVG <pattern> that tiles, rather than a background image: the band has
    to run the full height of a viewport nobody can predict and stay crisp on
    a HiDPI screen, and a raster would either repeat visibly or ship a large
    asset for what is 40 pixels of geometry.

    The id is randomised per instance. Two <pattern> elements sharing an id on
    one page is a silent bug - every user of the id resolves to whichever
    element the browser parsed FIRST - and this component is rendered twice on
    the login screen, so the collision would be guaranteed rather than
    theoretical.
--}}
@php
    $id = 'motif-'.\Illuminate\Support\Str::random(8);

    $ink = $tone === 'gold' ? 'var(--color-portal-gold)' : 'currentColor';

    /*
     * `scale` is the tile size in user units. The same weave has to run fine
     * and dense down the 54px left margin and broad and faint across the whole
     * field, and re-drawing the geometry twice at different coordinates would
     * be two things to keep in step. The tile's own path data is written
     * against a 40-unit square and scaled to fit.
     */
    $tile = max(8, (int) $scale);
    $k = $tile / 40;
@endphp

<svg {{ $attributes->merge(['class' => 'h-full w-full']) }} aria-hidden="true"
     preserveAspectRatio="none">
    <defs>
        <pattern id="{{ $id }}" width="{{ $tile }}" height="{{ $tile }}" patternUnits="userSpaceOnUse">
            <g transform="scale({{ round($k, 4) }})">
                {{-- Concentric diamonds - the motif's anchor. --}}
                <path d="M20 4l16 16-16 16L4 20 20 4z" fill="none" stroke="{{ $ink }}" stroke-width="1.1"/>
                <path d="M20 12l8 8-8 8-8-8 8-8z" fill="none" stroke="{{ $ink }}" stroke-width="0.9"/>
                <path d="M20 17.5l2.5 2.5-2.5 2.5-2.5-2.5 2.5-2.5z" fill="{{ $ink }}"/>

                {{-- Quarter diamonds at the corners, so the tile reads as a
                     continuous weave rather than a grid of separate stamps. --}}
                <path d="M0 20l4-4 4 4-4 4-4-4zM40 20l-4-4-4 4 4 4 4-4z" fill="none"
                      stroke="{{ $ink }}" stroke-width="0.9"/>
                <path d="M20 0l4 4-4 4-4-4 4-4zM20 40l4-4-4-4-4 4 4 4z" fill="none"
                      stroke="{{ $ink }}" stroke-width="0.9"/>
            </g>
        </pattern>
    </defs>

    <rect width="100%" height="100%" fill="url(#{{ $id }})" opacity="{{ $opacity }}"/>
</svg>
