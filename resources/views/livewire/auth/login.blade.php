{{--
    The desktop sign-in screen, built to the OPES artwork.

    MEASURED off the reference at 1536x1024 rather than eyeballed, because the
    first attempt got the arrangement right and the PROPORTIONS wrong, which
    is what made it read as a different page:

      left gutter        72px          card            x 920-1483 (563 wide)
      crest             215px wide     card padding    42px
      headline           30/41px       inputs          462x51, 12px radius
      gold rule          68x3px        sign-in button  462x52
      tile               158x77px      trust bar       460x57, overlapping
      security tile     290px wide     page bottom     60px ornament band

    Note the root font-size is 17px (resources/css/app.css), so a rem here is
    17 CSS pixels, not 16. Every arbitrary value below is already converted -
    max-w-[33rem] is the 563px card, not 528px. Getting that backwards is what
    would silently inflate the whole composition by 6%.

    THE MATERIAL IS GLASS. Not decoration: the reference's panels are frosted
    and translucent - the green field and the photograph read THROUGH the
    tiles and the card edges. Solid fills at the same colours look flat and
    wrong next to it, which is exactly how the first attempt failed. Hence
    backdrop-blur with low-alpha fills and a specular top highlight on every
    raised surface, rather than opaque backgrounds.

    THREE THINGS THIS PAGE DOES NOT CHANGE:

    1. The wire bindings. `authenticate`, `email`, `password`, `remember` and
       the demo block's `demoLogin()` are exactly as they were. This is a
       restyle; the authentication path is the last thing that should be
       edited as a side effect of a layout.

    2. The copy's neutrality. This is the platform's ONLY login - staff reach
       the back office through it - so nothing here addresses parents
       specifically.

    3. What the credential field ACCEPTS. The artwork's "Username or Email"
       label is used as drawn, but `users` has no username column and
       AuthenticateUser resolves an email address, so today only an email
       signs in. Making the label true is a schema change in Identity, not a
       paint job, and is flagged rather than faked here.

    Below `lg` this collapses to the single-column card the phone designs
    show. The brand column and photograph are desktop furniture and are hidden
    rather than reflowed - a 375px screen has no room for six capability tiles
    above the fold, and pushing the password field off-screen to show
    marketing copy would be a worse page, not a smaller one.
--}}
@php
    /*
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

    /*
     * The photograph is rendered ONLY when the asset is actually there. A
     * missing <img> would otherwise leave a broken-image glyph in the middle
     * of the sign-in page on every install that has not supplied one, and
     * this file cannot assume a school has. The gold arc is drawn either way,
     * so its absence reads as a composition rather than a hole.
     */
    $hasHero = is_file(public_path('images/login-hero.jpg'));
@endphp

<div class="relative min-h-screen overflow-hidden bg-portal-green">

    {{-- ============================================== BACKGROUND FIELD == --}}
    {{-- The green is not flat: it lifts behind the photograph and deepens
         into every corner. --}}
    <div class="pointer-events-none absolute inset-0
                bg-[radial-gradient(130%_110%_at_42%_18%,#0F4630_0%,#0A3121_42%,#041D12_100%)]"
         aria-hidden="true"></div>

    {{-- A whisper of the motif across the field. It was at 0.07 with a 90px
         tile, which rendered as a busy lattice of large diamonds over the
         whole page - the reference's field is essentially CLEAN, carrying the
         weave only in the left margin and the footer course. Halved and
         finer, it now reads as texture rather than pattern. --}}
    <div class="pointer-events-none absolute inset-0 text-portal-gold opacity-[0.035]" aria-hidden="true">
        <x-portal.motif scale="64" opacity="1"/>
    </div>

    {{-- The dense left margin band. --}}
    <div class="pointer-events-none absolute inset-y-0 left-0 hidden w-[3.4rem] text-portal-gold opacity-25 lg:block"
         aria-hidden="true">
        <x-portal.motif scale="34" opacity="1"/>
    </div>

    {{-- The ornament course along the foot, closed by two gold rules. --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-[3.5rem] text-portal-gold opacity-40"
         aria-hidden="true">
        <x-portal.motif scale="28" opacity="1"/>
    </div>
    <div class="pointer-events-none absolute inset-x-0 bottom-[3.5rem] h-px bg-portal-gold/50" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-portal-gold/35" aria-hidden="true"></div>

    {{-- The quarter-arc that turns the bottom-left corner. --}}
    <svg class="pointer-events-none absolute bottom-0 left-0 hidden h-64 w-64 lg:block" viewBox="0 0 100 100"
         fill="none" aria-hidden="true">
        <path d="M100 100A100 100 0 0 1 0 0" stroke="var(--color-portal-gold)" stroke-width="0.7"
              vector-effect="non-scaling-stroke" opacity="0.45"/>
    </svg>

    {{-- ================================================== PHOTOGRAPH === --}}
    {{-- `xl`, not `2xl`: the brand column and the card together clear 1280px
         with room for the photograph between them, and gating it at 1536
         meant almost no real laptop saw the centrepiece of the design. --}}
    <div class="pointer-events-none absolute inset-y-0 left-[26rem] right-[31rem] hidden overflow-hidden xl:block"
         aria-hidden="true">
        @if ($hasHero)
            {{-- Clipped by a wide ellipse anchored off the right edge, which
                 is what produces the reference's single sweeping arc down the
                 photograph's left side. --}}
            {{-- clip-path as an INLINE STYLE, not a Tailwind arbitrary value.
                 `[clip-path:ellipse(88%_90%_at_100%_50%)]` did not survive
                 compilation - the percentages inside the function are not
                 emitted - so the photograph rendered as a hard-edged rectangle
                 with the arc floating uselessly beside it. Inline, it is not
                 something a build step can silently drop. --}}
            <img src="{{ asset('images/login-hero.jpg') }}" alt=""
                 style="clip-path: ellipse(88% 90% at 100% 50%)"
                 class="h-full w-full object-cover object-top">
        @endif

        {{-- The arc itself, drawn whether or not the photograph is there. --}}
        <svg class="absolute inset-0 h-full w-full" viewBox="0 0 100 100" fill="none"
             preserveAspectRatio="none">
            {{-- ry 90, not 128. At 128 the ellipse is so tall that the arc
                 reads as a straight vertical line over a 1024px viewport - it
                 bulged 40px where the reference sweeps about 100. Shortening
                 the vertical radius is what puts the curve back. --}}
            <ellipse cx="100" cy="50" rx="88" ry="90" stroke="var(--color-portal-gold)" stroke-width="1.6"
                     vector-effect="non-scaling-stroke" opacity="0.9"/>
            <ellipse cx="103" cy="50" rx="88" ry="90" stroke="var(--color-portal-gold)" stroke-width="0.6"
                     vector-effect="non-scaling-stroke" opacity="0.35"/>
        </svg>

        {{-- A green veil where the photograph meets the brand column, so the
             headline keeps its contrast.

             ONLY when there is a photograph. Painted unconditionally it laid
             flat #012A17 over the radial field, and because the two greens
             differ at that point it drew a hard vertical seam straight down
             the page - a line the reference does not have, and the most
             visible flaw in the render. With no photograph there is nothing
             to veil. --}}
        @if ($hasHero)
            <div class="absolute inset-y-0 left-0 w-2/5 bg-gradient-to-r from-portal-green via-portal-green/70 to-transparent"></div>
        @endif
    </div>

    {{-- ===================================================== CONTENT === --}}
    {{-- `lg:items-stretch`, not `items-center`. In the reference the brand
         column SPANS the viewport - crest at the top edge, contact strip at
         the foot - and centring it as a block instead floated the whole
         column into the middle and pushed the contact strip off the bottom of
         the page. The card is centred within its own column below. --}}
    <div class="relative z-10 mx-auto flex min-h-screen w-full max-w-[94rem] flex-col justify-center gap-10
                px-5 py-10 lg:flex-row lg:items-stretch lg:justify-between lg:gap-10 lg:px-[4.25rem]">

        {{-- ------------------------------------------ brand column (lg+) -- --}}
        <div class="hidden min-w-0 shrink-0 lg:flex lg:flex-col lg:justify-between">
            {{-- TWO groups, not three. In the reference the crest, headline,
                 rule, body and tiles are one CONTINUOUS block running from the
                 top edge down, with only the contact strip pushed to the foot.
                 Spacing the crest apart from the headline as its own group
                 opened a gap the design does not have. --}}
            <div>
            <x-portal.crest-mark size="xl" on="dark" class="text-white"/>

            <div class="mt-9">
            <h1 class="font-serif text-[1.75rem] font-bold uppercase leading-[1.38] tracking-[0.01em] text-white">
                {{ __('opes.auth.hero_line_one') }}<br>
                {{ __('opes.auth.hero_line_two') }}<br>
                {{ __('opes.auth.hero_line_three') }}<br>
                <span class="text-portal-gold">{{ __('opes.auth.hero_line_four') }}</span>
            </h1>

            <span class="mt-7 block h-[3px] w-[4rem] bg-portal-gold" aria-hidden="true"></span>

            <p class="mt-7 max-w-[19rem] text-[0.92rem] leading-[1.62] text-white/80">
                {{ __('opes.auth.hero_body') }}
            </p>

            {{-- ------------------------------------------------- tiles -- --}}
            <div class="mt-9 grid gap-4 sm:grid-cols-2 lg:grid-cols-3
                        xl:grid-cols-[repeat(3,minmax(0,9.3rem))_minmax(0,17rem)]">
                @foreach ($features as $feature)
                    {{-- Frosted glass, not a solid panel: the green field has
                         to read through it, which is what the reference's
                         tiles do. --}}
                    <div class="{{ $feature['place'] }} relative flex items-center gap-3 overflow-hidden rounded-xl
                                border border-portal-gold/35 bg-white/[0.07] px-3.5 py-4 backdrop-blur-md">
                        {{-- Specular highlight along the top edge - the tell
                             that a surface is glass rather than paint. --}}
                        <span class="pointer-events-none absolute inset-x-0 top-0 h-px bg-white/25" aria-hidden="true"></span>

                        <x-portal.icon :name="$feature['icon']" bare size="lg" class="shrink-0 text-portal-gold"/>
                        <span class="text-[0.8rem] font-medium leading-[1.35] text-white/95">{{ $feature['label'] }}</span>
                    </div>
                @endforeach

                {{-- The wide security panel: fourth column, SECOND row. --}}
                <div class="relative flex items-start gap-3 overflow-hidden rounded-xl border border-portal-gold/35
                            bg-white/[0.07] px-4 py-4 backdrop-blur-md xl:col-start-4 xl:row-start-2">
                    <span class="pointer-events-none absolute inset-x-0 top-0 h-px bg-white/25" aria-hidden="true"></span>

                    <x-portal.icon name="shield" bare size="xl" class="shrink-0 text-portal-gold"/>

                    <span class="min-w-0">
                        <span class="block text-[0.85rem] font-bold text-white">{{ __('opes.auth.secure_title') }}</span>
                        <span class="mt-1 block text-[0.72rem] leading-[1.45] text-white/70">
                            {{ __('opes.auth.secure_body') }}
                        </span>
                    </span>
                </div>
            </div>{{-- tiles grid --}}
            </div>{{-- headline + rule + body + tiles --}}
            </div>{{-- top group: crest through tiles --}}

            {{-- ------------------------------------------------ contact -- --}}
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-[0.8rem] text-white/80">
                <span class="flex items-center gap-2">
                    <x-portal.icon name="pin" bare size="sm" class="text-portal-gold"/>
                    {{ config('opes.vendor.city') }}
                </span>

                <span class="hidden h-5 w-px bg-white/25 xl:block" aria-hidden="true"></span>

                <span class="flex items-center gap-2">
                    <x-portal.icon name="globe" bare size="sm" class="text-portal-gold"/>
                    {{ config('opes.vendor.website') }}
                </span>

                <span class="hidden h-5 w-px bg-white/25 xl:block" aria-hidden="true"></span>

                <span class="flex items-center gap-2">
                    <x-portal.icon name="phone" bare size="sm" class="text-portal-gold"/>
                    {{ config('opes.vendor.phone') }}
                </span>
            </div>
        </div>

        {{-- ================================================ SIGN-IN CARD == --}}
        {{-- `pb-10` on the wrapper leaves the trust bar room to sit beneath the
             card while overlapping its lower corner, as it does in the
             reference. --}}
        {{-- `justify-center` centres the card in its own full-height column,
             which is what the stretched row above gives up. --}}
        <div class="relative mx-auto flex w-full max-w-[33rem] shrink-0 flex-col justify-center lg:mx-0">

            <div class="relative rounded-[1.6rem] border border-white/60 bg-white/90 px-6 pb-10 pt-14
                        shadow-[0_45px_120px_-25px_rgba(0,0,0,0.6)] backdrop-blur-2xl sm:px-[2.5rem]">

                {{-- The specular sheen across the card's crown. --}}
                <span class="pointer-events-none absolute inset-x-0 top-0 h-40 rounded-t-[1.6rem]
                             bg-gradient-to-b from-white/70 to-transparent"
                      aria-hidden="true"></span>

                {{-- The gold tab riding the card's top edge. --}}
                <span class="absolute -top-px left-1/2 flex h-[2.2rem] w-[5.9rem] -translate-x-1/2 items-end
                             justify-center rounded-b-full bg-gradient-to-b from-portal-gold to-portal-gold-deep pb-2
                             shadow-[0_6px_16px_rgba(0,0,0,0.25)]"
                      aria-hidden="true">
                    <x-portal.icon name="shield" bare size="sm" class="text-portal-green"/>
                </span>

                <div class="relative flex flex-col items-center text-center">
                    <x-portal.crest-mark size="lg" on="light" class="text-portal-green"/>

                    <h2 class="mt-5 text-[1.85rem] font-bold leading-none text-portal-green">
                        {{ __('opes.auth.welcome_back') }}
                    </h2>

                    <p class="mt-2.5 text-[0.9rem] text-charcoal/65">
                        {{ __('opes.auth.welcome_sub', ['brand' => __('opes.shell.brand')]) }}
                    </p>
                </div>

                {{-- The error sits ABOVE the fields so a screen reader meets it
                     before them, and so it is visible without scrolling. --}}
                @if ($errors->any())
                    <div role="alert" aria-live="assertive"
                         class="relative mt-7 rounded-xl border-l-4 border-portal-danger bg-portal-danger-soft px-4 py-3
                                text-sm text-charcoal">
                        <ul class="list-none space-y-1">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form wire:submit="authenticate" class="relative mt-8 space-y-5" novalidate>
                    <div>
                        <label for="email" class="mb-2.5 block text-[0.88rem] font-semibold text-charcoal">
                            {{ __('opes.auth.credential') }}
                        </label>

                        <div class="relative">
                            {{--
                                `z-10` IS LOAD-BEARING here, not tidiness.

                                `backdrop-blur` on the input below creates a
                                stacking context, promoting the input into the
                                positioned-descendant paint layer at z-0. This
                                icon is absolutely positioned at z-auto and
                                comes FIRST in the DOM, so without the lift the
                                input paints over it and its own bg-white/70
                                veils the glyph. The icons rendered as pale
                                ghosts while their computed colours were
                                perfectly correct - which is why reading the
                                CSS proved nothing and only looking at the
                                pixels found it.

                                Any glass surface with an icon laid over it
                                needs the same lift.
                            --}}
                            <span class="pointer-events-none absolute inset-y-0 left-0 z-10 flex w-[3.1rem] items-center
                                         justify-center text-portal-green/45">
                                <x-portal.icon name="user" bare size="md"/>
                            </span>

                            <input id="email" name="email" type="email" wire:model="email"
                                   placeholder="{{ __('opes.auth.credential_placeholder') }}"
                                   autocomplete="username" required autofocus
                                   @error('email') aria-invalid="true" @enderror
                                   class="block h-[3rem] w-full rounded-xl border border-border-primary bg-white/70 pl-[3.1rem]
                                          pr-4 text-[0.95rem] text-charcoal placeholder:text-charcoal/40 backdrop-blur
                                          focus:border-primary focus:bg-white focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <div class="mb-2.5 flex items-center justify-between gap-3">
                            <label for="password" class="block text-[0.88rem] font-semibold text-charcoal">
                                {{ __('opes.auth.password') }}
                            </label>

                            <a href="{{ route('portal.entry', 'reset') }}"
                               class="text-[0.85rem] font-medium text-primary hover:underline">
                                {{ __('opes.auth.forgot_short') }}
                            </a>
                        </div>

                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 z-10 flex w-[3.1rem] items-center
                                         justify-center text-portal-green/70">
                                <x-portal.icon name="lock" bare size="md"/>
                            </span>

                            <input id="password" name="password" type="password" wire:model="password"
                                   placeholder="{{ __('opes.auth.password_placeholder') }}"
                                   autocomplete="current-password" required
                                   class="block h-[3rem] w-full rounded-xl border border-border-primary bg-white/70 pl-[3.1rem]
                                          pr-4 text-[0.95rem] text-charcoal placeholder:text-charcoal/40 backdrop-blur
                                          focus:border-primary focus:bg-white focus:outline-none">
                        </div>
                    </div>

                    <label for="remember" class="flex items-center gap-2.5 pt-1 text-[0.88rem] text-charcoal">
                        <input id="remember" name="remember" type="checkbox" wire:model="remember"
                               class="h-[1.05rem] w-[1.05rem] rounded border-border-primary text-primary">
                        {{ __('opes.auth.remember_short') }}
                    </label>

                    <button type="submit"
                            class="mt-2 flex h-[3.1rem] w-full items-center justify-center gap-2.5 rounded-xl
                                   bg-gradient-to-b from-portal-green-soft to-portal-green text-[1rem] font-bold
                                   text-white shadow-[0_10px_28px_-8px_rgba(1,42,23,0.75)] hover:brightness-125">
                        <x-portal.icon name="lock" bare size="sm"/>
                        {{ __('opes.auth.sign_in_title') }}
                    </button>
                </form>

                {{-- The shield-on-a-rule divider. --}}
                <div class="relative mt-8 flex items-center gap-4" aria-hidden="true">
                    <span class="h-px flex-1 bg-portal-gold/55"></span>
                    <x-portal.icon name="shield" bare size="md" class="text-portal-green/45"/>
                    <span class="h-px flex-1 bg-portal-gold/55"></span>
                </div>

                <p class="relative mt-6 text-center text-[0.9rem] text-charcoal/70">
                    {{ __('opes.auth.contact_admin_prompt') }}
                    <span class="font-bold text-portal-green">{{ __('opes.auth.contact_admin') }}</span>
                </p>

            </div>

            {{-- ------------------------------------------------ trust bar -- --}}
            {{-- Overlaps the card's foot and is inset from its edges, exactly
                 as the reference has it - not a separate block below. --}}
            <div class="relative z-10 mx-auto -mt-6 flex w-[calc(100%-6rem)] items-center gap-3 rounded-xl
                        border border-portal-gold/50 bg-portal-green/90 px-4 py-3
                        shadow-[0_18px_40px_-12px_rgba(0,0,0,0.6)] backdrop-blur-xl">
                {{-- 0.78rem, not 0.85: at the larger size the line wraps
                     inside a 460px bar and the bar grows to 75px against the
                     reference's 57. The design's bar is one line. --}}
                <x-portal.icon name="shield" bare size="md" class="shrink-0 text-portal-gold"/>
                <span class="text-[0.72rem] font-medium leading-tight text-white/95">
                    {{ __('opes.auth.trust_line') }}
                </span>
            </div>

            {{--
                00-core 9.3: most Cameroonian schools have no SMTP server, so
                there is deliberately no self-service password reset. The page
                must SAY so rather than offer a link that would never arrive,
                and LoginTest asserts this line is present - it caught its
                removal when the card was trimmed to the reference's height.

                It sits on the green field below the card rather than inside
                it: the artwork's card ends at "Contact your administrator",
                and the sentence is a standing product fact, not part of the
                sign-in form.
            --}}
            <p class="mt-4 px-4 text-center text-[0.72rem] leading-relaxed text-white/55">
                {{ __('opes.auth.forgot_help') }}
            </p>

            {{--
                One-click demo sign-in, BELOW the card rather than inside it.

                It is not part of the design, and while it sat inside the card
                it inflated a panel the reference fixes at 847px to 1276px -
                so the one machine anybody actually looks at the page on was
                the one machine where the proportions were wrong.

                Rendered only when the component says it is available, which
                requires BOTH the config flag and the local environment (see
                config/opes.php). On any real deployment this block is absent
                from the HTML, not merely hidden by CSS - and the card is then
                the only thing here, exactly as drawn.
            --}}
            @if ($this->demoLoginAvailable())
                <div class="mt-4 rounded-2xl border border-dashed border-portal-gold/60 bg-portal-green/70 p-4
                            backdrop-blur-xl">
                    <p class="text-xs font-semibold uppercase tracking-wide text-portal-gold">
                        {{ __('opes.auth.demo_heading') }}
                    </p>
                    <p class="mt-1 text-xs text-white/70">{{ __('opes.auth.demo_choose_role') }}</p>

                    {{-- One OPTION per configured identity. Each signs in as a
                         REAL user holding that role through Spatie, so what the
                         visitor then sees is the product's own permission checks
                         answering - not a demo mode.

                         A dropdown rather than a grid of buttons: the identity
                         list is configuration and grows, and a button per role
                         made the block taller than the sign-in form it sits
                         under. One row stays one row however many roles are
                         configured. --}}
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <label for="demo-role" class="sr-only">{{ __('opes.auth.demo_choose_role') }}</label>

                        <select id="demo-role" wire:model="demoRole"
                                class="min-w-0 flex-1 rounded-xl border-0 bg-white/10 px-3 py-2.5 text-sm font-semibold
                                       text-white ring-1 ring-portal-gold/30 focus:outline-none focus:ring-portal-gold">
                            @foreach ($this->demoIdentities() as $identity)
                                {{-- The option's own colours are set explicitly:
                                     a native dropdown list renders on the
                                     system's popup surface, not the glass
                                     panel, so white-on-transparent inherited
                                     from the select would be white on white. --}}
                                <option value="{{ $identity['key'] }}" class="bg-portal-green text-white">
                                    {{ $identity['label'] }}
                                </option>
                            @endforeach
                        </select>

                        <button type="button" wire:click="demoLoginSelected"
                                class="shrink-0 rounded-xl bg-portal-gold px-4 py-2.5 text-sm font-bold text-portal-green
                                       hover:brightness-110">
                            {{ __('opes.auth.sign_in_title') }}
                        </button>
                    </div>

                    <p class="mt-2 text-xs text-white/60">{{ __('opes.auth.demo_help') }}</p>
                    <p class="mt-1 text-xs text-white/60">{{ __('opes.auth.demo_rbac_help') }}</p>
                </div>
            @endif
        </div>
    </div>
</div>
