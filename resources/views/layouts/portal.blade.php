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

    @php
        /*
         * The portal's own navigation. Six destinations, matching the mobile
         * app's bottom bar so a parent who uses both does not have to learn two
         * mental models of one product.
         *
         * Rendered unconditionally: every screen re-authorizes on entry
         * (GuardianPortalPolicy), so this is chrome, not a gate - the same rule
         * the child tab strip already follows. A destination a guardian may not
         * use answers 403 on arrival rather than vanishing from the bar, which
         * keeps the navigation stable for a family instead of shifting shape
         * per capability.
         */
        $portalNav = [
            ['portal.dashboard', __('opes.guardian_portal.nav_children'), 'M3 12l9-9 9 9M5 10v10h14V10'],
            ['portal.payments', __('opes.guardian_portal.nav_payments'), 'M2 7h20v10H2zM2 11h20'],
            ['portal.messages', __('opes.guardian_portal.nav_messages'), 'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z'],
            ['portal.announcements', __('opes.guardian_portal.nav_announcements'), 'M3 11l18-5v12L3 13v-2z'],
            ['portal.search', __('opes.guardian_portal.nav_search'), 'M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z'],
            ['portal.account', __('opes.guardian_portal.nav_account'), 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z'],
        ];
        $portalCurrent = request()->route()?->getName() ?? '';
    @endphp

    {{-- Desktop and tablet: a horizontal strip under the header. --}}
    <nav aria-label="{{ __('opes.guardian_portal.nav_label') }}"
         class="hidden shrink-0 border-b border-border-primary bg-white sm:block">
        <div class="mx-auto flex max-w-5xl gap-1 px-4 sm:px-6">
            @foreach ($portalNav as [$routeName, $label, $path])
                @php $isActive = str_starts_with($portalCurrent, $routeName); @endphp
                <a href="{{ route($routeName) }}"
                   @if ($isActive) aria-current="page" @endif
                   class="border-b-2 px-3 py-3 text-sm font-medium {{ $isActive
                       ? 'border-primary text-primary'
                       : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </nav>

    {{-- `pb-24` below `sm` keeps the last card clear of the fixed bar. --}}
    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-6 pb-24 sm:px-6 sm:pb-6">
        {{ $slot }}
    </main>

    <footer class="hidden shrink-0 bg-chrome px-4 py-1.5 text-center text-xs text-white/70 sm:block">
        {{ __('opes.shell.brand') }} &middot; {{ __('opes.guardian_portal.footer_note') }}
    </footer>

    {{--
        Mobile: a fixed bottom bar, because this portal is opened on a phone far
        more often than on a desktop and a top nav puts every destination out of
        thumb reach. The 28px top radius and the gold active state are the
        mobile app's own tokens, deliberately.
    --}}
    <nav aria-label="{{ __('opes.guardian_portal.nav_label') }}"
         class="fixed inset-x-0 bottom-0 z-20 flex rounded-t-[28px] bg-chrome pb-[env(safe-area-inset-bottom)] pt-2 shadow-[0_-4px_20px_rgba(0,45,23,0.25)] sm:hidden">
        @foreach ($portalNav as [$routeName, $label, $path])
            @php $isActive = str_starts_with($portalCurrent, $routeName); @endphp
            <a href="{{ route($routeName) }}"
               @if ($isActive) aria-current="page" @endif
               class="flex flex-1 flex-col items-center gap-0.5 px-1 pb-2 text-[10px] {{ $isActive
                   ? 'font-semibold text-heritage-yellow'
                   : 'text-white/65' }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
                </svg>
                <span class="truncate">{{ $label }}</span>
            </a>
        @endforeach
    </nav>
</div>
@livewireScripts
</body>
</html>
