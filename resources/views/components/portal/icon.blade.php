@props([
    'name' => 'dot',
    'tone' => 'primary',
    'size' => 'md',
    'bare' => false,
])

{{--
    The coloured icon circle that heads every section and every list row in the
    reference screens.

    One place for the glyph paths, because the alternative is the same inline
    SVG pasted into twenty views and drifting. `bare` renders the glyph without
    its circle, for the few places the designs use a plain icon (the header
    bell, breadcrumb chevrons).

    The palette is the platform's own tokens - the designs tint each row's
    circle by subject, and these are the tints that already exist.
--}}
@php
    $paths = [
        'home' => 'M3 11l9-8 9 8M5 10v10a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V10',
        'users' => 'M17 20v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M10 10a3 3 0 100-6 3 3 0 000 6M21 20v-2a4 4 0 00-3-3.87M16 4.13A4 4 0 0119 8',
        'user' => 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8',
        'book' => 'M4 5a2 2 0 012-2h13v16H6a2 2 0 00-2 2V5zM19 3v18',
        'chart' => 'M4 19h16M7 16V9M12 16V5M17 16v-4',
        'calendar' => 'M8 3v3M16 3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z',
        'card' => 'M2 7h20v10a1 1 0 01-1 1H3a1 1 0 01-1-1V7zM2 11h20M2 7l.01-1a1 1 0 011-1H21a1 1 0 011 1V7',
        'receipt' => 'M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6',
        'wallet' => 'M3 7h16a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7zM3 7l2-3h12M17 13h.01',
        'chat' => 'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',
        'bell' => 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
        'megaphone' => 'M3 11l16-5v12L3 13v-2zM3 11v4a2 2 0 002 2h1M9 17.5a3 3 0 005.9.8',
        'search' => 'M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z',
        'heart' => 'M20.8 8.6a5 5 0 00-8.8-2.4A5 5 0 003.2 8.6C2.5 12.5 8 16.5 12 20c4-3.5 9.5-7.5 8.8-11.4z',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9.5 12l2 2 3.5-4',
        'file' => 'M14 3H7a1 1 0 00-1 1v16a1 1 0 001 1h10a1 1 0 001-1V7l-4-4zM14 3v4h4M9 13h6M9 17h4',
        'gear' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1A1.7 1.7 0 008.9 19a1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1A1.7 1.7 0 004.6 8.9a1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z',
        'help' => 'M12 17h.01M9.1 9a3 3 0 015.8 1c0 2-3 3-3 3M12 21a9 9 0 100-18 9 9 0 000 18z',
        'alert' => 'M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
        'clock' => 'M12 7v5l3 2M12 21a9 9 0 100-18 9 9 0 000 18z',
        'globe' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM3 12h18M12 3a15 15 0 010 18 15 15 0 010-18z',
        'id' => 'M3 6h18v12H3zM8 12a2 2 0 100-4 2 2 0 000 4zM5 16c.6-1.6 2-2.5 3-2.5s2.4.9 3 2.5M14 10h4M14 13h4',
        'pin' => 'M12 21s7-6.3 7-11a7 7 0 10-14 0c0 4.7 7 11 7 11zM12 12a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
        'mail' => 'M3 6h18v12H3zM3 7l9 6 9-6',
        'phone' => 'M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8.1 9.9a16 16 0 006 6l1.3-1.2a2 2 0 012.1-.5c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z',
        'check' => 'M20 6L9 17l-5-5',
        // The padlock the sign-in artwork uses on the password field, the
        // submit button and the card's gold tab. `shield` was standing in for
        // it, which read as a different mark at those three sizes.
        'lock' => 'M5 11h14a1 1 0 011 1v8a1 1 0 01-1 1H5a1 1 0 01-1-1v-8a1 1 0 011-1zM8 11V7a4 4 0 118 0v4M12 15v2',
        'dot' => 'M12 13a1 1 0 100-2 1 1 0 000 2z',
    ];

    $tones = [
        'primary' => 'bg-portal-tint text-primary',
        'gold' => 'bg-gold-100 text-gold-700',
        'danger' => 'bg-portal-danger-soft text-portal-danger',
        'success' => 'bg-portal-chip text-portal-success',
        'warning' => 'bg-warning-bg text-warning-text',
        'info' => 'bg-info-bg text-info',
        'onChrome' => 'bg-white/10 text-portal-gold',
    ];

    // `xl` exists for the sign-in artwork's security panel, whose shield is
    // noticeably larger than any icon the portal screens use.
    $circle = match ($size) {
        'sm' => 'h-8 w-8',
        'lg' => 'h-12 w-12',
        'xl' => 'h-14 w-14',
        default => 'h-10 w-10',
    };

    $glyph = match ($size) {
        'sm' => 'h-4 w-4',
        'lg' => 'h-6 w-6',
        'xl' => 'h-8 w-8',
        default => 'h-5 w-5',
    };
@endphp

@if ($bare)
    <svg {{ $attributes->merge(['class' => $glyph.' shrink-0']) }}
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $paths[$name] ?? $paths['dot'] }}"/>
    </svg>
@else
    <span {{ $attributes->merge([
        'class' => 'flex shrink-0 items-center justify-center rounded-full '
            .$circle.' '.($tones[$tone] ?? $tones['primary']),
    ]) }} aria-hidden="true">
        <svg class="{{ $glyph }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="{{ $paths[$name] ?? $paths['dot'] }}"/>
        </svg>
    </span>
@endif
