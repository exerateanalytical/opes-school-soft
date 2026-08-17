@php
    use App\Modules\Identity\Domain\Role;
    use App\Modules\Identity\Support\Navigation;
    use App\Modules\SchoolProfile\Actions\ReadSetting;
    use App\Modules\SchoolProfile\Actions\ResolveSchoolLogo;
    use App\Modules\SchoolProfile\Livewire\Branding;
    use App\Support\Branding\BrandTokens;

    /** @var \App\Modules\Identity\Models\User|null $shellUser */
    $shellUser = auth()->user();

    // The school's brand palette (/settings/branding), emitted as an
    // UNLAYERED :root override after the compiled stylesheet.
    //
    // Unlayered is load-bearing: Tailwind 4 compiles utilities into
    // @layer utilities, and unlayered CSS outranks every layered rule
    // regardless of specificity. A @layer components version of this block
    // measures correctly in devtools and repaints nothing.
    //
    // ReadSetting is cached (rememberForever, invalidated on write), so
    // this costs nothing beyond the first read per deploy. Wrapped
    // defensively: a hand-edited or stale palette must never take every
    // page in the app down over a cosmetic preference.
    $brandVariables = [];
    $faviconPath = '';

    try {
        $reader = app(ReadSetting::class);

        /** @var mixed $storedPalette */
        $storedPalette = $reader->handle(Branding::PALETTE_KEY, BrandTokens::DEFAULTS);

        $brandVariables = BrandTokens::fromArray(
            is_array($storedPalette) ? $storedPalette : BrandTokens::DEFAULTS
        )->toCssVariables();

        $faviconPath = (string) $reader->handle('branding.favicon_path', '');
    } catch (\Throwable) {
        $brandVariables = BrandTokens::defaults()->toCssVariables();
        $faviconPath = '';
    }

    // ONE resolver, shared with the sign-in page, the guardian portal and the
    // letterhead of newly issued documents. See ResolveSchoolLogo for the
    // precedence and for why the favicon below is NOT part of it (a favicon
    // is a 32px square, not the logo at a small size).
    $appLogoUrl = app(ResolveSchoolLogo::class)->url();

    // Only ever a relative path under branding/ on the `public` disk (the
    // uploader's own contract, mirrored by the settings validation_rule) -
    // so nothing hand-typed can become an arbitrary icon href.
    $faviconUrl = ($faviconPath !== '' && str_starts_with($faviconPath, 'branding/'))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($faviconPath)
        : null;

    // Permission first, then enabled/disabled. An item the user may not see is
    // absent; an item nobody can use yet is present but inert. Conflating the
    // two would either leak the roadmap or hide the product.
    $navItems = array_values(array_filter(
        Navigation::items(),
        static fn (array $item): bool => $item['permission'] === null
            || ($shellUser !== null && $shellUser->can($item['permission']->value)),
    ));

    // Group by 'section', preserving each section's first-appearance order
    // (Navigation::items()'s own order) rather than sorting alphabetically -
    // a school's mental model of "what comes first" is the build's, not the
    // dictionary's.
    $navSections = [];
    foreach ($navItems as $item) {
        $navSections[$item['section']][] = $item;
    }

    $currentPath = '/'.ltrim(request()->path(), '/');

    $shellRoleName = $shellUser?->getRoleNames()->first();
    $shellRole = is_string($shellRoleName) ? Role::tryFrom($shellRoleName) : null;
    $shellRoleLabel = $shellRole?->label(app()->getLocale()) ?? __('opes.shell.no_role');

    $appVersion = (string) config('app.version', 'dev');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'OPES SCHOOL') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    @if ($faviconUrl !== null)
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    {{-- UNLAYERED on purpose - see the PHP block at the top of this file.
         Blade compiles directives BEFORE it strips comments, so a directive
         name written inside a comment is still compiled: never spell one out
         here. --}}
    <style>
        :root {
            @foreach ($brandVariables as $brandVariableName => $brandVariableValue)
            {{ $brandVariableName }}: {{ $brandVariableValue }};
            @endforeach
        }
    </style>
</head>
{{-- `opes-app` is the scope hook for the shared form-control treatment in
     app.css. It is deliberately ONLY on this layout: the auth screens and the
     guardian portal carry their own approved designs, and a platform-wide
     field restyle must not reach into them. --}}
<body class="opes-app min-h-screen bg-ivory font-sans text-charcoal antialiased">
{{-- The top bar is white; the sidebar is dark chrome-green running the full
     viewport height beside it (00-core 8.2 - one continuous chrome surface,
     just no longer painted the same colour top-to-bottom). The drawer state
     lives on the wrapper so the hamburger in the bar can drive the sidebar. --}}
<div class="flex min-h-screen flex-col md:flex-row" x-data="{ nav: false }" @keydown.escape.window="nav = false">

    {{-- ── Sidebar ─────────────────────────────────────────────────────── --}}
    <div x-show="nav" x-cloak class="fixed inset-0 z-20 bg-charcoal/50 md:hidden"
         x-on:click="nav = false" aria-hidden="true"></div>

    {{-- `md:sticky md:top-0 md:h-screen md:self-start`, NOT `md:static`.

         The nav lists up to 50 modules. As a static flex item it grew to its
         content height - 2585px measured on an administrator's /dashboard -
         and because it is a sibling of <main> in a flex row it dragged the
         whole PAGE to 2585px. Actual dashboard content is 911px, so every
         screen in the product carried ~1700px of blank canvas below the fold
         that no amount of restyling the content could fill. That is most of
         what "dashboards empty" was describing, and it was never a dashboard
         bug.

         `overflow-y-auto` was already here and never fired, because nothing
         constrained the height for it to overflow. h-screen supplies the
         constraint; self-start stops the flex row stretching it back out
         (align-items defaults to stretch, which would silently undo h-screen);
         sticky keeps it in view while the page scrolls. Below md the drawer is
         `fixed inset-y-0` and is untouched. --}}
    <nav id="opes-sidebar" aria-label="{{ __('opes.shell.primary_navigation') }}"
         class="fixed inset-y-0 left-0 z-20 hidden w-60 shrink-0 flex-col overflow-y-auto bg-chrome pb-4 md:sticky md:top-0 md:z-auto md:flex md:h-screen md:self-start"
         :class="{ 'hidden': ! nav, 'flex': nav }">

        {{-- Crest: shield + crown + laurel wreath, gold line-art on the dark
             chrome sidebar, built from the same two tokens as the rest of the
             shell chrome (00-core section 8) - no new colours introduced. --}}
        <a href="/dashboard" class="flex flex-col items-center gap-1 px-4 pt-6 pb-4 text-center">
            @if ($appLogoUrl !== null)
                {{-- The school's own logo replaces the built-in OPES mark
                     once one is uploaded. Height-constrained, width auto:
                     a school logo is any aspect ratio at all, and a fixed
                     square box would squash half of them. --}}
                <img src="{{ $appLogoUrl }}" alt="{{ __('opes.branding.app_logo_alt') }}"
                     class="h-16 w-auto max-w-[200px] object-contain">
            @else
            <svg class="h-16 w-16" viewBox="0 0 64 64" fill="none" stroke="var(--color-heritage-yellow)"
                 stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">
                {{-- laurel wreath, left and right --}}
                <path d="M14 46c-5-6-6-16-2-24" stroke-linecap="round"/>
                <path d="M13 22l-3.5-1 1 3.5M13 30l-4-.5.5 4M14 38l-4 .5.8 4"/>
                <path d="M50 46c5-6 6-16 2-24" stroke-linecap="round"/>
                <path d="M51 22l3.5-1-1 3.5M51 30l4-.5-.5 4M50 38l4 .5-.8 4"/>
                {{-- crown --}}
                <path d="M24 14l3 6 5-8 5 8 3-6 2 8H22z" stroke-linecap="round"/>
                {{-- shield --}}
                <path d="M20 22h24v14c0 10-7 16-12 19-5-3-12-9-12-19V22z" stroke-linecap="round"/>
                {{-- letterform --}}
                <text x="32" y="40" text-anchor="middle" font-size="17" font-weight="700"
                      fill="var(--color-heritage-yellow)" stroke="none" font-family="serif">O</text>
            </svg>
            @endif
            <span class="font-serif text-sm font-bold leading-tight tracking-wide text-heritage-yellow">{{ __('opes.shell.brand') }} {{ __('opes.shell.brand_suffix') }}</span>
            <span class="text-[11px] italic leading-tight text-heritage-yellow/85">{{ __('opes.shell.tagline') }}</span>
        </a>

        <x-toghu-band/>

        @foreach ($navSections as $sectionKey => $sectionItems)
            {{-- Section header: small uppercase muted label, the same
                 treatment the top bar already uses for the secondary date
                 line (text-charcoal/50 there; text-white/50 here to sit on
                 the dark chrome sidebar) - no new visual language. --}}
            <p class="{{ $loop->first ? 'mt-1' : 'mt-3' }} px-4 pb-1 text-[11px] font-semibold uppercase tracking-wide text-white/50">
                {{ __('opes.nav_section.'.$sectionKey) }}
            </p>
            <ul class="space-y-0.5 px-2 pb-2">
                @foreach ($sectionItems as $item)
                    @php
                        $label = __('opes.nav.'.$item['key']);
                        $isActive = $item['enabled'] && $item['route'] === $currentPath;
                    @endphp
                    <li>
                        @if ($item['enabled'] && is_string($item['route']))
                            <a href="{{ $item['route'] }}"
                               @if ($isActive) aria-current="page" @endif
                               @unless ($item['built'] ?? true) title="{{ __('opes.nav.nav_disabled_title') }}" @endunless
                               class="relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm {{ $isActive
                                   ? 'bg-primary font-semibold text-white'
                                   : (($item['built'] ?? true)
                                       ? 'text-white/85 hover:bg-chrome-light/60 hover:text-white'
                                       : 'text-white/60 hover:bg-chrome-light/40 hover:text-white/90') }}">
                                @if ($isActive)
                                    {{-- The design system's gold left indicator on
                                         the active module. Absolutely positioned so
                                         it cannot shift the label the way a border
                                         would, and aria-hidden because
                                         aria-current="page" already carries this
                                         meaning to a screen reader. --}}
                                    <span class="absolute inset-y-1 left-0 w-[3px] rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                                @endif
                                <x-opes-nav-icon :nav-key="$item['key']"
                                                 class="h-4.5 w-4.5 shrink-0 {{ $isActive ? 'text-heritage-yellow' : '' }}"/>
                                <span class="min-w-0 truncate">{{ $label }}</span>
                                @unless ($item['built'] ?? true)
                                    {{-- The module is scheduled, not missing: the
                                         link works and lands on a page that says
                                         so. The chip keeps built and coming
                                         modules distinguishable at a glance. --}}
                                    <span class="ml-auto shrink-0 rounded-full bg-heritage-yellow/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-heritage-yellow">
                                        {{ __('opes.placeholder.chip_short') }}
                                    </span>
                                @endunless
                            </a>
                        @else
                            <span aria-disabled="true"
                                  title="{{ __('opes.nav.nav_disabled_title') }}"
                                  class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm text-white/35">
                                <x-opes-nav-icon :nav-key="$item['key']" class="h-4.5 w-4.5 shrink-0"/>
                                {{ $label }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach

        {{-- Per-screen QUICK ACTIONS box. Each screen @push()es its own list
             into this stack; a screen that pushes nothing simply renders no
             box, rather than a stale or generic one. --}}
        @stack('sidebar-quick-actions')
    </nav>

    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
        {{-- ── Top bar ─────────────────────────────────────────────────── --}}
        <header class="sticky top-0 z-30 flex h-16 min-w-0 shrink-0 items-center gap-3 border-b border-border-primary bg-white px-3 sm:px-4">
            <button type="button"
                    class="-ml-1 rounded p-2 text-charcoal/70 hover:bg-sand md:hidden"
                    :aria-expanded="nav ? 'true' : 'false'"
                    aria-controls="opes-sidebar"
                    x-on:click="nav = ! nav">
                <span class="sr-only">{{ __('opes.shell.open_menu') }}</span>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     aria-hidden="true">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <button type="button"
                    class="hidden rounded p-2 text-charcoal/70 hover:bg-sand md:inline-flex"
                    x-on:click="nav = ! nav">
                <span class="sr-only">{{ __('opes.shell.open_menu') }}</span>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     aria-hidden="true">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="flex shrink-0 flex-col leading-tight">
                <span class="font-serif text-lg font-bold tracking-tight text-chrome">{{ __('opes.shell.brand') }} {{ __('opes.shell.brand_suffix') }}</span>
                <span class="hidden text-[11px] text-charcoal/50 sm:block">School Management System</span>
            </div>

            {{-- Was disabled on purpose, with a tooltip explaining why - "a
                 search box that quietly swallows every query is worse than
                 no search box at all." Real now: Reporting\GlobalSearch. --}}
            <div class="mx-auto hidden w-full min-w-0 max-w-sm lg:block">
                @if ($shellUser !== null)
                    @livewire('reporting.global-search.index')
                @endif
            </div>

            <div class="ml-auto flex items-center gap-2 sm:gap-3">
                {{-- Real server date/time, not a mockup placeholder. --}}
                <div class="hidden items-center gap-2 text-charcoal/70 lg:flex">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="16" rx="2"/>
                        <path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/>
                    </svg>
                    <span class="text-xs leading-tight">
                        <span class="block font-medium text-charcoal">{{ now()->translatedFormat('d F Y') }}</span>
                        <span class="block text-charcoal/50">{{ now()->translatedFormat('l') }}</span>
                    </span>
                </div>

                {{-- The real notification engine (Notifications module):
                     a genuine unread count from the notifications table,
                     not a fabricated badge on a dead button. --}}
                @if ($shellUser !== null)
                    @livewire('notifications.bell')
                @endif

                <a href="{{ route('communication.messages') }}" title="{{ __('opes.messages_screen.title') }}"
                   class="hidden rounded-full p-2 text-charcoal/60 hover:bg-sand lg:inline-flex">
                    <span class="sr-only">{{ __('opes.messages_screen.title') }}</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6"/>
                    </svg>
                </a>

                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button"
                            class="flex items-center gap-2 rounded px-1 py-1 text-sm text-charcoal hover:bg-sand"
                            :aria-expanded="open ? 'true' : 'false'"
                            x-on:click="open = ! open">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-chrome text-xs font-semibold uppercase text-white">
                            {{ mb_substr((string) ($shellUser->name ?? '?'), 0, 1) }}
                        </span>
                        <span class="hidden flex-col items-start leading-tight lg:flex">
                            <span class="max-w-32 truncate text-sm font-medium text-charcoal">{{ $shellUser->name ?? '' }}</span>
                            <span class="max-w-32 truncate text-xs text-charcoal/50">{{ $shellRoleLabel }}</span>
                        </span>
                        <svg class="hidden h-3.5 w-3.5 text-charcoal/40 lg:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>
                        </svg>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                         class="absolute right-0 z-40 mt-1 w-56 rounded border border-border-primary bg-white py-1 shadow-lg">
                        <p class="px-3 py-2 text-xs text-charcoal/60">
                            {{ __('opes.shell.signed_in_as') }}
                            <span class="block truncate text-sm font-medium text-charcoal">{{ $shellUser->name ?? '' }}</span>
                        </p>
                        {{-- EN / FR. Two plain form buttons rather than a select: no
                             JavaScript, and it works on the cheap Android handsets that
                             make up most of the field fleet. --}}
                        <div class="flex items-center gap-1 border-t border-border-primary px-3 py-2" role="group"
                             aria-label="{{ __('opes.shell.language') }}">
                            @foreach (['en' => 'EN', 'fr' => 'FR'] as $code => $short)
                                <form method="POST" action="/locale">
                                    @csrf
                                    <input type="hidden" name="locale" value="{{ $code }}">
                                    <button type="submit"
                                            title="{{ $code === 'en' ? __('opes.shell.switch_to_english') : __('opes.shell.switch_to_french') }}"
                                            @if (app()->getLocale() === $code) aria-current="true" @endif
                                            class="rounded px-2 py-1 text-xs font-semibold {{ app()->getLocale() === $code ? 'bg-heritage-yellow text-charcoal' : 'text-charcoal/60 hover:bg-sand' }}">
                                        {{ $short }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                        {{-- The staff shell had no own-account screen at all,
                             so this menu offered a name, a language toggle and
                             a way out. --}}
                        <a href="/account"
                           class="block border-t border-border-primary px-3 py-2 text-sm text-charcoal hover:bg-sand">
                            {{ __('opes.account.title') }}
                        </a>
                        <form method="POST" action="/logout">
                            @csrf
                            <button type="submit"
                                    class="w-full px-3 py-2 text-left text-sm text-charcoal hover:bg-sand">
                                {{ __('opes.shell.sign_out') }}
                            </button>
                        </form>
                    </div>
                </div>

                {{-- /settings has existed since the wiring pass and is in the
                     sidebar; the comment that used to sit here claiming
                     otherwise outlived the route by several phases. Behind
                     setting.view because the route is - a link the holder's
                     permissions refuse is the one thing the nav contract
                     forbids offering. --}}
                @can('setting.view')
                <a href="{{ route('settings.index') }}" title="{{ __('opes.nav.settings') }}"
                   class="hidden rounded-full p-2 text-charcoal/60 transition hover:bg-sand hover:text-primary lg:inline-flex">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/>
                    </svg>
                </a>
                @endcan
            </div>
        </header>

        {{-- ── Main ────────────────────────────────────────────────────── --}}
        {{-- The canvas is deliberately the soft surface, not white: the design
             system's desktop structure is white CARDS lifting off a #F5F7F6
             BODY. On an all-white canvas the cards have nothing to separate
             from and the page reads flat. --}}
        <main class="min-w-0 flex-1 overflow-x-hidden bg-sand px-4 py-6 sm:px-6">
            {{ $slot }}
        </main>

        {{-- ── Status strip ────────────────────────────────────────────── --}}
        <footer class="shrink-0 bg-chrome px-4 py-1.5 text-xs text-white/80"
                aria-label="{{ __('opes.shell.status_strip') }}">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span>{{ __('opes.shell.user') }}: {{ $shellUser->name ?? '' }}</span>
                    <span aria-hidden="true">|</span>
                    <span>{{ __('opes.shell.role') }}: {{ $shellRoleLabel }}</span>
                    <span aria-hidden="true">|</span>
                    {{-- No academic-session concept exists yet, so this is an
                         honest em-dash rather than an invented school year. --}}
                    <span>Session: —</span>
                </div>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span>V {{ $appVersion }}</span>
                    <span aria-hidden="true">|</span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                        {{ __('opes.shell.connected') }}
                    </span>
                    <svg class="h-3.5 w-3.5 text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <ellipse cx="12" cy="5" rx="8" ry="3"/>
                        <path stroke-linecap="round" d="M4 5v6c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 11v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>
                    </svg>
                </div>
            </div>
        </footer>
    </div>
</div>

@livewireScripts

@if ($shellUser !== null)
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.opesInitPushNotifications) {
                window.opesInitPushNotifications();
            }
        });
    </script>
@endif
</body>
</html>
