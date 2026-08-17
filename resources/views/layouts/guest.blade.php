@php
    // The SAME logo the sidebar, the guardian portal and the letterhead use.
    // This page used to hardcode the built-in OPES mark, so a school that had
    // uploaded its own logo still greeted its parents with someone else's
    // crest on the one page they all see. See ResolveSchoolLogo.
    $guestLogoUrl = app(\App\Modules\SchoolProfile\Actions\ResolveSchoolLogo::class)->url();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'OPES SCHOOL') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-sand font-sans text-charcoal antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <main class="w-full max-w-md">
            <div class="overflow-hidden rounded-lg border border-border-primary bg-white shadow-lg shadow-charcoal/5">
                {{-- Chrome-green band with a shield/crest mark, echoing the
                     sidebar wordmark treatment. Red and yellow stay accents:
                     the crest's gold rim is the only heritage-yellow mark on
                     the page. --}}
                <div class="flex flex-col items-center gap-3 bg-chrome px-6 py-8">
                    @if ($guestLogoUrl !== null)
                        {{-- The school's own logo, height-constrained with width
                             auto: a school logo is any aspect ratio at all, and
                             a fixed square box would squash half of them. The
                             wordmark below is suppressed because an uploaded
                             logo almost always carries the school's name inside
                             the artwork - the same reason x-portal.crest-mark
                             drops its label when the real asset is present. --}}
                        <img src="{{ $guestLogoUrl }}" alt="{{ __('opes.branding.app_logo_alt') }}"
                             class="h-16 w-auto max-w-[220px] object-contain">
                    @else
                    <svg class="h-12 w-12 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2.5l7.5 2.7v5.3c0 4.8-3.15 8.9-7.5 10.3-4.35-1.4-7.5-5.5-7.5-10.3V5.2L12 2.5z"
                              fill="none" stroke="var(--color-heritage-yellow)" stroke-width="1.1"/>
                        <path d="M12 5.3l5 1.8v3.5c0 3.35-2.15 6.15-5 7.15-2.85-1-5-3.8-5-7.15V7.1l5-1.8z"
                              fill="var(--color-chrome-light)"/>
                        <path d="M12 8.1l1.15 2.35 2.55.28-1.9 1.75.55 2.52L12 13.7l-2.35 1.3.55-2.52-1.9-1.75 2.55-.28L12 8.1z"
                              fill="var(--color-heritage-yellow)"/>
                    </svg>
                    <div class="flex flex-col items-center leading-tight">
                        <span class="text-lg font-semibold text-white">{{ __('opes.shell.brand') }}</span>
                        <span class="text-xs font-medium tracking-[0.35em] text-white/80">{{ __('opes.shell.brand_suffix') }}</span>
                    </div>
                    @endif
                </div>

                <div class="px-8 py-8">
                    {{ $slot }}
                </div>
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
