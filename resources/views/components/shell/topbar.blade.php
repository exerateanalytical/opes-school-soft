@props([
    'user' => null,
    'roleLabel' => '',
    'greetingName' => '',
    'academicYear' => '',
    'campusLabel' => '',
    'unreadMessages' => 0,
])

@php
    // Up to two initials from the display name - "Super Administrator" -> SA,
    // exactly the lockup the reference draws in the avatar disc. Computed
    // here rather than in a helper because it is presentation, and the only
    // caller is this file.
    $initials = collect(preg_split('/\s+/', trim((string) ($user->name ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [])
        ->take(2)
        ->map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $initials = $initials !== '' ? $initials : '?';
@endphp

{{--
    The back-office top bar, built to `frontend images/super admin
    dashbaord.png`.

    MEASURED (docs/superpowers/specs/2026-08-20-admin-dashboard-measurements.md):
      bar height          y 0..116 -> 117px
      eyebrow             cap 10 -> 14px
      name                the page's largest type
      subline             cap 12 -> 16px
      right rail          two rows: selectors + identity above,
                          date / time / refresh below

    Everything the previous bar carried is still here - the hamburger, global
    search, the real notification bell, messages, the account menu with its
    EN/FR switch and sign-out. The reference simply arranges them differently
    and gives search an icon rather than a permanently open field, so nothing
    is removed; only the composition changes.
--}}
{{-- NOT a white bar. Sampled down column x=1450 of the reference, the whole
     top region is #FBFAF7 - the same warm ivory as the canvas - with no rule
     under it. The only white in the reference's header is on the pill
     controls themselves. A white bar with a divider, which is what this was,
     draws a horizontal line across a design that deliberately has none.

     It still needs an OPAQUE background despite matching the canvas: it is
     sticky, and a transparent sticky header lets the page scroll visibly
     through it. --}}
<header class="sticky top-0 z-30 shrink-0 bg-shell-ground px-5 pt-[12px] pb-[6px]">
    <div class="flex items-start gap-4">

        {{-- ── Left: menu + greeting ───────────────────────────────────── --}}
        <button type="button"
                class="mt-0.5 shrink-0 rounded-lg p-1.5 text-charcoal/75 transition hover:bg-shell-ground"
                :aria-expanded="nav ? 'true' : 'false'"
                aria-controls="opes-sidebar"
                x-on:click="nav = ! nav">
            <span class="sr-only">{{ __('opes.shell.open_menu') }}</span>
            <x-shell.icon name="menu" class="h-[22px] w-[22px]"/>
        </button>

        <div class="min-w-0 flex-1">
            <p class="text-[14px] leading-none text-charcoal/70">{{ __('opes.shell.welcome_back') }}</p>

            <h1 class="mt-1.5 flex items-center gap-1.5 text-[30px] font-bold leading-none tracking-tight text-charcoal">
                <span class="min-w-0 truncate">{{ $greetingName }}</span>
                <x-verified-badge :official="(bool) ($user->is_official ?? false)" class="h-5 w-5 shrink-0"/>
            </h1>

            <p class="mt-2 truncate text-[16px] leading-none text-charcoal/70">
                {{ __('opes.shell.network_subline') }}
            </p>
        </div>

        {{-- ── Right rail ──────────────────────────────────────────────── --}}
        <div class="flex shrink-0 flex-col items-end gap-3">

            {{-- Row 1: scope selectors, alerts, identity --}}
            <div class="flex items-center gap-3">
                {{-- Search kept as a disclosure: the reference has no open
                     field in the bar, and dropping the feature to match a
                     picture would be a regression, not a restyle. --}}
                <div x-data="{ search: false }" class="relative">
                    <button type="button" x-on:click="search = ! search"
                            class="rounded-lg p-2 text-charcoal/70 transition hover:bg-shell-ground">
                        <span class="sr-only">{{ __('opes.global_search.title') }}</span>
                        <x-shell.icon name="search" class="h-[19px] w-[19px]"/>
                    </button>
                    <div x-show="search" x-cloak @click.outside="search = false"
                         class="absolute right-0 top-full z-40 mt-2 w-80 rounded-xl border border-shell-divider bg-white p-2 shadow-lg">
                        @if ($user !== null)
                            @livewire('reporting.global-search.index')
                        @endif
                    </div>
                </div>

                <button type="button"
                        class="flex h-[38px] items-center gap-2 rounded-xl border border-shell-divider bg-white px-3 text-[15px] font-medium text-charcoal transition hover:bg-shell-ground">
                    <x-shell.icon name="campus" class="h-[19px] w-[19px] text-charcoal"/>
                    <span class="max-w-40 truncate">{{ $campusLabel }}</span>
                    <x-shell.icon name="chevron_down" class="h-4 w-4 text-charcoal/45"/>
                </button>

                <button type="button"
                        class="flex h-[38px] items-center gap-2 rounded-xl border border-shell-divider bg-white px-3 text-[15px] font-medium text-charcoal transition hover:bg-shell-ground">
                    <x-shell.icon name="calendar" class="h-[19px] w-[19px] text-primary"/>
                    <span class="whitespace-nowrap">{{ $academicYear }}</span>
                    <x-shell.icon name="chevron_down" class="h-4 w-4 text-charcoal/45"/>
                </button>

                @if ($user !== null)
                    @livewire('notifications.bell')
                @endif

                <a href="{{ route('communication.messages') }}" wire:navigate
                   class="relative rounded-lg p-1.5 text-charcoal transition hover:bg-shell-ground">
                    <span class="sr-only">{{ __('opes.messages_screen.title') }}</span>
                    <x-shell.icon name="mail" class="h-[22px] w-[22px]"/>
                    @if ($unreadMessages > 0)
                        <span class="absolute -right-0.5 -top-0.5 flex h-[17px] min-w-[17px] items-center justify-center rounded-full bg-shell-alert px-1 text-[10px] font-bold leading-none text-white">
                            {{ $unreadMessages > 99 ? '99+' : $unreadMessages }}
                        </span>
                    @endif
                </a>

                {{-- Account menu: unchanged in what it offers, restyled to the
                     reference's avatar + name + presence lockup. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" x-on:click="open = ! open"
                            :aria-expanded="open ? 'true' : 'false'"
                            class="flex items-center gap-2.5 rounded-xl px-1 py-1 transition hover:bg-shell-ground">
                        <span class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full bg-shell-field text-[15px] font-bold uppercase text-white">
                            {{ $initials }}
                        </span>
                        <span class="hidden flex-col items-start leading-tight xl:flex">
                            <span class="max-w-36 truncate text-[15px] font-medium text-charcoal">{{ $roleLabel }}</span>
                            <span class="mt-0.5 flex items-center gap-1 text-[13px] text-success">
                                <span class="h-2 w-2 rounded-full bg-success" aria-hidden="true"></span>
                                {{ __('opes.shell.online') }}
                            </span>
                        </span>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                         class="absolute right-0 z-40 mt-1 w-56 rounded-xl border border-shell-divider bg-white py-1 shadow-lg">
                        <p class="px-3 py-2 text-xs text-charcoal/60">
                            {{ __('opes.shell.signed_in_as') }}
                            <span class="block truncate text-sm font-medium text-charcoal">{{ $user->name ?? '' }}</span>
                        </p>
                        <div class="flex items-center gap-1 border-t border-shell-divider px-3 py-2" role="group"
                             aria-label="{{ __('opes.shell.language') }}">
                            @foreach (['en' => 'EN', 'fr' => 'FR'] as $code => $short)
                                <form method="POST" action="/locale">
                                    @csrf
                                    <input type="hidden" name="locale" value="{{ $code }}">
                                    <button type="submit"
                                            title="{{ $code === 'en' ? __('opes.shell.switch_to_english') : __('opes.shell.switch_to_french') }}"
                                            @if (app()->getLocale() === $code) aria-current="true" @endif
                                            class="rounded px-2 py-1 text-xs font-semibold {{ app()->getLocale() === $code ? 'bg-heritage-yellow text-charcoal' : 'text-charcoal/60 hover:bg-shell-ground' }}">
                                        {{ $short }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                        <a href="/account" class="block border-t border-shell-divider px-3 py-2 text-sm text-charcoal hover:bg-shell-ground">
                            {{ __('opes.account.title') }}
                        </a>
                        @can('setting.view')
                            <a href="{{ route('settings.index') }}" class="block border-t border-shell-divider px-3 py-2 text-sm text-charcoal hover:bg-shell-ground">
                                {{ __('opes.nav.settings') }}
                            </a>
                        @endcan
                        <form method="POST" action="/logout">
                            @csrf
                            <button type="submit" class="w-full border-t border-shell-divider px-3 py-2 text-left text-sm text-charcoal hover:bg-shell-ground">
                                {{ __('opes.shell.sign_out') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Row 2: server date, server time, refresh --}}
            <div class="flex items-center gap-3 text-[15px] text-charcoal">
                <span class="flex items-center gap-2">
                    <x-shell.icon name="calendar" class="h-[18px] w-[18px] text-primary"/>
                    {{ now()->translatedFormat('l, j F Y') }}
                </span>

                <span class="h-4 w-px bg-shell-divider" aria-hidden="true"></span>

                <span class="flex items-center gap-2">
                    <x-shell.icon name="clock" class="h-[18px] w-[18px] text-charcoal"/>
                    {{ now()->translatedFormat('g:i A') }}
                </span>

                {{-- A real reload, not a decorative control: the bar renders
                     server time and live counts, so "Refresh" has to actually
                     re-fetch them. --}}
                <button type="button" onclick="window.location.reload()"
                        class="flex h-[38px] items-center gap-2 rounded-xl bg-surface-green px-3.5 text-[15px] font-semibold text-primary transition hover:bg-kpi-green">
                    <x-shell.icon name="refresh" class="h-[18px] w-[18px]"/>
                    {{ __('opes.shell.refresh') }}
                </button>
            </div>
        </div>
    </div>
</header>
