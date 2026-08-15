{{-- The landing screen, composed PER ROLE (09-ui §3).

     This was one screen for twenty roles, and it produced two defects an
     audit caught: an Accountant landed on a page with zero KPI cards, and a
     Teacher landed on one card reading "—" beside a raw authorization
     exception. Gating the admin tiles away from other roles was correct;
     having nothing to put in their place was not.

     Spacing follows the 8pt token grid (--space-*). Do NOT reason about
     pixel sizes from the Tailwind utility names on this page: the root
     font-size is 17px, so gap-4 is 16px but w-72 is 306px. --}}
<div class="min-w-0 space-y-8">

    {{-- ── Greeting ─────────────────────────────────────────────────────── --}}
    <div>
        <h1 class="text-2xl font-bold text-charcoal">
            {{ __('opes.dashboard.greeting', ['name' => $signedInAs['name'] ?? '']) }}
        </h1>
        @if (($signedInAs['role'] ?? null) !== null)
            {{-- Which identity is producing this (filtered) screen. Makes a
                 role demonstration legible, and orients a real user who holds
                 more than one account. --}}
            <p class="mt-1 text-sm text-text-secondary">
                {{ __('opes.dashboard.greeting_role', ['role' => $signedInAs['role']]) }}
            </p>
        @endif
    </div>

    {{-- ── KPI strip ────────────────────────────────────────────────────── --}}
    @if ($panels !== [])
        <section aria-labelledby="opes-dashboard-overview">
            <h2 id="opes-dashboard-overview" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.dashboard.overview') }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach ($panels as $panel)
                    <x-kpi-card wire:key="panel-{{ $panel['key'] }}"
                                :label="__('opes.dashboard.panel_'.$panel['key'])"
                                :value="$panel['value']"
                                :sub="$panel['sub'] ?? __('opes.dashboard.panel_'.$panel['key'].'_sub')"
                                :tone="$panel['tone']"
                                :href="$panel['route'] === null ? null : route($panel['route'], absolute: false)">
                        <x-slot:icon>
                            <x-opes-nav-icon :nav-key="$panel['icon']" class="h-5 w-5"/>
                        </x-slot:icon>

                        {{-- System health is the one panel whose headline is
                             not a numeral: it carries a status pill through
                             x-kpi-card's display slot, so it picks up the same
                             surface, radius and badge as its neighbours rather
                             than rendering as the one plain white rectangle in
                             a tinted row. --}}
                        @if ($panel['key'] === 'system_health' && $healthSummary !== null)
                            <x-slot:display>
                                <x-status-pill :status="$healthSummary->value"/>
                            </x-slot:display>
                        @endif
                    </x-kpi-card>
                @endforeach
            </div>
        </section>
    @else
        {{-- The empty-state rule: a role with nothing to show still lands on
             something that says what it is and offers what it can do. Never a
             blank grid. --}}
        <section class="rounded-xl border border-border-primary bg-white p-6 text-center shadow-sm">
            <p class="text-base font-medium text-charcoal">{{ __('opes.dashboard.empty_title') }}</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-text-secondary">{{ __('opes.dashboard.empty_body') }}</p>
        </section>
    @endif

    {{-- ── Quick actions ────────────────────────────────────────────────── --}}
    @if ($quickActions !== [])
        <section aria-labelledby="opes-dashboard-actions">
            <h2 id="opes-dashboard-actions" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.dashboard.quick_actions') }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($quickActions as $action)
                    {{-- min-h-[88px] so a one-word card and a two-line card
                         are the same height; the badge is 40x40 inside a 44px
                         tap target row. --}}
                    <a href="{{ $action['url'] }}" wire:key="action-{{ $action['key'] }}" wire:navigate
                       class="group flex min-h-[88px] items-start gap-3 rounded-xl border border-border-primary bg-white p-4 shadow-sm transition hover:border-primary hover:shadow-md">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-kpi-green text-kpi-green-solid">
                            <x-opes-nav-icon :nav-key="$action['icon']" class="h-5 w-5"/>
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold text-charcoal group-hover:text-primary">{{ $action['label'] }}</span>
                            <span class="mt-0.5 block text-sm text-text-secondary">{{ $action['description'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── "What's open right now" (08-operations §6.4). The panel decides
         its own visibility (fee.view or ledger.view) and renders nothing for
         anyone else. --}}
    @livewire(\App\Modules\Operations\Livewire\WhatsOpenPanel::class)

    {{-- ── Alerts ───────────────────────────────────────────────────────── --}}
    <section aria-labelledby="opes-alerts">
        <h2 id="opes-alerts" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.dashboard.alerts') }}
        </h2>

        @if ($alerts === [])
            <x-empty-state :message="__('opes.dashboard.no_alerts')"/>
        @else
            <ul class="space-y-2">
                @foreach ($alerts as $alert)
                    <li class="rounded-xl border border-border-primary bg-white px-4 py-3 shadow-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-pill :status="$alert->status->value"/>
                            <span class="text-sm font-semibold text-charcoal">{{ $alert->label }}</span>
                        </div>
                        <p class="mt-1 text-sm text-charcoal/80">{{ $alert->detail }}</p>
                        @if (str_starts_with($alert->key, 'backup.') || $alert->key === 'queue.heartbeat')
                            {{-- A school administrator gets a BUTTON, not a
                                 shell command: /operations/backups exists, is
                                 in the sidebar, and has a run control. The
                                 artisan command in the remedy string belongs
                                 to whoever runs the server, so it is tucked
                                 behind a disclosure rather than printed as
                                 the instruction. --}}
                            {{-- ...but only where a backup is the actual fix.
                                 queue.heartbeat shares this branch to keep its
                                 artisan remedy behind the disclosure, not
                                 because taking a backup restarts a dead task
                                 runner; it was offering that button, and a
                                 control that cannot fix the thing it sits
                                 under is worse than no control. --}}
                            @can('backup.run') @if (str_starts_with($alert->key, 'backup.'))
                                <a href="{{ route('operations.backups') }}"
                                   class="mt-2 inline-block rounded-lg border border-primary bg-primary px-3.5 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                                    {{ __('opes.dashboard.run_a_backup') }}
                                </a>
                            @endif @endcan
                            <details class="mt-2 text-xs text-charcoal/55">
                                <summary class="cursor-pointer">{{ __('opes.dashboard.for_your_it_provider') }}</summary>
                                <p class="mt-1">{{ __('opes.dashboard.it_provider_backup_note') }}</p>
                            </details>
                        @elseif ($alert->remedy !== '')
                            {{-- The remedy is the whole point. A red light with
                                 no instruction is anxiety, not information
                                 (08-operations 7). --}}
                            <p class="mt-1 text-sm text-charcoal">
                                <span class="font-medium">{{ __('opes.dashboard.remedy') }}:</span>
                                {{ $alert->remedy }}
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
