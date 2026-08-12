@props([
    'size' => 'lg',
    'label' => true,
    'on' => 'dark',
])

{{--
    The OPES crest: gold star, laurel wreath, green shield carrying a white
    mortarboard with a gold tassel, the OPES ribbon, and "SCHOOL SYSTEM" set
    beneath.

    THE REAL ARTWORK when it is installed, a drawn fallback when it is not.

    `public/images/opes-crest.png` and `opes-crest-dark.png` are cut from the
    supplied logo sheet, with the white ground removed by a FLOOD FILL from
    the border rather than a "make white transparent" pass - the mortarboard
    is white and the ribbon face is cream, so a global colour test would punch
    holes through the middle of the mark.

    `on` names the BACKGROUND the crest sits on, not the crest's own colour,
    and it selects between two files. The artwork is drawn for white paper:
    its laurels, shield fill and wordmark are all the same dark green, so on
    the sign-in page's green field the wreath and the wordmark simply
    disappear. The dark-ground file re-inks the leaves gold and the wordmark
    cream while leaving the shield, ribbon and cap exactly as drawn - which is
    the variant the reference mockup itself shows.

    THE FALLBACK IS NOT DECORATION. If the assets are absent the SVG below
    renders instead, so a deployment that has not installed brand files still
    gets a crest rather than a broken image. It is a fair likeness, not a
    trace: the wreath is one branch mirrored by transform with leaf angles
    from trigonometry, which is the only way the halves stay true mirrors.

    Deliberately a SECOND component rather than a rewrite of `x-portal.crest`.
    That one is the 32-44px mark in the portal header, drawn as a silhouette
    because this much detail at that size is mud.
--}}
@php
    $box = match ($size) {
        'sm' => 'w-24',
        'md' => 'w-32',
        'xl' => 'w-[12.6rem]',
        default => 'w-[8.8rem]',
    };

    // The file carries "SCHOOL SYSTEM" inside the artwork, so the HTML label
    // below is suppressed whenever the image is used - printing it twice is
    // the obvious failure here.
    $asset = $on === 'dark' ? 'images/opes-crest-dark.png' : 'images/opes-crest.png';
    $hasAsset = is_file(public_path($asset));
@endphp

@if ($hasAsset)
    <img src="{{ asset($asset) }}" alt="{{ __('opes.shell.brand') }} {{ __('opes.auth.brand_suffix') }}"
         {{ $attributes->merge(['class' => 'block h-auto '.$box]) }}>
@else
@php

    $onDark = $on === 'dark';

    // Cream rather than pure white: the artwork's highlights are warm, and
    // #FFF against this green reads as a hole punched in the page.
    $cream = '#F6F2E6';

    $leafInk = $onDark ? 'var(--color-portal-gold)' : 'var(--color-portal-green)';
    $shieldRim = $onDark ? $cream : 'var(--color-portal-green)';
    $bannerFace = $onDark ? 'var(--color-portal-gold)' : $cream;
    $bannerRim = $onDark ? 'var(--color-portal-gold-deep)' : 'var(--color-portal-green)';

    /*
     * One branch of the wreath, sweeping from the foot of the shield up its
     * left side. Angles are measured the mathematical way (0 = east, growing
     * anticlockwise) and flipped into SVG's y-down space where they are used.
     *
     * The rotation is `45 - a`, which puts each leaf tangential to the arc and
     * canted outward: at a = 180 (the widest point) that resolves to 225deg,
     * i.e. up and to the left, which is the direction a laurel leaf grows.
     */
    $branch = [];
    $count = 11;

    for ($i = 0; $i < $count; $i++) {
        $a = 268 - (268 - 116) * ($i / ($count - 1));
        $rad = deg2rad($a);

        $branch[] = [
            'x' => round(100 + 58 * cos($rad), 2),
            'y' => round(98 - 58 * sin($rad), 2),
            'r' => round(45 - $a, 2),
            // Fullest in the middle of the branch, tapering to both tips -
            // what stops the wreath reading as a ring of identical blobs.
            's' => round(0.62 + 0.38 * sin(M_PI * $i / ($count - 1)), 3),
        ];
    }
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex flex-col items-center '.$box]) }}>
    <svg viewBox="0 0 200 178" fill="none" class="w-full" aria-hidden="true">

        {{-- ----------------------------------------------------- star -- --}}
        <path d="M100 4l5.3 10.9 12 1.7-8.7 8.4 2.1 11.9-10.7-5.6-10.7 5.6 2.1-11.9-8.7-8.4 12-1.7L100 4z"
              fill="var(--color-portal-gold)"/>

        {{-- --------------------------------------------- laurel wreath -- --}}
        {{-- Left branch drawn once; the right is the same geometry reflected
             about the centre line. --}}
        @foreach (['', 'translate(200,0) scale(-1,1)'] as $mirror)
            <g @if ($mirror !== '') transform="{{ $mirror }}" @endif>
                {{-- The stem the leaves grow from. --}}
                <path d="M100 158C74 156 52 140 44 116 38 96 42 70 54 52"
                      stroke="{{ $leafInk }}" stroke-width="2.6" stroke-linecap="round" fill="none"/>

                @foreach ($branch as $leaf)
                    {{-- A pointed leaf, drawn along +x and rotated into place. --}}
                    <path d="M0 0C5-6 16-5 21 0 16 5 5 6 0 0z"
                          fill="{{ $leafInk }}"
                          transform="translate({{ $leaf['x'] }},{{ $leaf['y'] }})
                                     rotate({{ $leaf['r'] }}) scale({{ $leaf['s'] }})"/>
                @endforeach
            </g>
        @endforeach

        {{-- ---------------------------------------------------- shield -- --}}
        <path d="M60 44Q100 34 140 44v42q0 32-40 50-40-18-40-50V44z"
              fill="var(--color-portal-green)" stroke="{{ $shieldRim }}" stroke-width="4"
              stroke-linejoin="round"/>

        {{-- The thin inner rule inset from the shield edge. --}}
        <path d="M68 51Q100 43 132 51v35q0 26-32 42-32-16-32-42V51z"
              fill="none" stroke="{{ $cream }}" stroke-width="1.6" opacity="0.5"/>

        {{-- ----------------------------------------------- mortarboard -- --}}
        {{-- The tassel hangs from the board's right corner, drawn BEFORE the
             cap so its cord tucks behind the board's edge. --}}
        <path d="M133 76v22" stroke="var(--color-portal-gold)" stroke-width="2.2" stroke-linecap="round"/>
        <path d="M129.5 96h7l-1.5 12c0 1.6-.9 2.4-2 2.4s-2-.8-2-2.4l-1.5-12z"
              fill="var(--color-portal-gold)"/>

        <g fill="{{ $cream }}">
            {{-- The cap beneath the board. --}}
            <path d="M80 82v15c0 6 9 9.5 20 9.5s20-3.5 20-9.5V82l-20 8-20-8z"/>
            {{-- The board itself, a square seen in perspective. --}}
            <path d="M100 62l36 14-36 14-36-14 36-14z"/>
        </g>

        {{-- ---------------------------------------------------- banner -- --}}
        {{-- Folded tails first, so the ribbon's face overlaps them - which is
             what gives a ribbon its depth. --}}
        <path d="M30 130l24-6v34l-24-6 7-11-7-11z" fill="var(--color-portal-gold)"
              stroke="{{ $bannerRim }}" stroke-width="1.4" stroke-linejoin="round"/>
        <path d="M170 130l-24-6v34l24-6-7-11 7-11z" fill="var(--color-portal-gold)"
              stroke="{{ $bannerRim }}" stroke-width="1.4" stroke-linejoin="round"/>

        {{-- The face, arced the way the artwork's ribbon lifts at its centre. --}}
        <path d="M48 128Q100 116 152 128v26Q100 142 48 154V128z"
              fill="{{ $bannerFace }}" stroke="{{ $bannerRim }}" stroke-width="1.6"
              stroke-linejoin="round"/>

        <text x="100" y="146" text-anchor="middle"
              font-family="Georgia, 'Times New Roman', serif" font-size="26" font-weight="700"
              letter-spacing="1" fill="var(--color-portal-green)">
            {{ __('opes.shell.brand') }}
        </text>
    </svg>

    {{-- HTML text, not an SVG <text>: real copy that must stay selectable,
         translatable and properly hinted at small sizes.

         The word is "SCHOOL SYSTEM", NOT the portal's `brand_suffix`
         ("Family Portal"). This is the platform's single sign-in page - staff
         come through it too - so the crest must not announce the parent
         portal to a bursar. --}}
    @if ($label)
        {{-- `whitespace-nowrap`: the label is a LOCKUP with the crest above it,
             and letting "SCHOOL SYSTEM" break onto two lines - which it does
             at the narrow card width - turns the mark into something the
             brand does not have. --}}
        <span class="mt-2 whitespace-nowrap font-serif text-[0.72em] font-bold uppercase leading-none
                     tracking-[0.14em] text-current">
            {{ __('opes.auth.brand_suffix') }}
        </span>
    @endif
</span>
@endif
