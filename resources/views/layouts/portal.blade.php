@php
    /** @var \App\Modules\Identity\Models\User|null $portalUser */
    $portalUser = auth()->user();

    $portalUnread = $portalUser === null ? 0 : app(\App\Modules\Guardians\Support\Portal\GuardianInbox::class)
        ->unreadNotificationCount((int) $portalUser->getKey());

    /*
     * The five destinations the reference designs put in the bar, in their
     * order: Dashboard, Children, Academics, Payments, More.
     *
     * Rendered unconditionally: every screen re-authorizes on entry, so this
     * is chrome, not a gate. That differs from the CHILD tab strip, which does
     * filter - these five are reachable by every portal principal, whereas a
     * child tab can be closed for a particular link and offering it would be
     * an invitation to a wall.
     *
     * "Academics" is CHILD-SCOPED in this product - there is no top-level
     * academics screen - so it resolves to the first linked child's results
     * and falls back to the children index for a guardian with none. Pointing
     * it at the children index outright would give the bar two buttons that
     * land on the same page.
     *
     * "More" is the account hub, which is where the designs' overflow screens
     * (settings, security, help, search, school life) actually live.
     */
    $portalFirstChild = $portalUser === null ? null : \Illuminate\Support\Facades\DB::table('student_guardians')
        ->join('guardians', 'guardians.id', '=', 'student_guardians.guardian_id')
        ->where('guardians.portal_user_id', $portalUser->getKey())
        ->orderBy('student_guardians.student_id')
        ->value('student_guardians.student_id');

    $portalNav = [
        ['portal.dashboard', __('opes.guardian_portal.nav_dashboard'), 'home', []],
        ['portal.children.index', __('opes.guardian_portal.nav_children'), 'users', []],
        $portalFirstChild === null
            ? ['portal.children.index', __('opes.guardian_portal.nav_academics'), 'book', []]
            : ['portal.children.results', __('opes.guardian_portal.nav_academics'), 'book', ['student' => $portalFirstChild]],
        ['portal.payments', __('opes.guardian_portal.nav_payments'), 'card', []],
        ['portal.account', __('opes.guardian_portal.nav_more'), 'menu', []],
    ];

    $portalCurrent = request()->route()?->getName() ?? '';

    // The SCHOOL's name, not the product's. The designs put the school's crest
    // and wordmark in the header - a parent is signing in to their child's
    // school, not to a software vendor - and `school.name` is already set.
    $portalSettings = app(\App\Modules\SchoolProfile\Actions\ReadSetting::class);

    $portalSchool = trim((string) ($portalSettings->handle(
        app()->getLocale() === 'fr' ? 'school.name_fr' : 'school.name'
    ) ?? __('opes.shell.brand')));

    // Split on the first space so the header can set the lead word large and
    // the rest small beneath it, the way the reference wordmark is drawn.
    [$portalSchoolLead, $portalSchoolRest] = str_contains($portalSchool, ' ')
        ? [strtok($portalSchool, ' '), trim(substr($portalSchool, strpos($portalSchool, ' ')))]
        : [$portalSchool, ''];

    /*
     * The school's own strapline ("Learn. Grow. Excel." in the designs).
     *
     * Read from settings with the portal's tagline as fallback, rather than
     * hardcoding the design's words: that line belongs to the school, and this
     * is a multi-tenant product. A school that has not set one still gets a
     * sensible line instead of a gap.
     */
    $portalStrapline = trim((string) ($portalSettings->handle('school.strapline')
        ?? __('opes.guardian_portal.tagline')));
@endphp
<!DOCTYPE html>
{{-- `portal-root` drops the root font-size from the platform's 17px to the
     16px the phone designs are drawn at. It has to sit on <html>: rem always
     resolves against :root, so the same class on <body> would do nothing. See
     resources/css/app.css. --}}
<html lang="{{ app()->getLocale() }}" class="portal-root">
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
    {{--
        Built to the reference header: the SCHOOL's crest and wordmark with its
        strapline on the left, then the bell with its unread badge, then the
        parent's photo under a "Hello," label with a chevron.

        THE SEARCH ICON IS GONE. It was never in the designs, and it was
        crowding the one row the header has on a 426px screen. `portal.search`
        is now reached from the account hub instead - it must be reached from
        somewhere, because PortalRouteWiringTest fails any portal route that
        nothing links to, and that test exists precisely because a screen
        nothing links to is a screen no parent can open.
    --}}
    <header class="shrink-0 bg-portal-green text-white">
        <div class="mx-auto w-full max-w-5xl px-4 pb-2 pt-3 sm:px-6">
            <div class="flex items-center gap-2.5">
                <a href="{{ route('portal.dashboard') }}" class="flex min-w-0 items-center gap-2">
                    <x-portal.crest size="md"/>

                    {{-- The wordmark is SPLIT, as the reference sets it: the
                         first word large, the remainder small beneath it, then
                         the strapline. Set on one line, "Heritage Bilingual
                         College" simply truncates to "HERITAGE BILINGUAL C..."
                         in the width a 426px header leaves. --}}
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate font-serif text-[0.95rem] font-bold uppercase tracking-[0.08em]">
                            {{ $portalSchoolLead }}
                        </span>

                        @if ($portalSchoolRest !== '')
                            <span class="block truncate text-[0.55rem] font-semibold uppercase tracking-[0.14em] text-portal-gold">
                                {{ $portalSchoolRest }}
                            </span>
                        @endif

                        <span class="block truncate text-[0.58rem] text-white/70">
                            {{ $portalStrapline }}
                        </span>
                    </span>
                </a>

                <div class="ml-auto flex shrink-0 items-center gap-1">
                    @if ($portalUser !== null)
                        <a href="{{ route('portal.notifications') }}"
                           class="relative flex h-9 w-9 items-center justify-center rounded-full hover:bg-white/10"
                           aria-label="{{ __('opes.guardian_portal.notifications_title') }}">
                            <x-portal.icon name="bell" bare size="md"/>

                            @if ($portalUnread > 0)
                                <span class="absolute right-0 top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-portal-gold px-1 text-[0.6rem] font-bold text-portal-green">
                                    {{ $portalUnread > 99 ? '99+' : $portalUnread }}
                                </span>
                            @endif
                        </a>

                        <a href="{{ route('portal.account') }}"
                           class="flex items-center gap-1.5 rounded-full py-0.5 pl-0.5 pr-1 hover:bg-white/10">
                            <x-portal.avatar :name="$portalUser->name" size="sm" tone="gold"
                                             :photo="route('portal.photo.self')"/>

                            <span class="hidden min-w-0 leading-tight sm:block">
                                <span class="block text-[0.62rem] text-white/70">
                                    {{ __('opes.guardian_portal.greeting') }}
                                </span>
                                <span class="block truncate text-[0.78rem] font-bold">{{ $portalUser->name }}</span>
                            </span>

                            <x-portal.icon name="chevron-down" bare size="sm" class="hidden text-white/70 sm:block"/>
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
                @foreach ($portalNav as [$routeName, $label, $icon, $params])
                    @php $isActive = str_starts_with($portalCurrent, $routeName); @endphp
                    <a href="{{ route($routeName, $params) }}"
                       @if ($isActive) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $isActive
                           ? 'bg-portal-green text-white'
                           : 'text-charcoal/70 hover:bg-portal-tint hover:text-primary' }}">
                        <x-portal.icon :name="$icon" bare size="md"/>
                        <span class="truncate">{{ $label }}</span>
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
        The bar from the designs, up to lg - which is where the sidebar takes
        over. Tablets keep it too: a thumb is still the pointing device at
        900px.

        DARK GREEN, not white. The dashboard reference happens to show a white
        bar, and building to that one screen would have been wrong: sampling
        the nav band across all 76 phone references gives 69 green against 5
        white. The dashboard is the outlier, so the green is the design and
        the white is the exception.

        Height is measured, not chosen: in the references the bar occupies CSS
        y 853..910 of a 923-tall screen, i.e. ~57px, which is what the padding
        below adds up to with a 20px icon and an 11px label.
    --}}
    <nav aria-label="{{ __('opes.guardian_portal.nav_label') }}"
         class="fixed inset-x-0 bottom-0 z-30 bg-portal-green pb-[env(safe-area-inset-bottom)] shadow-[0_-6px_24px_rgba(0,45,23,0.3)] lg:hidden">
        <div class="flex items-stretch">
            @foreach ($portalNav as [$routeName, $label, $icon, $params])
                @php $isActive = str_starts_with($portalCurrent, $routeName); @endphp
                <a href="{{ route($routeName, $params) }}"
                   @if ($isActive) aria-current="page" @endif
                   class="relative flex flex-1 flex-col items-center gap-1 px-0.5 pb-2 pt-2.5 text-[0.68rem] {{ $isActive
                       ? 'font-semibold text-portal-gold'
                       : 'text-white/65' }}">
                    {{-- The gold rule under the active item, as the designs
                         mark it. --}}
                    @if ($isActive)
                        <span class="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-portal-gold"
                              aria-hidden="true"></span>
                    @endif

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
