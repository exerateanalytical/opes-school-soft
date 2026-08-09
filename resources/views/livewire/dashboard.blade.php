<div class="space-y-6">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.dashboard.title') }}</h1>

    {{-- ── Tiles. Only the four whose data actually exists (09-ui 3). Each
         gets the mockup's coloured icon-circle; deltas stay OFF here because
         no "vs last term" comparison exists for any of these four figures
         yet - inventing one would violate the no-fabricated-data rule. ───── --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {{-- The tile links to the user list only for someone allowed to open
             it. A link that guarantees a 403 is not a shortcut, it is a trap,
             and it also tells the reader a screen exists that they may not
             see. --}}
        <x-kpi-card :label="__('opes.dashboard.tile_users')"
                    :value="$activeUsers"
                    :href="$canViewUsers ? '/users' : null"
                    icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path stroke-linecap="round" d="M2.8 19.5c0-3.4 2.8-6.2 6.2-6.2s6.2 2.8 6.2 6.2"/><path stroke-linecap="round" d="M15.5 8.3a2.8 2.8 0 110 5.6M20.5 19.5c0-2.6-1.9-4.8-4.4-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.dashboard.tile_roles')" :value="$roleCount" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <div class="flex items-start gap-3 rounded border border-sand bg-white px-4 py-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-badge-teal text-white">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/></svg>
            </span>
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-charcoal/60">
                    {{ __('opes.dashboard.tile_health') }}
                </p>
                <p class="mt-2">
                    <x-status-pill :status="$healthSummary->value"/>
                </p>
            </div>
        </div>

        {{-- Null, not zero: see Dashboard::lastBackupAge(). --}}
        <x-kpi-card :label="__('opes.dashboard.tile_backup')" :value="$lastBackupAge" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3.5 2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

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
