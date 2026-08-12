@php
    /** @var \App\Modules\Identity\Models\User|null $portalUser */
    $portalUser = auth()->user();

    $portalUnread = $portalUser === null ? 0 : app(\App\Modules\Guardians\Support\Portal\GuardianInbox::class)
        ->unreadNotificationCount((int) $portalUser->getKey());

    /*
     * The portal's own navigation. Six destinations, in the order the mobile
     * designs put them, so a parent who uses both meets one product.
     *
     * Rendered unconditionally: every screen re-authorizes on entry, so this
     * is chrome, not a gate. That differs from the CHILD tab strip, which does
     * filter - the difference is that these six are reachable by every portal
     * principal, whereas a child tab can be closed for a particular link and
     * offering it would be an invitation to a wall.
     */
    $portalNav = [
        ['portal.dashboard', __('opes.guardian_portal.nav_children'), 'home'],
        ['portal.payments', __('opes.guardian_portal.nav_payments'), 'card'],
        ['portal.messages', __('opes.guardian_portal.nav_messages'), 'chat'],
        ['portal.announcements', __('opes.guardian_portal.nav_announcements'), 'megaphone'],
        ['portal.account', __('opes.guardian_portal.nav_account'), 'user'],
    ];

    $portalCurrent = request()->route()?->getName() ?? '';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#012A17">
    <title>{{ $title ?? __('opes.guardian_portal.brand_title') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{--
    The guardian portal's own chrome, built to mobile/parent-dashboard.png and
    its siblings: a deep-green header closing on a gold wave, a NEAR-WHITE
    canvas beneath it, and a floating bottom bar on small screens.

    The canvas colour is measured, not chosen: the reference screens are
    #FEFEFE behind the cards, not the warm sand this portal used before. Only
    the identity card is tinted (#F5F8F6). See the portal palette in
    resources/css/app.css.

    Entirely separate from the staff sidebar shell (phase-12-13 12.2). A parent
    gets one page, one back-link and one account menu - never a module nav.
--}}
<body class="min-h-screen bg-portal-surface font-sans text-charcoal antialiased">
<div class="flex min-h-screen flex-col">

    {{-- ---------------------------------------------------------- header -- --}}
    <header class="shrink-0 bg-portal-green text-white">
        <div class="mx-auto w-full max-w-5xl px-4 pb-1 pt-3 sm:px-6">
            <div class="flex items-center gap-3">
                <a href="{{ route('portal.dashboard') }}" class="flex min-w-0 items-center gap-2.5">
                    <x-portal.crest size="md"/>

                    <span class="min-w-0 leading-tight">
                        <span class="block truncate font-serif text-base font-bold tracking-[0.12em] text-portal-gold">
                            {{ __('opes.shell.brand') }}
                        </span>
                        <span class="block truncate text-[11px] text-white/65">
                            {{ __('opes.guardian_portal.tagline') }}
                        </span>
                    </span>
                </a>

                <div class="ml-auto flex shrink-0 items-center gap-1.5">
                    @if ($portalUser !== null)
                        <a href="{{ route('portal.notifications') }}"
                           class="relative flex h-10 w-10 items-center justify-center rounded-full hover:bg-white/10"
                           aria-label="{{ __('opes.guardian_portal.notifications_title') }}">
                            <x-portal.icon name="bell" bare size="md"/>

                            @if ($portalUnread > 0)
                                <span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-portal-danger px-1 text-[10px] font-bold text-white">
                                    {{ $portalUnread > 99 ? '99+' : $portalUnread }}
                                </span>
                            @endif
                        </a>

                        <a href="{{ route('portal.search') }}"
                           class="flex h-10 w-10 items-center justify-center rounded-full hover:bg-white/10"
                           aria-label="{{ __('opes.guardian_portal.search_title') }}">
                            <x-portal.icon name="search" bare size="md"/>
                        </a>

                        <a href="{{ route('portal.account') }}"
                           class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 hover:bg-white/10">
                            <x-portal.avatar :name="$portalUser->name" size="sm" tone="gold"
                                             :photo="route('portal.photo.self')"/>
                            <span class="hidden text-sm text-white/85 sm:inline">{{ $portalUser->name }}</span>
                        </a>
                    @endif

                    <div class="flex items-center gap-1" role="group" aria-label="{{ __('opes.shell.language') }}">
                        @foreach (['en' => 'EN', 'fr' => 'FR'] as $code => $short)
                            <form method="POST" action="/locale">
                                @csrf
                                <input type="hidden" name="locale" value="{{ $code }}">
                                <button type="submit"
                                        @if (app()->getLocale() === $code) aria-current="true" @endif
                                        class="rounded-full px-2 py-1 text-[11px] font-semibold {{ app()->getLocale() === $code
                                            ? 'bg-portal-gold text-charcoal'
                                            : 'text-white/60 hover:bg-white/10' }}">
                                    {{ $short }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>

        </div>
    </header>

    <x-portal.curve/>

    {{--
        Below lg this is the phone design and nothing else: full-width content,
        floating bottom bar. From lg up it becomes a sidebar shell, because a
        393px-wide column stretched across a 1400px monitor is not "the same
        design" - it is the design misused. Same cards, same palette, same
        components; the navigation simply moves from the thumb to the left rail.
    --}}
    <div class="mx-auto flex w-full max-w-7xl flex-1 gap-6 px-4 pb-28 pt-2 sm:px-6 lg:pb-10">

        {{-- Desktop sidebar. Hidden entirely on phones, where the bottom bar
             is the navigation. --}}
        <aside class="hidden w-60 shrink-0 lg:block">
            <nav aria-label="{{ __('opes.guardian_portal.nav_label') }}"
                 class="sticky top-4 space-y-1 rounded-2xl border border-border-primary bg-white p-3 shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
                @foreach ($portalNav as [$routeName, $label, $icon])
                    @php $isActive = str_starts_with($portalCurrent, $routeName); @endphp
                    <a href="{{ route($routeName) }}"
                       @if ($isActive) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $isActive
                           ? 'bg-portal-green text-white'
                           : 'text-charcoal/70 hover:bg-portal-tint hover:text-primary' }}">
                        <x-portal.icon :name="$icon" bare size="md"/>
                        <span class="truncate">{{ $label }}</span>

                        @if ($routeName === 'portal.messages' && $portalUnread > 0)
                            <span class="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-portal-danger px-1.5 text-[11px] font-bold text-white">
                                {{ $portalUnread }}
                            </span>
                        @endif
                    </a>
                @endforeach

                <div class="!mt-3 border-t border-border-secondary pt-3">
                    @foreach ([
                        ['portal.school-life', __('opes.guardian_portal.activities_title'), 'megaphone'],
                        ['portal.account.settings', __('opes.guardian_portal.account_settings_title'), 'gear'],
                        ['portal.help', __('opes.guardian_portal.help_title'), 'help'],
                    ] as [$routeName, $label, $icon])
                        <a href="{{ route($routeName) }}"
                           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-charcoal/70 hover:bg-portal-tint hover:text-primary">
                            <x-portal.icon :name="$icon" bare size="md"/>
                            <span class="truncate">{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>
        </aside>

        <main class="min-w-0 flex-1">
            @if (session('portal-status'))
                <p class="mb-4 rounded-xl border border-success/30 bg-portal-chip px-4 py-3 text-sm font-medium text-portal-success">
                    {{ session('portal-status') }}
                </p>
            @endif

            {{ $slot }}
        </main>
    </div>

    <footer class="hidden shrink-0 px-4 py-4 text-center text-xs text-charcoal/45 sm:block">
        {{ __('opes.shell.brand') }} &middot; {{ __('opes.guardian_portal.footer_note') }}
    </footer>

    {{--
        The floating bar from the designs, up to lg - which is where the
        sidebar takes over. Tablets keep it too: a thumb is still the pointing
        device at 900px.
    --}}
    <nav aria-label="{{ __('opes.guardian_portal.nav_label') }}"
         class="fixed inset-x-0 bottom-0 z-30 rounded-t-[28px] bg-portal-green pb-[env(safe-area-inset-bottom)] shadow-[0_-6px_24px_rgba(0,45,23,0.3)] lg:hidden">
        <div class="flex items-stretch px-1 pt-2">
            @foreach ($portalNav as [$routeName, $label, $icon])
                @php $isActive = str_starts_with($portalCurrent, $routeName); @endphp
                <a href="{{ route($routeName) }}"
                   @if ($isActive) aria-current="page" @endif
                   class="flex flex-1 flex-col items-center gap-1 px-1 pb-2 pt-1 text-[10px] {{ $isActive
                       ? 'font-semibold text-portal-gold'
                       : 'text-white/60' }}">
                    <x-portal.icon :name="$icon" bare size="md"/>
                    <span class="truncate">{{ $label }}</span>
                </a>
            @endforeach
        </div>
    </nav>
</div>
@livewireScripts
</body>
</html>
