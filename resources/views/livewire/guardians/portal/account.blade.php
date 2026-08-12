{{--
    `/portal/account` - built to mobile/parent-profile.png: the identity card,
    My Children, Account Information, Quick Actions, and the security banner.
--}}
<div class="min-w-0 space-y-5">

    <div class="flex items-center gap-3 pt-2">
        <x-portal.icon name="user" tone="primary"/>
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.account_title') }}</h1>
            <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.account_subtitle') }}</p>
        </div>
    </div>

    {{-- ------------------------------------------------ identity card -- --}}
    <x-portal.card tone="green">
        <div class="flex flex-wrap items-start gap-4">
            <x-portal.avatar :name="$guardian?->fullName() ?? '?'" size="xl" tone="chrome"
                             :photo="route('portal.photo.self')"/>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-xl font-bold text-charcoal">{{ $guardian?->fullName() ?? '—' }}</p>
                    <span class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-primary">
                        <x-portal.icon name="user" bare size="sm"/>
                        {{ __('opes.guardian_portal.account_role') }}
                    </span>
                </div>

                <dl class="mt-3 space-y-2 text-sm text-charcoal/80">
                    @if ($guardian?->email)
                        <div class="flex items-center gap-2">
                            <x-portal.icon name="mail" bare size="sm" class="text-primary"/>
                            <dd>{{ $guardian->email }}</dd>
                        </div>
                    @endif
                    @if ($guardian?->phone)
                        <div class="flex items-center gap-2">
                            <x-portal.icon name="phone" bare size="sm" class="text-primary"/>
                            <dd>{{ $guardian->phone }}</dd>
                        </div>
                    @endif
                    @if ($guardian?->city || $guardian?->address_line)
                        <div class="flex items-start gap-2">
                            <x-portal.icon name="pin" bare size="sm" class="mt-0.5 text-primary"/>
                            <dd>{{ collect([$guardian->address_line, $guardian->city, $guardian->region])->filter()->join(', ') }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($canEdit)
                <a href="{{ route('portal.account.edit') }}"
                   class="shrink-0 rounded-xl border border-primary bg-white px-4 py-2.5 text-sm font-semibold text-primary hover:bg-portal-tint">
                    {{ __('opes.guardian_portal.account_edit') }}
                </a>
            @endif
        </div>
    </x-portal.card>

    {{-- --------------------------------------------------- my children -- --}}
    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.account_children')" icon="users"
                              :action="__('opes.guardian_portal.account_children_count', ['count' => count($children)])"/>
        </div>

        @if ($children === [])
            <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.no_children') }}</p>
        @else
            <div class="space-y-3 px-4 pb-4 sm:px-5 sm:pb-5">
                @foreach ($children as $child)
                    <div wire:key="acct-child-{{ $child['id'] }}"
                         class="flex flex-wrap items-center gap-3 rounded-xl border border-border-secondary p-3">
                        <x-portal.avatar :name="$child['display_name']" size="lg" tone="green"
                                         :photo="route('portal.photo.child', $child['id'])"/>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate text-base font-semibold text-charcoal">{{ $child['display_name'] }}</p>
                                @if ($child['class'])
                                    <span class="rounded-full bg-portal-tint px-2.5 py-0.5 text-xs font-medium text-primary">
                                        {{ $child['class'] }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-charcoal/60">
                                {{ __('opes.guardian_portal.profile_matricule') }}: {{ $child['matricule'] }}
                            </p>
                        </div>

                        <a href="{{ route('portal.children.profile', $child['id']) }}"
                           class="shrink-0 rounded-xl border border-border-primary px-3 py-2 text-xs font-semibold text-primary hover:border-primary/50">
                            {{ __('opes.guardian_portal.account_view_child') }}
                        </a>
                    </div>
                @endforeach

                {{-- The design shows "Link Another Child" as an action. It is
                     not one: linking gates on guardians.manage, and a parent
                     who could self-link could attach themselves to any child
                     in the school. Stated, not offered. --}}
                <div class="flex items-center gap-3 rounded-xl border border-dashed border-border-primary p-3">
                    <x-portal.icon name="users" tone="primary" size="sm"/>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-charcoal">{{ __('opes.guardian_portal.account_link_child') }}</p>
                        <p class="text-xs text-charcoal/60">{{ __('opes.guardian_portal.account_link_child_hint') }}</p>
                    </div>
                </div>
            </div>
        @endif
    </x-portal.card>

    {{-- -------------------------------------------- account information -- --}}
    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.account_information')" icon="id"/>
        </div>

        <dl class="divide-y divide-border-secondary px-4 pb-2 text-sm sm:px-5">
            @foreach ([
                ['user', __('opes.guardian_portal.account_type'), __('opes.guardian_portal.account_type_value')],
                ['calendar', __('opes.guardian_portal.account_registered_on'), $registeredOn ?? __('opes.guardian_portal.account_none')],
                ['clock', __('opes.guardian_portal.account_last_login'), $lastLoginAt ?? __('opes.guardian_portal.account_none')],
                ['globe', __('opes.guardian_portal.account_language'), $guardian?->language?->value === 'fr' ? 'Français' : 'English'],
                ['bell', __('opes.guardian_portal.account_comm_preference'), $guardian?->preferred_contact_method?->value ?? __('opes.guardian_portal.account_none')],
            ] as [$icon, $label, $value])
                <div class="flex items-center gap-3 py-3">
                    <x-portal.icon :name="$icon" tone="primary" size="sm"/>
                    <dt class="min-w-0 flex-1 text-charcoal/70">{{ $label }}</dt>
                    <dd class="shrink-0 text-right font-semibold text-charcoal">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-portal.card>

    {{-- -------------------------------------------------- quick actions -- --}}
    <x-portal.card>
        <x-portal.section :title="__('opes.guardian_portal.account_quick_actions')" icon="gear" class="mb-4"/>

        {{-- "Change Password" and "2FA" are absent, not disabled: neither
             exists on this surface, and a tile that appears to harden an
             account without doing so is worse than no tile. --}}
        @php
            $actions = [];
            if ($canEdit) {
                $actions[] = ['user', __('opes.guardian_portal.account_personal_info'), route('portal.account.edit')];
                $actions[] = ['bell', __('opes.guardian_portal.account_notify'), route('portal.account.notifications')];
            }
            $actions[] = ['shield', __('opes.guardian_portal.security_title'), route('portal.account.security')];
            $actions[] = ['gear', __('opes.guardian_portal.account_settings_title'), route('portal.account.settings')];
            $actions[] = ['help', __('opes.guardian_portal.help_title'), route('portal.help')];
        @endphp

        <div class="grid grid-cols-3 gap-3 sm:grid-cols-5">
            @foreach ($actions as [$icon, $label, $href])
                <a wire:key="acct-qa-{{ $loop->index }}" href="{{ $href }}"
                   class="flex flex-col items-center justify-center gap-2 rounded-xl border border-border-primary px-2 py-4 text-center hover:border-primary/40 hover:bg-portal-tint">
                    <x-portal.icon :name="$icon" tone="primary" size="sm"/>
                    <span class="text-[11px] font-medium leading-tight text-charcoal">{{ $label }}</span>
                </a>
            @endforeach
        </div>
    </x-portal.card>

    {{-- ------------------------------------------------ security banner -- --}}
    <x-portal.card tone="green">
        <div class="flex flex-wrap items-center gap-4">
            <x-portal.icon name="shield" tone="primary" size="lg"/>

            <div class="min-w-0 flex-1">
                <p class="text-base font-bold text-charcoal">{{ __('opes.guardian_portal.security_title') }}</p>
                <p class="mt-0.5 text-sm text-charcoal/70">
                    {{ __('opes.guardian_portal.security_sessions') }}: {{ $sessionCount }}
                    <span aria-hidden="true">&middot;</span>
                    {{ __('opes.guardian_portal.security_devices') }}: {{ $deviceCount }}
                </p>
                <p class="mt-1 text-xs text-charcoal/55">{{ __('opes.guardian_portal.account_no_2fa') }}</p>
            </div>

            <a href="{{ route('portal.account.security') }}"
               class="shrink-0 rounded-xl border border-primary bg-white px-4 py-2.5 text-sm font-semibold text-primary hover:bg-portal-tint">
                {{ __('opes.guardian_portal.security_title') }}
            </a>
        </div>
    </x-portal.card>
</div>
