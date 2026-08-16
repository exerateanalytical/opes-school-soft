{{--
    `/portal` - built to mobile/parent-dashboard.png, in the design's own
    order: greeting with the date chip beside it, the children rail, the four
    overview tiles, the unread strip, the two half-width panels, quick
    actions, safety banner.

    DENSITY IS THE POINT OF THIS FILE. The previous version was a desktop
    layout being viewed at phone width: two large cards per row where the
    design runs four compact tiles, and a page 2.5x the reference's height, so
    everything from the unread strip down fell off the screen. The reference
    fits the whole dashboard in one 426x923 screen, and these sizes are what
    that costs.

    They are written as explicit rem values rather than utility steps because
    the design's tiles land between Tailwind's stops - a 90px tile is neither
    w-20 nor w-24 - and rounding each one to the nearest step is how a row of
    four drifts by a whole card's width.

    Check changes with tools/design-parity, not by eye: reference left, built
    right, at the same pixel size.
--}}
@php
    /*
     * The overview tiles' icon colour is FIXED PER METRIC in the design -
     * green, gold, blue, purple, left to right - and does not move with the
     * value. The component's `tone` is a STATUS (danger once fees are owed),
     * and the design carries that on the VALUE instead: the fees tile shows a
     * blue icon above a red figure.
     *
     * Keyed by position, because the tiles arrive in a fixed order and two of
     * them share the `chart` icon, so an icon-name map would collide.
     */
    $tileAccents = ['bg-portal-green', 'bg-portal-gold', 'bg-badge-blue', 'bg-badge-purple'];

    $valueInk = [
        'danger' => 'text-portal-danger',
        'warning' => 'text-charcoal',
        'success' => 'text-charcoal',
        'primary' => 'text-charcoal',
    ];

    $captionInk = [
        'danger' => 'text-charcoal/50',
        'warning' => 'text-portal-gold-deep',
        'success' => 'text-portal-success',
        'primary' => 'text-portal-success',
    ];
@endphp

<div class="min-w-0 space-y-4">

    {{-- ---------------------------------------------- greeting + date -- --}}
    {{-- One row: the heading takes what it needs and the date chip sits beside
         it. Stacked, as this was, it cost 60px of the fold. --}}
    <div class="flex items-start justify-between gap-3 pt-1">
        <div class="min-w-0">
            <h1 class="text-[1.15rem] font-bold leading-tight text-charcoal">
                {{ __('opes.guardian_portal.dashboard_welcome', ['name' => $guardianName]) }}
            </h1>
            <p class="mt-0.5 text-[0.78rem] text-charcoal/60">
                {{ __('opes.guardian_portal.dashboard_today') }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2 rounded-xl border border-border-primary bg-white px-2.5 py-1.5 shadow-[0_1px_4px_rgba(0,45,23,0.05)]">
            <x-portal.icon name="calendar" bare size="sm" class="text-primary"/>
            <span class="leading-tight">
                <span class="block text-[0.62rem] text-charcoal/55">{{ now()->translatedFormat('l') }}</span>
                <span class="block text-[0.72rem] font-bold text-charcoal">{{ now()->translatedFormat('j M Y') }}</span>
            </span>
        </div>
    </div>

    {{-- ------------------------------------------------------ children -- --}}
    <div class="space-y-2">
        <h2 class="text-[0.92rem] font-bold text-primary">{{ __('opes.guardian_portal.dashboard_title') }}</h2>

        @if ($children === [])
            <x-portal.card>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.no_children') }}</p>
            </x-portal.card>
        @else
            <div class="-mx-4 flex snap-x gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0">
                @foreach ($children as $child)
                    <a wire:key="dash-child-{{ $child['id'] }}"
                       href="{{ route('portal.children.profile', $child['id']) }}"
                       @class([
                           'relative w-[6.9rem] shrink-0 snap-start rounded-xl border p-2',
                           'border-primary bg-portal-selected' => $loop->first,
                           'border-border-primary bg-white' => ! $loop->first,
                       ])>
                        @if ($loop->first)
                            <x-portal.icon name="chevron-right" bare size="sm"
                                           class="absolute right-1.5 top-1.5 text-primary"/>
                        @endif

                        <x-portal.avatar :name="$child['name']" size="md" tone="green"
                                         :photo="route('portal.photo.child', $child['id'])"/>

                        <p class="mt-1.5 truncate text-[0.72rem] font-semibold leading-tight text-charcoal">
                            {{ $child['name'] }}
                        </p>
                        <p class="truncate text-[0.65rem] text-charcoal/55">
                            {{ $child['class'] ?? $child['matricule'] }}
                        </p>

                        <span class="mt-1 inline-block rounded-md bg-portal-chip px-1.5 py-px text-[0.6rem] font-semibold text-portal-success">
                            {{ __('opes.guardian_portal.status_active') }}
                        </span>
                    </a>
                @endforeach

                <a href="{{ route('portal.children.index') }}"
                   class="flex w-[4.2rem] shrink-0 snap-start flex-col items-center justify-center gap-1 rounded-xl border border-border-primary bg-portal-tint p-2 text-center">
                    <x-portal.icon name="users" bare size="md" class="text-primary"/>
                    <span class="text-[0.6rem] font-semibold leading-tight text-primary">
                        {{ __('opes.guardian_portal.dashboard_view_all_children') }}
                    </span>
                </a>
            </div>
        @endif
    </div>

    {{-- ------------------------------------------------------ overview -- --}}
    @if ($tiles !== [])
        <div class="space-y-2">
            <h2 class="text-[0.92rem] font-bold text-primary">{{ __('opes.guardian_portal.dashboard_overview') }}</h2>

            {{-- Four across, scrolling if they will not fit, as the design has
                 it. The 2-col grid of large cards this replaces is what pushed
                 everything below it off the screen. --}}
            <div class="-mx-4 flex gap-[0.28rem] overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0">
                @foreach ($tiles as $tile)
                    <div wire:key="tile-{{ $loop->index }}"
                         class="flex w-[5.81rem] shrink-0 flex-col items-center rounded-xl border border-border-primary bg-white px-1.5 py-2.5 text-center">
                        <span class="flex h-[1.5625rem] w-[1.5625rem] items-center justify-center rounded-full text-white {{ $tileAccents[$loop->index % 4] }}">
                            <x-portal.icon :name="$tile['icon']" bare size="sm"/>
                        </span>

                        <span class="mt-1.5 block w-full truncate text-[0.6rem] leading-tight text-charcoal/70">
                            {{ $tile['label'] }}
                        </span>

                        <span class="mt-1 block text-[0.95rem] font-bold leading-none {{ $valueInk[$tile['tone']] ?? 'text-charcoal' }}">
                            {{ $tile['value'] }}
                        </span>

                        @if ($tile['caption'] !== null)
                            <span class="mt-1 block w-full truncate text-[0.58rem] {{ $captionInk[$tile['tone']] ?? 'text-charcoal/50' }}">
                                {{ $tile['caption'] }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------- unread messages -- --}}
    @if ($unreadMessages > 0)
        <div class="flex items-center gap-2.5 rounded-xl border border-portal-gold/45 bg-gold-100 px-2.5 py-2.5">
            <span class="flex h-[1.6875rem] w-[1.6875rem] shrink-0 items-center justify-center rounded-full bg-portal-gold text-white">
                <x-portal.icon name="bell" bare size="sm"/>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-[0.78rem] font-bold text-charcoal">
                    {{ __('opes.guardian_portal.dashboard_unread', ['count' => $unreadMessages]) }}
                </p>
                <p class="truncate text-[0.65rem] text-charcoal/60">
                    {{ __('opes.guardian_portal.dashboard_check_inbox') }}
                </p>
            </div>

            <a href="{{ route('portal.messages') }}"
               class="flex shrink-0 items-center gap-1 rounded-lg bg-white px-2 py-1.5 text-[0.68rem] font-bold text-charcoal shadow-sm">
                {{ __('opes.guardian_portal.dashboard_view_messages') }}
                <x-portal.icon name="chevron-right" bare size="sm"/>
            </a>
        </div>
    @endif

    {{-- ---------------------------------------- messages + activities -- --}}
    {{-- TWO COLUMNS AT PHONE WIDTH. Unusual, and deliberate: the reference
         puts these side by side at 426px, and stacking them costs another
         screen of height. --}}
    <div class="grid grid-cols-2 gap-2">
        <div class="rounded-xl border border-border-primary bg-white">
            <div class="flex items-center justify-between gap-1 px-2.5 pt-2.5">
                <h2 class="truncate text-[0.75rem] font-bold text-charcoal">
                    {{ __('opes.guardian_portal.dashboard_recent_messages') }}
                </h2>
                <a href="{{ route('portal.messages') }}" class="shrink-0 text-[0.6rem] font-semibold text-primary">
                    {{ __('opes.guardian_portal.view_all') }}
                </a>
            </div>

            @if ($recentMessages === [])
                <p class="px-2.5 pb-3 pt-2 text-[0.65rem] text-charcoal/55">
                    {{ __('opes.guardian_portal.messages_empty') }}
                </p>
            @else
                <div class="space-y-2 p-2.5">
                    @foreach ($recentMessages as $message)
                        <a wire:key="dash-msg-{{ $message['id'] }}"
                           href="{{ route('portal.messages.thread', $message['id']) }}"
                           class="flex items-start gap-1.5">
                            <span class="relative flex h-[0.9375rem] w-[0.9375rem] shrink-0 items-center justify-center text-primary">
                                <x-portal.icon name="mail" bare size="sm"/>

                                @if ($message['unread'])
                                    <span class="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-portal-danger"
                                          aria-hidden="true"></span>
                                @endif
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[0.65rem] font-semibold leading-tight text-charcoal">
                                    {{ $message['title'] }}
                                </span>

                                {{-- RELATIVE, as the design has it ("2h ago"),
                                     not the raw timestamp this printed before.
                                     Parsed defensively: the value arrives as a
                                     string and a malformed one must not take
                                     the dashboard down over a caption. --}}
                                <span class="block truncate text-[0.58rem] text-charcoal/55">
                                    @php
                                        try {
                                            $stamp = \Illuminate\Support\Carbon::parse($message['subtitle'])->diffForHumans();
                                        } catch (\Throwable) {
                                            $stamp = $message['subtitle'];
                                        }
                                    @endphp
                                    {{ $stamp }}
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-border-primary bg-white">
            <div class="flex items-center justify-between gap-1 px-2.5 pt-2.5">
                <h2 class="truncate text-[0.75rem] font-bold text-charcoal">
                    {{ __('opes.guardian_portal.dashboard_upcoming') }}
                </h2>
                <a href="{{ route('portal.announcements') }}" class="shrink-0 text-[0.6rem] font-semibold text-primary">
                    {{ __('opes.guardian_portal.view_all') }}
                </a>
            </div>

            @if ($upcoming === [])
                <p class="px-2.5 pb-3 pt-2 text-[0.65rem] text-charcoal/55">
                    {{ __('opes.guardian_portal.announcements_empty') }}
                </p>
            @else
                <div class="space-y-2 p-2.5">
                    @foreach ($upcoming as $event)
                        <div wire:key="dash-evt-{{ $loop->index }}" class="flex items-start gap-1.5">
                            <span class="flex h-8 w-7 shrink-0 flex-col items-center justify-center rounded-lg bg-surface-secondary">
                                <span class="text-[0.5rem] font-semibold uppercase leading-none text-charcoal/50">
                                    {{ $event['month'] }}
                                </span>
                                <span class="text-[0.7rem] font-bold leading-none text-charcoal">{{ $event['day'] }}</span>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[0.65rem] font-semibold leading-tight text-charcoal">
                                    {{ $event['title'] }}
                                </span>
                                <span class="block truncate text-[0.58rem] text-charcoal/55">{{ $event['when'] }}</span>
                            </span>
                        </div>
                    @endforeach

                    {{-- The footer row the design closes this panel with. It
                         was omitted, and a missing row is a missing section:
                         the panel read as finished while it was not. --}}
                    <a href="{{ route('portal.announcements') }}"
                       class="flex items-center gap-1 border-t border-border-secondary pt-2 text-[0.6rem] font-semibold text-primary">
                        <x-portal.icon name="calendar" bare size="sm"/>
                        <span class="min-w-0 flex-1 truncate">
                            {{ __('opes.guardian_portal.dashboard_add_to_calendar') }}
                        </span>
                        <x-portal.icon name="chevron-right" bare size="sm"/>
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- ------------------------------------------------- quick actions -- --}}
    <div class="space-y-2">
        <h2 class="text-[0.92rem] font-bold text-primary">
            {{ __('opes.guardian_portal.dashboard_quick_actions') }}
        </h2>

        <div class="grid grid-cols-6 gap-1.5">
            @foreach ($quickActions as $action)
                <a wire:key="qa-{{ $loop->index }}" href="{{ $action['href'] }}"
                   class="flex flex-col items-center justify-center gap-1 rounded-lg border border-border-primary bg-white px-0.5 py-2 text-center">
                    <x-portal.icon :name="$action['icon']" bare size="sm" class="text-primary"/>
                    <span class="w-full truncate text-[0.55rem] font-medium leading-tight text-charcoal">
                        {{ $action['label'] }}
                    </span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- ------------------------------------------------------- safety -- --}}
    <div class="flex items-center gap-2.5 rounded-xl bg-portal-green px-3 py-3">
        <x-portal.icon name="shield" bare size="lg" class="shrink-0 text-portal-gold"/>

        <div class="min-w-0 flex-1">
            <p class="text-[0.78rem] font-bold text-white">
                {{ __('opes.guardian_portal.dashboard_safety_title') }}
            </p>
            <p class="mt-0.5 text-[0.62rem] leading-snug text-white/70">
                {{ __('opes.guardian_portal.dashboard_safety_body') }}
            </p>
        </div>

        <a href="{{ route('portal.account.edit') }}"
           class="flex shrink-0 items-center gap-1 rounded-lg border border-portal-gold px-2 py-1.5 text-[0.66rem] font-bold text-portal-gold">
            {{ __('opes.guardian_portal.dashboard_update_profile') }}
            <x-portal.icon name="chevron-right" bare size="sm"/>
        </a>
    </div>
</div>
