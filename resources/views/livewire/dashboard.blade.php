<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.dashboard.title') }}</h1>

        {{-- Which identity is producing this (filtered) screen. Makes a role
             demonstration legible, and is a useful orientation cue for a real
             user who holds more than one account. --}}
        @if ($signedInAs !== null)
            <p class="text-xs text-charcoal/70">
                {{ __('opes.dashboard.signed_in_as') }}:
                <span class="font-semibold text-charcoal">{{ $signedInAs['name'] }}</span>
                @if ($signedInAs['role'] !== null)
                    <span aria-hidden="true">·</span>
                    <span class="font-semibold text-charcoal">{{ $signedInAs['role'] }}</span>
                @endif
            </p>
        @endif
    </div>

    @if ($financeDashboardUrl !== null)
        <p>
            <a href="{{ $financeDashboardUrl }}" wire:navigate
               class="inline-flex items-center gap-2 rounded border border-primary px-3 py-1.5 text-sm font-semibold text-primary hover:bg-primary/5">
                {{ __('opes.dashboard.go_to_finance') }}
            </a>
        </p>
    @endif

    {{-- ── Tiles. Only the four whose data actually exists (09-ui 3). Each
         gets the mockup's coloured icon-circle; deltas stay OFF here because
         no "vs last term" comparison exists for any of these four figures
         yet - inventing one would violate the no-fabricated-data rule. ───── --}}
    @if ($tileCount > 0)
    <div @class([
        'grid grid-cols-1 gap-3 sm:grid-cols-2',
        'xl:grid-cols-1' => $tileCount === 1,
        'xl:grid-cols-2' => $tileCount === 2,
        'xl:grid-cols-3' => $tileCount === 3,
        'xl:grid-cols-4' => $tileCount === 4,
        'xl:grid-cols-5' => $tileCount >= 5,
    ])>
        {{-- Identity tiles: gated on user.view, the same permission the
             /users screen behind them is gated on. The tile links there only
             for someone allowed to open it. A link that guarantees a 403 is
             not a shortcut, it is a trap. --}}
        @if ($canViewUsers)
        <x-kpi-card :label="__('opes.dashboard.tile_users')"
                    :value="$activeUsers"
                    :href="$canViewUsers ? '/users' : null"
                    tone="green">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path stroke-linecap="round" d="M2.8 19.5c0-3.4 2.8-6.2 6.2-6.2s6.2 2.8 6.2 6.2"/><path stroke-linecap="round" d="M15.5 8.3a2.8 2.8 0 110 5.6M20.5 19.5c0-2.6-1.9-4.8-4.4-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.dashboard.tile_roles')" :value="$roleCount" tone="blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        @endif

        {{-- Health carries a status pill rather than a number, which is why it
             used to be hand-rolled - and why it rendered as the one plain white
             rectangle in a row of tinted cards. x-kpi-card's `display` slot
             covers the non-numeric case now, so this tile is a real KPI card
             and picks up the same surface, radius and badge as its neighbours.
             `iconBg` keeps the teal circle it has always had. --}}
        @if ($canViewHealth)
        <x-kpi-card :label="__('opes.dashboard.tile_health')" icon-bg="bg-badge-teal">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/></svg>
            </x-slot:icon>
            <x-slot:display>
                <x-status-pill :status="$healthSummary->value"/>
            </x-slot:display>
        </x-kpi-card>
        @endif

        {{-- Null, not zero: see Dashboard::lastBackupAge(). Gated on
             backup.run - the operator right that makes the figure
             actionable. --}}
        @if ($canViewBackup)
        <x-kpi-card :label="__('opes.dashboard.tile_backup')" :value="$lastBackupAge" tone="amber">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3.5 2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        @endif

        {{-- Phase 8 F5: shown only to a role that holds attendance.view - the
             same permission the /attendance route itself is gated on, so the
             tile never links anywhere its viewer would get a 403. Null, not
             zero, when no register has been taken yet today: see
             Dashboard::todaysAttendanceRate(). --}}
        @if ($canViewAttendance)
            <x-kpi-card :label="__('opes.dashboard.tile_attendance')"
                        :value="$todaysAttendanceRate"
                        href="/attendance"
                        tone="purple">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>
                </x-slot:icon>
            </x-kpi-card>
        @endif
    </div>
    @endif

    {{-- ── "What's open right now" (08-operations §6.4). The panel decides
         its own visibility (fee.view or ledger.view - the Bursar, Accountant,
         Principal, Administrator set the spec names) and renders nothing for
         anyone else. --}}
    @livewire(\App\Modules\Operations\Livewire\WhatsOpenPanel::class)

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
                    <li class="rounded border border-border-primary bg-white px-4 py-3">
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

    {{-- Quick actions render as the gold-bordered sidebar box the mockups
         show (see layouts/app.blade.php's @stack('sidebar-quick-actions')),
         not inline in the page body - this keeps the same treatment on
         every screen that pushes its own action list. --}}
    @push('sidebar-quick-actions')
        <div class="mx-3 mt-auto rounded-lg border border-heritage-yellow/70 p-3">
            <h2 class="text-xs font-bold uppercase tracking-wide text-heritage-yellow">
                {{ __('opes.dashboard.quick_actions') }}
            </h2>
            <ul class="mt-2 space-y-1">
                @foreach ($quickActions as $action)
                    <li>
                        @if (is_string($action['route']))
                            <a href="{{ $action['route'] }}"
                               class="flex items-center gap-2 rounded px-2 py-1.5 text-sm text-white/90 hover:bg-chrome-light">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                                {{ $action['label'] }}
                            </a>
                        @else
                            <span aria-disabled="true"
                                  title="{{ __('opes.nav.nav_disabled_title') }}"
                                  class="flex cursor-not-allowed items-center gap-2 rounded px-2 py-1.5 text-sm text-white/40">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-white/30" aria-hidden="true"></span>
                                {{ $action['label'] }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endpush
</div>
