{{--
    `/portal/account/settings` - built to mobile/account-settings.png: the
    identity strip, the settings list, the notification channel chips, and the
    security summary.
--}}
<div class="min-w-0 space-y-5">

    <div class="pt-2">
        <a href="{{ route('portal.account') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.account_title') }}
        </a>

        <h1 class="mt-1 text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.account_settings_title') }}</h1>
        <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.account_settings_subtitle') }}</p>
    </div>

    <a href="{{ route('portal.account') }}" class="block">
        <x-portal.card tone="green" class="flex items-center gap-4">
            <x-portal.avatar :name="$guardian?->fullName() ?? '?'" size="lg" tone="chrome"
                             :photo="route('portal.photo.self')"/>

            <div class="min-w-0 flex-1">
                <p class="truncate text-base font-bold text-charcoal">{{ $guardian?->fullName() ?? '—' }}</p>
                <span class="mt-1 inline-block rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-primary">
                    {{ __('opes.guardian_portal.account_role') }}
                </span>
                <p class="mt-1 truncate text-xs text-charcoal/70">{{ $guardian?->email ?? $guardian?->phone }}</p>
            </div>

            <svg class="h-5 w-5 shrink-0 text-charcoal/30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 5l7 7-7 7"/>
            </svg>
        </x-portal.card>
    </a>

    {{-- The design's six rows. Five exist; "Password, 2FA and security
         options" becomes devices and sessions, which is the part of it this
         platform has. --}}
    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.account_settings_title')" icon="gear"/>
        </div>

        <div class="divide-y divide-border-secondary pb-1">
            @foreach ([
                ['user', 'portal.account.edit', __('opes.guardian_portal.account_personal_info'), __('opes.guardian_portal.account_personal_info_hint')],
                ['globe', 'portal.account.notifications', __('opes.guardian_portal.account_preferences'), __('opes.guardian_portal.account_preferences_hint')],
                ['bell', 'portal.account.notifications', __('opes.guardian_portal.account_notify'), __('opes.guardian_portal.account_notifications_hint')],
                ['card', 'portal.payments', __('opes.guardian_portal.payments_title'), __('opes.guardian_portal.account_payments_hint')],
                ['shield', 'portal.account.security', __('opes.guardian_portal.security_title'), __('opes.guardian_portal.account_security_hint')],
                ['id', 'portal.account.security', __('opes.guardian_portal.account_devices'), __('opes.guardian_portal.account_devices_hint')],
            ] as $index => [$icon, $routeName, $label, $hint])
                <x-portal.row wire:key="set-{{ $index }}"
                              :title="$label" :subtitle="$hint"
                              :icon="$icon" tone="primary"
                              :href="route($routeName)"/>
            @endforeach
        </div>
    </x-portal.card>

    {{-- The On/Off channel chips the design shows. --}}
    <x-portal.card>
        <x-portal.section :title="__('opes.guardian_portal.account_notify')" icon="bell"
                          :action="__('opes.guardian_portal.account_edit')"
                          :href="route('portal.account.notifications')" class="mb-4"/>

        <div class="grid grid-cols-3 gap-3">
            @foreach ([
                ['phone', __('opes.guardian_portal.account_notify_sms'), $notifySms],
                ['mail', __('opes.guardian_portal.account_notify_email'), $notifyEmail],
                ['bell', __('opes.guardian_portal.account_notify_push'), $notifyPush],
            ] as [$icon, $label, $isOn])
                <div class="flex flex-col items-center gap-2 rounded-xl border border-border-secondary px-2 py-4 text-center">
                    <x-portal.icon :name="$icon" :tone="$isOn ? 'success' : 'primary'" size="sm"/>
                    <p class="text-[11px] font-medium leading-tight text-charcoal">{{ $label }}</p>
                    <p @class(['text-[11px] font-bold', 'text-portal-success' => $isOn, 'text-charcoal/40' => ! $isOn])>
                        {{ $isOn ? __('opes.guardian_portal.account_on') : __('opes.guardian_portal.account_off') }}
                    </p>
                </div>
            @endforeach
        </div>
    </x-portal.card>

    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.security_title')" icon="shield"
                              :action="__('opes.guardian_portal.messages_open')"
                              :href="route('portal.account.security')"/>
        </div>

        <div class="divide-y divide-border-secondary pb-1">
            <x-portal.row :title="__('opes.guardian_portal.security_sessions')"
                          icon="clock" tone="primary"
                          :trailing="(string) $sessionCount" :chevron="false"/>
            <x-portal.row :title="__('opes.guardian_portal.security_devices')"
                          icon="id" tone="primary"
                          :trailing="(string) $deviceCount" :chevron="false"/>
        </div>

        <p class="px-4 pb-4 text-xs text-charcoal/55 sm:px-5 sm:pb-5">
            {{ __('opes.guardian_portal.account_no_2fa') }}
        </p>
    </x-portal.card>
</div>
