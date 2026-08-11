@php
    /*
     * A 403 on the guardian portal is not an error - it is a correct answer
     * about how the school configured this guardian's link, and it deserves a
     * sentence a parent can act on rather than the framework's bare
     * "403 | FORBIDDEN".
     *
     * The staff side keeps a neutral message: a staff 403 usually IS a
     * misconfiguration, and telling an administrator "your school has not
     * shared this with you" would be nonsense.
     */
    $isPortal = request()->is('portal') || request()->is('portal/*');
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('opes.guardian_portal.brand_title') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-ivory font-sans text-charcoal antialiased">
<div class="flex min-h-screen flex-col">
    <header class="shrink-0 bg-chrome px-4 py-3 text-white sm:px-6">
        <div class="mx-auto flex max-w-5xl items-center gap-2">
            <svg class="h-8 w-8 shrink-0" viewBox="0 0 64 64" fill="none" stroke="var(--color-heritage-yellow)"
                 stroke-width="2" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 22h24v14c0 10-7 16-12 19-5-3-12-9-12-19V22z" stroke-linecap="round"/>
            </svg>
            <span class="font-serif text-base font-bold tracking-wide text-heritage-yellow">
                {{ __('opes.shell.brand') }}
            </span>
        </div>
    </header>

    <main class="mx-auto flex w-full max-w-xl flex-1 items-center px-4 py-10">
        <div class="w-full rounded border border-border-primary bg-white p-6 text-center shadow-sm">
            <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-surface-green"
                  aria-hidden="true">
                <svg class="h-7 w-7 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </span>

            <h1 class="text-lg font-semibold text-charcoal">
                {{ $isPortal ? __('opes.guardian_portal.denied_title') : __('opes.errors.forbidden_title') }}
            </h1>

            <p class="mt-2 text-sm text-charcoal/70">
                {{ $isPortal ? __('opes.guardian_portal.denied_body') : __('opes.errors.forbidden_body') }}
            </p>

            @if ($isPortal)
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <a href="{{ route('portal.dashboard') }}"
                       class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-chrome-light">
                        {{ __('opes.guardian_portal.back_to_dashboard') }}
                    </a>
                    <a href="{{ route('portal.help') }}"
                       class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.guardian_portal.help_title') }}
                    </a>
                </div>
            @endif
        </div>
    </main>
</div>
</body>
</html>
