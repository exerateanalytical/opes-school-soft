<div class="space-y-6">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.dashboard.title') }}</h1>

    {{-- ── Tiles. Only the four whose data exists today (09-ui 3). ───────── --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {{-- The tile links to the user list only for someone allowed to open
             it. A link that guarantees a 403 is not a shortcut, it is a trap,
             and it also tells the reader a screen exists that they may not
             see. --}}
        <x-kpi-card :label="__('opes.dashboard.tile_users')"
                    :value="$activeUsers"
                    :href="$canViewUsers ? '/users' : null"/>

        <x-kpi-card :label="__('opes.dashboard.tile_roles')" :value="$roleCount"/>

        <div class="rounded border border-sand bg-white px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-charcoal/60">
                {{ __('opes.dashboard.tile_health') }}
            </p>
            <p class="mt-2">
                <x-status-pill :status="$healthSummary->value"/>
            </p>
        </div>

        {{-- Null, not zero: see Dashboard::lastBackupAge(). --}}
        <x-kpi-card :label="__('opes.dashboard.tile_backup')" :value="$lastBackupAge"/>
    </div>

    {{-- ── Alerts ───────────────────────────────────────────────────────── --}}
    <section aria-labelledby="opes-alerts">
        <h2 id="opes-alerts" class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.dashboard.alerts') }}
        </h2>

        @if ($alerts === [])
            <div class="mt-2">
                <x-empty-state :message="__('opes.dashboard.no_alerts')"/>
            </div>
        @else
            <ul class="mt-2 space-y-2">
                @foreach ($alerts as $alert)
                    <li class="rounded border border-sand bg-white px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-pill :status="$alert->status->value"/>
                            <span class="text-sm font-semibold text-charcoal">{{ $alert->label }}</span>
                        </div>
                        <p class="mt-1 text-sm text-charcoal/80">{{ $alert->detail }}</p>
                        @if ($alert->remedy !== '')
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

    {{-- ── Quick actions ────────────────────────────────────────────────── --}}
    <section aria-labelledby="opes-quick-actions">
        <h2 id="opes-quick-actions" class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.dashboard.quick_actions') }}
        </h2>

        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($quickActions as $action)
                @if (is_string($action['route']))
                    <a href="{{ $action['route'] }}"
                       class="rounded border border-primary/40 bg-white px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary/5">
                        {{ $action['label'] }}
                    </a>
                @else
                    <span aria-disabled="true"
                          title="{{ __('opes.nav.nav_disabled_title') }}"
                          class="cursor-not-allowed rounded border border-sand bg-white px-3 py-1.5 text-sm font-medium text-charcoal/40">
                        {{ $action['label'] }}
                    </span>
                @endif
            @endforeach
        </div>
    </section>
</div>
