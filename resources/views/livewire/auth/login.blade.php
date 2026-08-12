{{--
    The desktop sign-in screen, built to the OPES artwork: a deep green field
    carrying the woven motif, the brand column with its six capability tiles,
    the school photograph behind a gold arc, and the sign-in card standing
    proud on the right over the trust bar.

    THREE THINGS THIS PAGE IS NOT ALLOWED TO CHANGE, and does not:

    1. The wire bindings. `authenticate`, `email`, `password`, `remember` and
       the demo block's `demoLogin()` are exactly as they were. This is a
       restyle; the authentication path is the last thing that should be
       edited as a side effect of a layout.

    2. The copy's neutrality. This is the platform's ONLY login - staff reach
       the back office through it - so nothing here addresses parents
       specifically. The reference's own subtitle is already role-neutral.

    3. The field's contract. The artwork labels the first field "Username or
       Email", but the component validates `required|email` and
       AuthenticateUser resolves an email address. Labelling it "Username"
       would promise a credential that is rejected on submit, so the label
       stays the honest `opes.auth.email`. Changing what the field ACCEPTS is
       an Identity-module decision, not a paint job.

    Below `lg` this collapses to the single-column stack the phone designs
    show: crest, heading, card. The brand column and photograph are desktop
    furniture and are hidden rather than reflowed - a 375px screen has no room
    for six capability tiles above the fold, and pushing the password field
    off-screen to show marketing copy would be a worse page, not a smaller one.
--}}
@php
    /*
     * The six capability tiles.
     *
     * `place` carries LITERAL grid-placement classes rather than interpolated
     * ones. Tailwind scans source text for complete class names, so a
     * generated `xl:col-start-{{ $n }}` compiles to nothing at all - the
     * utility is simply absent from the stylesheet, with no warning. The
     * placement is explicit because the reference puts the wide security
     * panel in the fourth column of the SECOND row only, which no auto-flow
     * arrangement produces.
     */
    $features = [
        ['icon' => 'user', 'label' => __('opes.auth.feature_students'), 'place' => 'xl:col-start-1 xl:row-start-1'],
        ['icon' => 'book', 'label' => __('opes.auth.feature_academics'), 'place' => 'xl:col-start-2 xl:row-start-1'],
        ['icon' => 'wallet', 'label' => __('opes.auth.feature_finance'), 'place' => 'xl:col-start-3 xl:row-start-1'],
        ['icon' => 'calendar', 'label' => __('opes.auth.feature_attendance'), 'place' => 'xl:col-start-1 xl:row-start-2'],
        ['icon' => 'file', 'label' => __('opes.auth.feature_exams'), 'place' => 'xl:col-start-2 xl:row-start-2'],
        ['icon' => 'chart', 'label' => __('opes.auth.feature_reports'), 'place' => 'xl:col-start-3 xl:row-start-2'],
    ];
@endphp

<div class="relative min-h-screen overflow-hidden bg-portal-green">

    {{-- ------------------------------------------------ background field -- --}}
    {{-- The green is not flat in the reference: it lifts towards the centre
         behind the photograph and deepens into all four corners. --}}
    <div class="pointer-events-none absolute inset-0
                bg-[radial-gradient(120%_100%_at_35%_20%,var(--color-portal-green-soft)_0%,var(--color-portal-green)_55%,#02180E_100%)]"
         aria-hidden="true"></div>

    {{-- The motif runs down the left margin and across the foot. --}}
    <div class="pointer-events-none absolute inset-y-0 left-0 hidden w-16 text-portal-gold opacity-[0.18] lg:block"
         aria-hidden="true">
        <x-portal.motif opacity="1"/>
    </div>

    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-14 text-portal-gold opacity-[0.22]"
         aria-hidden="true">
        <x-portal.motif opacity="1"/>
    </div>

    {{-- The two gold rules that close the foot of the artwork. --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-14 h-px bg-portal-gold/45" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-portal-gold/30" aria-hidden="true"></div>

    {{-- ------------------------------------------------------ photograph -- --}}
    {{--
        The school photograph, held behind a gold arc.

        Rendered ONLY when the asset is actually present. A missing <img> would
        otherwise leave a broken-image glyph in the middle of the sign-in page
        on every install that has not supplied one - and this file cannot
        assume a school has. Without it the arc closes over the green field,
        which is a composition in its own right rather than a hole.
    --}}
    @php
        $heroPath = public_path('images/login-hero.jpg');
        $hasHero = is_file($heroPath);
    @endphp

    {{-- `xl`, not `2xl`: the brand column and the card together clear 1280px
         with room for the photograph between them, and gating it at 1536
         meant almost no real laptop ever saw the centrepiece of the design. --}}
    <div class="pointer-events-none absolute inset-y-0 left-[24rem] right-[34rem] hidden overflow-hidden xl:block"
         aria-hidden="true">
        @if ($hasHero)
            <img src="{{ asset('images/login-hero.jpg') }}" alt=""
                 class="h-full w-full object-cover object-top
                        [clip-path:ellipse(78%_100%_at_78%_50%)]">
        @endif

        {{-- The arc itself: a gold hairline sweeping the photograph's left
             edge, drawn whether or not the photograph is there. --}}
        <svg class="absolute inset-0 h-full w-full" viewBox="0 0 100 100" fill="none"
             preserveAspectRatio="none">
            <path d="M22 0C4 26 4 74 22 100" stroke="var(--color-portal-gold)" stroke-width="0.6"
                  vector-effect="non-scaling-stroke" opacity="0.85"/>
            <path d="M25 0C7 26 7 74 25 100" stroke="var(--color-portal-gold)" stroke-width="0.4"
                  vector-effect="non-scaling-stroke" opacity="0.35"/>
        </svg>

        {{-- A green veil on the photograph's left, so the brand column's text
             keeps its contrast where the two meet. --}}
        <div class="absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-portal-green to-transparent"></div>
    </div>

    {{-- ========================================================= CONTENT == --}}
    <div class="relative z-10 mx-auto flex min-h-screen w-full max-w-[1600px] flex-col justify-center gap-10
                px-5 py-10 lg:flex-row lg:items-center lg:gap-12 lg:px-10 xl:px-16">

        {{-- ------------------------------------------- brand column (lg+) -- --}}
        <div class="hidden min-w-0 flex-1 lg:block">
            <x-portal.crest-mark size="lg" on="dark" class="text-white"/>

            <h1 class="mt-8 font-serif text-4xl font-bold uppercase leading-[1.15] tracking-tight text-white
                       xl:text-[2.6rem]">
                {{ __('opes.auth.hero_line_one') }}<br>
                {{ __('opes.auth.hero_line_two') }}<br>
                {{ __('opes.auth.hero_line_three') }}<br>
                <span class="text-portal-gold">{{ __('opes.auth.hero_line_four') }}</span>
            </h1>

            <span class="mt-6 block h-0.5 w-16 bg-portal-gold" aria-hidden="true"></span>

            <p class="mt-6 max-w-sm text-sm leading-relaxed text-white/75">
                {{ __('opes.auth.hero_body') }}
            </p>

            {{-- ------------------------------------------------- tiles -- --}}
            <div class="mt-8 grid max-w-3xl gap-3 sm:grid-cols-2 lg:grid-cols-3
                        xl:grid-cols-[repeat(3,minmax(0,11rem))_minmax(0,18rem)]">
                @foreach ($features as $feature)
                    <div class="{{ $feature['place'] }} flex items-center gap-3 rounded-xl border border-portal-gold/30
                                bg-white/[0.04] px-3.5 py-3">
                        <x-portal.icon :name="$feature['icon']" bare size="md" class="shrink-0 text-portal-gold"/>
                        <span class="text-xs font-medium leading-tight text-white/90">{{ $feature['label'] }}</span>
                    </div>
                @endforeach

                {{-- The wide security panel, fourth column of the second row. --}}
                <div class="flex items-start gap-3 rounded-xl border border-portal-gold/30 bg-white/[0.04] px-3.5 py-3
                            xl:col-start-4 xl:row-start-2">
                    <x-portal.icon name="shield" bare size="lg" class="shrink-0 text-portal-gold"/>

                    <span class="min-w-0">
                        <span class="block text-xs font-semibold text-white">{{ __('opes.auth.secure_title') }}</span>
                        <span class="mt-0.5 block text-[11px] leading-snug text-white/65">
                            {{ __('opes.auth.secure_body') }}
                        </span>
                    </span>
                </div>
            </div>

            {{-- ------------------------------------------------ contact -- --}}
            <div class="mt-10 flex flex-wrap items-center gap-x-8 gap-y-3 text-xs text-white/70">
                <span class="flex items-center gap-2">
                    <x-portal.icon name="pin" bare size="sm" class="text-portal-gold"/>
                    {{ config('opes.vendor.city') }}
                </span>

                <span class="hidden h-4 w-px bg-white/20 xl:block" aria-hidden="true"></span>

                <span class="flex items-center gap-2">
                    <x-portal.icon name="globe" bare size="sm" class="text-portal-gold"/>
                    {{ config('opes.vendor.website') }}
                </span>

                <span class="hidden h-4 w-px bg-white/20 xl:block" aria-hidden="true"></span>

                <span class="flex items-center gap-2">
                    <x-portal.icon name="phone" bare size="sm" class="text-portal-gold"/>
                    {{ config('opes.vendor.phone') }}
                </span>
            </div>
        </div>

        {{-- ================================================ sign-in card == --}}
        <div class="mx-auto w-full max-w-[30rem] shrink-0 lg:mx-0">

            <div class="relative rounded-3xl bg-portal-surface px-6 pb-8 pt-12 shadow-[0_30px_80px_rgba(0,0,0,0.35)]
                        sm:px-10">

                {{-- The gold tab riding the card's top edge. --}}
                <span class="absolute -top-px left-1/2 flex h-8 w-16 -translate-x-1/2 items-end justify-center
                             rounded-b-full bg-portal-gold pb-1.5"
                      aria-hidden="true">
                    <x-portal.icon name="shield" bare size="sm" class="text-portal-green"/>
                </span>

                <div class="flex flex-col items-center text-center">
                    <x-portal.crest-mark size="md" on="light" class="text-portal-green"/>

                    <h2 class="mt-4 text-3xl font-bold text-portal-green">{{ __('opes.auth.welcome_back') }}</h2>

                    <p class="mt-1.5 text-sm text-charcoal/65">
                        {{ __('opes.auth.welcome_sub', ['brand' => __('opes.shell.brand')]) }}
                    </p>
                </div>

                {{-- The error sits ABOVE the fields so a screen reader meets it
                     before them, and so it is visible without scrolling. --}}
                @if ($errors->any())
                    <div role="alert" aria-live="assertive"
                         class="mt-6 rounded-xl border-l-4 border-portal-danger bg-portal-danger-soft px-4 py-3
                                text-sm text-charcoal">
                        <ul class="list-none space-y-1">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form wire:submit="authenticate" class="mt-7 space-y-5" novalidate>
                    <div>
                        <label for="email" class="mb-2 block text-sm font-semibold text-charcoal">
                            {{ __('opes.auth.email') }}
                        </label>

                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center
                                         justify-center border-r border-border-primary text-charcoal/35">
                                <x-portal.icon name="user" bare size="md"/>
                            </span>

                            <input id="email" name="email" type="email" wire:model="email"
                                   autocomplete="username" required autofocus
                                   @error('email') aria-invalid="true" @enderror
                                   class="block w-full rounded-xl border border-border-primary bg-white py-3.5 pl-15 pr-4
                                          text-charcoal placeholder:text-charcoal/35
                                          focus:border-primary focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <label for="password" class="block text-sm font-semibold text-charcoal">
                                {{ __('opes.auth.password') }}
                            </label>

                            <a href="{{ route('portal.entry', 'reset') }}"
                               class="text-sm font-semibold text-portal-gold-deep hover:underline">
                                {{ __('opes.guardian_portal.auth_forgot') }}
                            </a>
                        </div>

                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center
                                         justify-center border-r border-border-primary text-charcoal/35">
                                <x-portal.icon name="shield" bare size="md"/>
                            </span>

                            <input id="password" name="password" type="password" wire:model="password"
                                   autocomplete="current-password" required
                                   class="block w-full rounded-xl border border-border-primary bg-white py-3.5 pl-15 pr-4
                                          text-charcoal placeholder:text-charcoal/35
                                          focus:border-primary focus:outline-none">
                        </div>
                    </div>

                    <label for="remember" class="flex items-center gap-2.5 text-sm text-charcoal">
                        <input id="remember" name="remember" type="checkbox" wire:model="remember"
                               class="h-4 w-4 rounded border-border-primary text-primary">
                        {{ __('opes.auth.remember') }}
                    </label>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-portal-green px-4 py-4
                                   text-base font-semibold text-white hover:brightness-125">
                        <x-portal.icon name="shield" bare size="sm"/>
                        {{ __('opes.auth.sign_in') }}
                    </button>
                </form>

                {{-- The shield-on-a-rule divider. --}}
                <div class="mt-7 flex items-center gap-4" aria-hidden="true">
                    <span class="h-px flex-1 bg-portal-gold/45"></span>
                    <x-portal.icon name="shield" bare size="md" class="text-charcoal/35"/>
                    <span class="h-px flex-1 bg-portal-gold/45"></span>
                </div>

                <p class="mt-5 text-center text-sm text-charcoal/70">
                    {{ __('opes.auth.contact_admin_prompt') }}
                    <span class="font-bold text-portal-green">{{ __('opes.auth.contact_admin') }}</span>
                </p>

                {{-- 00-core 9.3: no SMTP in most schools, so no self-service
                     reset link. Say so plainly rather than offering a link
                     that would never arrive. --}}
                <p class="mt-3 text-center text-xs leading-relaxed text-charcoal/55">
                    {{ __('opes.auth.forgot_help') }}
                </p>

                {{-- One-click demo sign-in. Rendered only when the component
                     says it is available, which requires BOTH the config flag
                     and the local environment - see config/opes.php. On any
                     real deployment this whole block is absent from the HTML,
                     not merely hidden by CSS. --}}
                @if ($this->demoLoginAvailable())
                    <div class="mt-6 rounded-2xl border border-dashed border-portal-gold bg-portal-gold/10 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/70">
                            {{ __('opes.auth.demo_heading') }}
                        </p>
                        <p class="mt-1 text-xs text-charcoal/70">{{ __('opes.auth.demo_choose_role') }}</p>

                        {{-- One button per configured identity. Each signs in as
                             a REAL user holding that role through Spatie, so
                             what the visitor then sees is the product's own
                             permission checks answering - not a demo mode. --}}
                        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($this->demoIdentities() as $identity)
                                <button type="button" wire:click="demoLogin('{{ $identity['key'] }}')"
                                        wire:key="demo-{{ $identity['key'] }}"
                                        class="rounded-xl bg-portal-green px-3 py-2.5 text-left text-sm font-semibold
                                               text-white hover:brightness-125">
                                    {{ __('opes.auth.demo_sign_in_as', ['role' => $identity['label']]) }}
                                </button>
                            @endforeach
                        </div>

                        <p class="mt-2 text-xs text-charcoal/60">{{ __('opes.auth.demo_help') }}</p>
                        <p class="mt-1 text-xs text-charcoal/60">{{ __('opes.auth.demo_rbac_help') }}</p>
                    </div>
                @endif
            </div>

            {{-- ------------------------------------------------ trust bar -- --}}
            <div class="mt-4 flex items-center justify-center gap-3 rounded-2xl border border-portal-gold/40
                        bg-portal-green px-5 py-4">
                <x-portal.icon name="shield" bare size="md" class="shrink-0 text-portal-gold"/>
                <span class="text-sm font-medium text-white/90">{{ __('opes.auth.trust_line') }}</span>
            </div>
        </div>
    </div>
</div>
