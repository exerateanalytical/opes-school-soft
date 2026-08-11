<div class="min-w-0 space-y-4">
    <div class="min-w-0">
        <a href="{{ route('portal.account') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.account_title') }}
        </a>

        <h1 class="mt-1 text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.security_title') }}</h1>
        @if ($lastLoginAt)
            <p class="mt-1 text-sm text-charcoal/70">
                {{ __('opes.guardian_portal.account_last_login') }}: {{ $lastLoginAt }}
            </p>
        @endif
    </div>

    @if (session('portal-status'))
        <p class="rounded border border-success/30 bg-success-bg px-4 py-2 text-sm text-success">{{ session('portal-status') }}</p>
    @endif

    {{-- Mobile app installations holding a live 30-day token. Revoking one is
         the honest answer to "I lost my phone": access ends now, not in a
         month. --}}
    <section aria-labelledby="portal-security-devices" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <h2 id="portal-security-devices" class="text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.security_devices') }}
        </h2>

        @if ($devices->isEmpty())
            <p class="mt-3 text-sm text-charcoal/60">{{ __('opes.guardian_portal.security_devices_empty') }}</p>
        @else
            <ul class="mt-3 divide-y divide-border-secondary">
                @foreach ($devices as $device)
                    <li wire:key="device-{{ $device->id }}" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium capitalize text-charcoal">{{ $device->platform }}</span>
                            <span class="block text-xs text-charcoal/60">
                                {{ __('opes.guardian_portal.security_last_active') }}:
                                {{ $device->last_used_at ?? $device->created_at ?? __('opes.guardian_portal.account_none') }}
                            </span>
                        </span>

                        <button type="button" wire:click="revokeDevice({{ $device->id }})"
                                class="shrink-0 rounded border border-danger/50 px-3 py-1.5 text-xs font-semibold text-danger hover:bg-danger-bg">
                            {{ __('opes.guardian_portal.security_revoke') }}
                        </button>
                    </li>
                @endforeach
            </ul>

            <p class="mt-3 text-xs text-charcoal/60">{{ __('opes.guardian_portal.security_lost_phone') }}</p>
        @endif
    </section>

    {{-- Browsers signed in right now. The current one is flagged rather than
         hidden: a list where every row looks equally foreign invites a parent
         to revoke the wrong one. --}}
    <section aria-labelledby="portal-security-sessions" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <h2 id="portal-security-sessions" class="text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.security_sessions') }}
        </h2>

        @if ($sessions->isEmpty())
            <p class="mt-3 text-sm text-charcoal/60">{{ __('opes.guardian_portal.security_sessions_empty') }}</p>
        @else
            <ul class="mt-3 divide-y divide-border-secondary">
                @foreach ($sessions as $session)
                    <li wire:key="session-{{ $session->id }}" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-charcoal">
                                {{ \Illuminate\Support\Str::limit($session->user_agent ?? '—', 60) }}
                            </span>
                            <span class="block text-xs text-charcoal/60">
                                {{ $session->ip_address ?? '—' }}
                                <span aria-hidden="true">&middot;</span>
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}
                            </span>
                        </span>

                        @if ($session->is_current)
                            <span class="shrink-0 rounded bg-success-bg px-2 py-0.5 text-xs font-medium text-success">
                                {{ __('opes.guardian_portal.security_current') }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="rounded border border-border-secondary bg-surface-green px-4 py-3 text-sm text-charcoal/70">
        {{ __('opes.guardian_portal.account_no_2fa') }}
    </p>
</div>
