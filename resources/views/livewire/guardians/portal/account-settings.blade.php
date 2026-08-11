<div class="min-w-0 space-y-4">
    <div class="min-w-0">
        <a href="{{ route('portal.account') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.account_title') }}
        </a>

        <h1 class="mt-1 text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.account_settings_title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.guardian_portal.account_settings_subtitle') }}</p>
    </div>

    <section class="rounded border border-border-primary bg-surface-green p-4">
        <div class="flex items-center gap-3">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-chrome text-base font-semibold uppercase text-white">
                {{ mb_substr($guardian?->fullName() ?? '?', 0, 1) }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-base font-semibold text-charcoal">{{ $guardian?->fullName() ?? '—' }}</p>
                <p class="truncate text-xs text-charcoal/70">{{ $guardian?->email ?? $guardian?->phone }}</p>
            </div>
        </div>
    </section>

    {{-- The reference screen's six rows. Five exist; the sixth ("Password, 2FA
         and security options") becomes devices and sessions, which is the part
         of it this platform actually has. --}}
    <section aria-labelledby="portal-settings-list" class="rounded border border-border-primary bg-white shadow-sm">
        <h2 id="portal-settings-list" class="border-b border-border-secondary px-4 py-3 text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.account_settings_title') }}
        </h2>

        <ul class="divide-y divide-border-secondary">
            @foreach ([
                ['portal.account.edit', __('opes.guardian_portal.account_personal_info'), __('opes.guardian_portal.account_personal_info_hint')],
                ['portal.account.notifications', __('opes.guardian_portal.account_notify'), __('opes.guardian_portal.account_notifications_hint')],
                ['portal.payments', __('opes.guardian_portal.payments_title'), __('opes.guardian_portal.account_payments_hint')],
                ['portal.account.security', __('opes.guardian_portal.security_title'), __('opes.guardian_portal.account_security_hint')],
                ['portal.help', __('opes.guardian_portal.help_title'), __('opes.guardian_portal.help_contact')],
            ] as [$routeName, $label, $hint])
                <li>
                    <a href="{{ route($routeName) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-surface-secondary">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-charcoal">{{ $label }}</span>
                            <span class="block text-xs text-charcoal/60">{{ $hint }}</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-charcoal/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- The On/Off channel chips the reference screen shows. --}}
    <section aria-labelledby="portal-settings-notify" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-2">
            <h2 id="portal-settings-notify" class="text-sm font-semibold text-charcoal">
                {{ __('opes.guardian_portal.account_notify') }}
            </h2>
            <a href="{{ route('portal.account.notifications') }}" class="text-xs font-semibold text-primary">
                {{ __('opes.guardian_portal.account_edit') }}
            </a>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-2">
            @foreach ([
                [__('opes.guardian_portal.account_notify_sms'), $notifySms],
                [__('opes.guardian_portal.account_notify_email'), $notifyEmail],
                [__('opes.guardian_portal.account_notify_push'), $notifyPush],
            ] as [$label, $isOn])
                <div class="rounded border border-border-secondary px-2 py-3 text-center">
                    <p class="text-xs font-medium text-charcoal">{{ $label }}</p>
                    <p @class(['mt-1 text-xs font-semibold', 'text-success' => $isOn, 'text-charcoal/40' => ! $isOn])>
                        {{ $isOn ? __('opes.guardian_portal.account_on') : __('opes.guardian_portal.account_off') }}
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="portal-settings-security" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-2">
            <h2 id="portal-settings-security" class="text-sm font-semibold text-charcoal">
                {{ __('opes.guardian_portal.security_title') }}
            </h2>
            <a href="{{ route('portal.account.security') }}" class="text-xs font-semibold text-primary">
                {{ __('opes.guardian_portal.messages_open') }}
            </a>
        </div>

        <dl class="mt-3 divide-y divide-border-secondary text-sm">
            <div class="flex items-center justify-between gap-4 py-2.5">
                <dt class="text-charcoal/60">{{ __('opes.guardian_portal.security_sessions') }}</dt>
                <dd class="font-medium text-charcoal">{{ $sessionCount }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 py-2.5">
                <dt class="text-charcoal/60">{{ __('opes.guardian_portal.security_devices') }}</dt>
                <dd class="font-medium text-charcoal">{{ $deviceCount }}</dd>
            </div>
        </dl>

        <p class="mt-2 text-xs text-charcoal/60">{{ __('opes.guardian_portal.account_no_2fa') }}</p>
    </section>
</div>
