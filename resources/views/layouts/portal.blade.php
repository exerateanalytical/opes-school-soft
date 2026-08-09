@php
    /** @var \App\Modules\Identity\Models\User|null $portalUser */
    $portalUser = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('opes.guardian_portal.brand_title') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{--
    The guardian/staff portal's OWN chrome (docs/plans/phase-12-13.md 12.2:
    "entirely separate from the staff sidebar shell"). No sidebar, no module
    navigation, no staff KPIs - a parent or a teacher checking their own
    account from a phone gets one page, one back-link, one account menu. The
    dark-chrome/gold palette matches the staff shell's tokens on purpose
    (frontend images/Guardian profile.png) so the product still reads as one
    system, just a narrower door into it.
--}}
<body class="min-h-screen bg-ivory font-sans text-charcoal antialiased">
<div class="flex min-h-screen flex-col">
    <header class="shrink-0 bg-chrome px-4 py-3 text-white sm:px-6">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3">
            <a href="{{ route('portal.dashboard') }}" class="flex items-center gap-2">
                <svg class="h-9 w-9 shrink-0" viewBox="0 0 64 64" fill="none" stroke="var(--color-heritage-yellow)"
                     stroke-width="2" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 22h24v14c0 10-7 16-12 19-5-3-12-9-12-19V22z" stroke-linecap="round"/>
                    <text x="32" y="40" text-anchor="middle" font-size="17" font-weight="700"
                          fill="var(--color-heritage-yellow)" stroke="none" font-family="serif">O</text>
                </svg>
                <span class="leading-tight">
                    <span class="block font-serif text-base font-bold tracking-wide text-heritage-yellow">{{ __('opes.shell.brand') }} {{ __('opes.guardian_portal.brand_suffix') }}</span>
                    <span class="block text-[11px] text-white/70">{{ __('opes.guardian_portal.tagline') }}</span>
                </span>
            </a>

            <div class="flex items-center gap-3 text-sm">
                @if ($portalUser !== null)
                    <span class="hidden sm:inline text-white/85">{{ $portalUser->name }}</span>
                @endif

                <div class="flex items-center gap-1" role="group" aria-label="{{ __('opes.shell.language') }}">
                    @foreach (['en' => 'EN', 'fr' => 'FR'] as $code => $short)
                        <form method="POST" action="/locale">
                            @csrf
                            <input type="hidden" name="locale" value="{{ $code }}">
                            <button type="submit"
                                    @if (app()->getLocale() === $code) aria-current="true" @endif
                                    class="rounded px-2 py-1 text-xs font-semibold {{ app()->getLocale() === $code ? 'bg-heritage-yellow text-charcoal' : 'text-white/70 hover:bg-white/10' }}">
                                {{ $short }}
                            </button>
                        </form>
                    @endforeach
                </div>

                <form method="POST" action="/logout">
                    @csrf
                    <button type="submit" class="rounded border border-white/30 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/10">
                        {{ __('opes.shell.sign_out') }}
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-6 sm:px-6">
        {{ $slot }}
    </main>

    <footer class="shrink-0 bg-chrome px-4 py-1.5 text-center text-xs text-white/70">
        {{ __('opes.shell.brand') }} &middot; {{ __('opes.guardian_portal.footer_note') }}
    </footer>
</div>
@livewireScripts
</body>
</html>
