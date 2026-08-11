<div class="min-w-0 space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.account_title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.guardian_portal.account_subtitle') }}</p>
    </div>

    @if (session('portal-status'))
        <p class="rounded border border-success/30 bg-success-bg px-4 py-2 text-sm text-success">{{ session('portal-status') }}</p>
    @endif

    {{-- Identity card, per mobile/parent-profile.png. --}}
    <section aria-labelledby="portal-account-identity" class="rounded border border-border-primary bg-surface-green p-4 shadow-sm">
        <h2 id="portal-account-identity" class="sr-only">{{ __('opes.guardian_portal.account_title') }}</h2>

        <div class="flex flex-wrap items-start gap-4">
            <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-chrome text-lg font-semibold uppercase text-white">
                {{ mb_substr($guardian?->fullName() ?? '?', 0, 1) }}
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-lg font-semibold text-charcoal">{{ $guardian?->fullName() ?? '—' }}</p>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-primary">
                        {{ __('opes.guardian_portal.account_role') }}
                    </span>
                </div>

                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    @if ($guardian?->email)
                        <div class="flex gap-2"><dt class="sr-only">{{ __('opes.guardian_portal.account_email') }}</dt><dd>{{ $guardian->email }}</dd></div>
                    @endif
                    @if ($guardian?->phone)
                        <div class="flex gap-2"><dt class="sr-only">{{ __('opes.guardian_portal.account_phone') }}</dt><dd>{{ $guardian->phone }}</dd></div>
                    @endif
                    @if ($guardian?->city || $guardian?->address_line)
                        <div class="flex gap-2">
                            <dt class="sr-only">{{ __('opes.guardian_portal.account_address') }}</dt>
                            <dd>{{ collect([$guardian->address_line, $guardian->city, $guardian->region])->filter()->join(', ') }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Offered only with row 29. Without it the school owns these
                 details and a button here would lead to a 403. --}}
            @if ($canEdit)
                <a href="{{ route('portal.account.edit') }}"
                   class="shrink-0 rounded border border-primary px-3 py-2 text-sm font-semibold text-primary hover:bg-white">
                    {{ __('opes.guardian_portal.account_edit') }}
                </a>
            @endif
        </div>
    </section>

    {{-- My children, per the reference screen. --}}
    <section aria-labelledby="portal-account-children" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-2">
            <h2 id="portal-account-children" class="text-sm font-semibold text-charcoal">
                {{ __('opes.guardian_portal.account_children') }}
            </h2>
            <span class="text-xs text-charcoal/60">
                {{ __('opes.guardian_portal.account_children_count', ['count' => count($children)]) }}
            </span>
        </div>

        @if ($children === [])
            <p class="mt-3 text-sm text-charcoal/60">{{ __('opes.guardian_portal.no_children') }}</p>
        @else
            <ul class="mt-3 divide-y divide-border-secondary">
                @foreach ($children as $child)
                    <li wire:key="acct-child-{{ $child['id'] }}" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-green text-sm font-semibold uppercase text-primary">
                            {{ mb_substr($child['display_name'], 0, 1) }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-semibold text-charcoal">{{ $child['display_name'] }}</span>
                                @if ($child['class'])
                                    <span class="rounded bg-surface-green px-2 py-0.5 text-xs font-medium text-primary">{{ $child['class'] }}</span>
                                @endif
                            </span>
                            <span class="block font-mono text-xs text-charcoal/50">{{ $child['matricule'] }}</span>
                        </span>

                        <a href="{{ route('portal.children.profile', $child['id']) }}"
                           class="shrink-0 rounded border border-border-primary px-3 py-1.5 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.guardian_portal.account_view_child') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- The design shows "Link Another Child" as an action. It is NOT one
             here: linking a child is a school decision (LinkGuardian gates on
             guardians.manage), and a parent who could self-link would be able
             to attach themselves to any child in the school. Stated, not
             offered. --}}
        <p class="mt-3 rounded border border-dashed border-border-primary px-3 py-2 text-xs text-charcoal/60">
            <span class="font-medium text-charcoal/80">{{ __('opes.guardian_portal.account_link_child') }}</span>
            &middot; {{ __('opes.guardian_portal.account_link_child_hint') }}
        </p>
    </section>

    <section aria-labelledby="portal-account-info" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <h2 id="portal-account-info" class="text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.account_information') }}
        </h2>

        <dl class="mt-3 divide-y divide-border-secondary text-sm">
            @foreach ([
                [__('opes.guardian_portal.account_type'), __('opes.guardian_portal.account_type_value')],
                [__('opes.guardian_portal.account_registered_on'), $registeredOn ?? __('opes.guardian_portal.account_none')],
                [__('opes.guardian_portal.account_last_login'), $lastLoginAt ?? __('opes.guardian_portal.account_none')],
                [__('opes.guardian_portal.account_language'), $guardian?->language?->value === 'fr' ? __('opes.guardian_portal.account_language') . ' — Français' : 'English'],
                [__('opes.guardian_portal.account_comm_preference'), $guardian?->preferred_contact_method?->value ?? __('opes.guardian_portal.account_none')],
            ] as [$label, $value])
                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-charcoal/60">{{ $label }}</dt>
                    <dd class="text-right font-medium text-charcoal">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section aria-labelledby="portal-account-actions" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <h2 id="portal-account-actions" class="text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.account_quick_actions') }}
        </h2>

        {{-- "Change Password" and "2FA" are absent, not disabled: neither
             exists on this surface, and a tile that appears to harden an
             account without doing so is worse than no tile. --}}
        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            @php
                $actions = [];
                if ($canEdit) {
                    $actions[] = ['portal.account.edit', __('opes.guardian_portal.account_personal_info')];
                    $actions[] = ['portal.account.notifications', __('opes.guardian_portal.account_notify')];
                }
                $actions[] = ['portal.account.security', __('opes.guardian_portal.security_title')];
                $actions[] = ['portal.help', __('opes.guardian_portal.help_title')];
            @endphp

            @foreach ($actions as [$routeName, $label])
                <a href="{{ route($routeName) }}"
                   class="flex min-h-20 flex-col items-center justify-center gap-1 rounded border border-border-primary px-2 py-3 text-center text-xs font-medium text-primary hover:border-primary/50 hover:bg-surface-green">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="portal-account-security" class="rounded border border-border-primary bg-surface-green p-4">
        <h2 id="portal-account-security" class="text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.security_title') }}
        </h2>
        <p class="mt-1 text-sm text-charcoal/70">
            {{ __('opes.guardian_portal.security_sessions') }}: {{ $sessionCount }}
            <span aria-hidden="true">&middot;</span>
            {{ __('opes.guardian_portal.security_devices') }}: {{ $deviceCount }}
        </p>
        <p class="mt-2 text-xs text-charcoal/60">{{ __('opes.guardian_portal.account_no_2fa') }}</p>

        <a href="{{ route('portal.account.security') }}"
           class="mt-3 inline-block rounded border border-primary px-3 py-1.5 text-xs font-semibold text-primary hover:bg-white">
            {{ __('opes.guardian_portal.security_title') }}
        </a>
    </section>
</div>
