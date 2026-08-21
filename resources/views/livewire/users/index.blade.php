@php
    // Role -> badge colour. A single, central mapping so pill colour is never
    // decided ad hoc per row (task brief). Anything not listed here (most of
    // the 19 Role cases - this screen has only ever been exercised with a
    // handful of them) falls back to a neutral sand/charcoal pill rather than
    // guessing at a colour that was never confirmed against the mockup.
    $roleBadgeTone = [
        'super_admin' => 'border-badge-purple/30 bg-badge-purple/10 text-badge-purple',
        'administrator' => 'border-badge-blue/30 bg-badge-blue/10 text-badge-blue',
        'teacher' => 'border-primary/30 bg-primary/10 text-primary',
        'class_master' => 'border-primary/30 bg-primary/10 text-primary',
        'discipline_master' => 'border-primary/30 bg-primary/10 text-primary',
        'librarian' => 'border-badge-orange/30 bg-badge-orange/10 text-badge-orange',
        'accountant' => 'border-badge-teal/30 bg-badge-teal/10 text-badge-teal',
        'bursar' => 'border-badge-teal/30 bg-badge-teal/10 text-badge-teal',
    ];
    $defaultRoleTone = 'border-border-primary bg-sand/60 text-charcoal/70';

    // This page's fetched rows only - NOT a system-wide split. A true
    // active/suspended split across every user needs a dedicated count query
    // this pass does not add (see the KPI row comment below for why).
    $pageActive = $users->getCollection()->filter(fn ($u) => ! $u->isSuspended())->count();
    $pageSuspended = $users->getCollection()->count() - $pageActive;
    $pageTotal = max($users->getCollection()->count(), 1);
    $activeDeg = round(360 * $pageActive / $pageTotal);
@endphp

{{-- Sidebar quick actions for this screen. "Add user" is a real, working
     link; the rest mirror unbuilt-feature nav items (aria-disabled + the
     standard "arrives later" title) rather than linking to nothing. --}}
@push('sidebar-quick-actions')
    <div class="mx-3 mt-auto rounded-lg border border-heritage-yellow/70 p-3">
        <h2 class="text-xs font-bold uppercase tracking-wide text-heritage-yellow">
            {{ __('opes.dashboard.quick_actions') }}
        </h2>
        <ul class="mt-2 space-y-1">
            <li>
                @can('user.manage')
                    <a href="{{ route('users.create') }}"
                       class="flex items-center gap-2 rounded px-2 py-1.5 text-sm text-white/90 hover:bg-chrome-light">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                        {{ __('opes.users.add_user') }}
                    </a>
                @endcan
            </li>
            {{-- "Activity Log" was inert; /audit-log has existed for some time
                 and is in the sidebar two panels away, so the box was greying
                 out a link to a screen the reader can already reach.

                 "User Permissions" is GONE rather than wired: there is no
                 permissions screen, and permissions are granted through a
                 user's ROLE on this very page. A menu entry for a screen that
                 does not exist teaches the reader that the menu lies. --}}
            @can('audit.view')
                <li>
                    <a href="{{ route('audit.index') }}" wire:navigate
                       class="flex items-center gap-2 rounded px-2 py-1.5 text-sm text-white/90 hover:bg-chrome-light">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                        {{ __('opes.nav.audit_log') }}
                    </a>
                </li>
            @endcan
        </ul>
    </div>
@endpush

<x-list-screen
    :title="__('opes.users.title')"
    :breadcrumb="[__('opes.users.breadcrumb_dashboard'), __('opes.users.breadcrumb_users')]"
    :paginator="$users"
    :empty-message="__('opes.users.empty')"
>
    <x-slot:actions>
        @can('user.manage')
            <a href="{{ route('users.create') }}"
               class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('opes.users.add_user') }}
            </a>
        @endcan
    </x-slot:actions>

    {{-- Only "Total users" is real, dataset-wide data ($users->total(), the
         paginator's actual count). The mockup also shows Active/Administrators/
         Teachers/Students tiles, but those need dedicated count queries this
         view-only fidelity pass does not add (Index.php is out of this pass's
         file scope) - adding them here would mean fabricating numbers, which
         the brief explicitly forbids. Flagged as a follow-up, not faked. --}}
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.users.kpi_total')" :value="$userStats['total']" icon-bg="bg-primary" class="col-span-2 sm:col-span-1">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path stroke-linecap="round" d="M2.8 19.5c0-3.4 2.8-6.2 6.2-6.2s6.2 2.8 6.2 6.2"/><path stroke-linecap="round" d="M15.5 8.3a2.8 2.8 0 110 5.6M20.5 19.5c0-2.6-1.9-4.8-4.4-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        {{-- Counted off the Spatie role tables, not off a column on users:
             that is where role membership actually lives, and a users.role
             column would be a second source of truth for the same fact.

             The reference's fifth tile says "Students". This product does NOT
             give a pupil a back-office login - their guardian gets one - so
             the tile counts guardians and says so. Labelling a guardian count
             "Students" would be a plain untruth on a screen whose whole job
             is who can sign in. --}}
        <x-kpi-card :label="__('opes.users.kpi_active')" :value="$userStats['active']"
                    :sub="__('opes.users.kpi_active_sub')" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3v5.5c0 4.2-3 7.9-7.5 9-4.5-1.1-7.5-4.8-7.5-9V6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.users.kpi_administrators')" :value="$userStats['administrators']"
                    :sub="__('opes.users.kpi_administrators_sub')" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="3.4"/><path stroke-linecap="round" d="M5 20c0-3.9 3.1-7 7-7s7 3.1 7 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.users.kpi_teachers')" :value="$userStats['teachers']"
                    :sub="__('opes.users.kpi_teachers_sub')" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4l9 4.5-9 4.5-9-4.5L12 4z"/><path stroke-linecap="round" d="M6 10.5V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.users.kpi_guardians')" :value="$userStats['guardians']"
                    :sub="__('opes.users.kpi_guardians_sub')" icon-bg="bg-badge-teal">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path stroke-linecap="round" d="M2.8 19.5c0-3.4 2.8-6.2 6.2-6.2s6.2 2.8 6.2 6.2"/><path stroke-linecap="round" d="M15.5 8.3a2.8 2.8 0 110 5.6M20.5 19.5c0-2.6-1.9-4.8-4.4-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="users-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.search_label') }}</span>
            <input id="users-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('opes.users.search_placeholder') }}"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="users-filter-role" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.role_label') }}</span>
            <select id="users-filter-role" wire:model.live="role"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($roleOptions as $roleOption)
                    <option value="{{ $roleOption->value }}">{{ $roleOption->label(app()->getLocale()) }}</option>
                @endforeach
            </select>
        </label>

        <label for="users-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.status_label') }}</span>
            <select id="users-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                <option value="active">{{ __('opes.users.status_active') }}</option>
                <option value="suspended">{{ __('opes.users.status_suspended') }}</option>
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.users.column_user') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.users.column_username') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.users.column_role') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.users.column_status') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.users.column_last_login') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.users.column_actions') }}</th>
        </tr>
    </x-slot:head>

    @foreach ($users as $user)
        <tr>
            <td class="px-4 py-2.5">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-chrome-light text-xs font-semibold uppercase text-white">
                        {{ mb_substr($user->name, 0, 1) }}
                    </span>
                    <div class="min-w-0">
                        <div class="truncate font-medium text-charcoal">{{ $user->name }}</div>
                        <div class="truncate text-xs text-charcoal/60">{{ $user->email }}</div>
                    </div>
                </div>
            </td>
            {{-- The username is what a user actually types to sign in, and the
                 search box already matches on it - a column the search can
                 find but the table cannot show is a column the reader has to
                 take on trust. Monospace because it is an identifier, and an
                 em dash where an older account has never been given one. --}}
            <td class="px-4 py-2.5 font-mono text-xs text-charcoal/75">
                {{ $user->username ?? '—' }}
            </td>
            <td class="px-4 py-2.5">
                @foreach ($user->roles as $userRole)
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $roleBadgeTone[$userRole->name] ?? $defaultRoleTone }}">
                        {{ \App\Modules\Identity\Domain\Role::tryFrom($userRole->name)?->label(app()->getLocale()) ?? $userRole->name }}
                    </span>
                @endforeach
            </td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$user->isSuspended() ? 'red' : 'ok'"
                                :label="$user->isSuspended() ? __('opes.users.status_suspended') : __('opes.users.status_active')"/>
            </td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ __('opes.users.never_logged_in') }}</td>
            <td class="px-4 py-2.5">
                <div class="flex flex-wrap items-center justify-end gap-1 text-xs">
                    @can('role.assign')
                        <button type="button" wire:click="toggleRoleForm({{ $user->id }})"
                                class="rounded border border-border-primary px-2 py-1 font-medium text-charcoal hover:bg-sand/40">
                            {{ __('opes.users.change_role') }}
                        </button>
                    @endcan
                    @can('user.set_password')
                        <button type="button" wire:click="togglePasswordForm({{ $user->id }})"
                                class="rounded border border-border-primary px-2 py-1 font-medium text-charcoal hover:bg-sand/40">
                            {{ __('opes.users.reset_password') }}
                        </button>
                    @endcan
                    @can('api.manage_tokens')
                        <a href="{{ route('users.tokens', $user) }}"
                           class="rounded border border-border-primary px-2 py-1 font-medium text-charcoal hover:bg-sand/40">
                            {{ __('opes.users.api_tokens') }}
                        </a>
                    @endcan
                </div>
            </td>
        </tr>

        @if ($roleFormUserId === $user->id)
            <tr>
                <td colspan="5" class="border-t-0 bg-sand/20 px-4 py-3">
                    <form wire:submit.prevent="changeRole({{ $user->id }})" class="flex flex-wrap items-end gap-3">
                        <label for="role-select-{{ $user->id }}" class="flex min-w-[10rem] flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.role_field_label') }}</span>
                            <select id="role-select-{{ $user->id }}" wire:model="selectedRole"
                                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                                <option value="">{{ __('opes.users.role_placeholder') }}</option>
                                @foreach ($roleOptions as $roleOption)
                                    <option value="{{ $roleOption->value }}">{{ $roleOption->label(app()->getLocale()) }}</option>
                                @endforeach
                            </select>
                            @error('selectedRole')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                        <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.users.save') }}
                        </button>
                        <button type="button" wire:click="toggleRoleForm({{ $user->id }})"
                                class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:bg-sand/40">
                            {{ __('opes.users.cancel') }}
                        </button>
                    </form>
                </td>
            </tr>
        @endif

        @if ($passwordFormUserId === $user->id)
            <tr>
                <td colspan="5" class="border-t-0 bg-sand/20 px-4 py-3">
                    <form wire:submit.prevent="setPassword({{ $user->id }})" class="flex flex-wrap items-end gap-3">
                        <label for="new-password-{{ $user->id }}" class="flex min-w-[10rem] flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.new_password_label') }}</span>
                            <input id="new-password-{{ $user->id }}" type="password" wire:model="newPassword"
                                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                            @error('newPassword')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                        <label for="new-password-confirm-{{ $user->id }}" class="flex min-w-[10rem] flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.new_password_confirmation_label') }}</span>
                            <input id="new-password-confirm-{{ $user->id }}" type="password" wire:model="newPasswordConfirmation"
                                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.users.save') }}
                        </button>
                        <button type="button" wire:click="togglePasswordForm({{ $user->id }})"
                                class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:bg-sand/40">
                            {{ __('opes.users.cancel') }}
                        </button>
                    </form>
                </td>
            </tr>
        @endif
    @endforeach

    <x-slot:cards>
        @foreach ($users as $user)
            <article class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-chrome-light text-xs font-semibold uppercase text-white">
                            {{ mb_substr($user->name, 0, 1) }}
                        </span>
                        <div>
                            <div class="font-medium text-charcoal">{{ $user->name }}</div>
                            <div class="text-xs text-charcoal/60">{{ $user->email }}</div>
                        </div>
                    </div>
                    <x-status-pill :status="$user->isSuspended() ? 'red' : 'ok'"
                                    :label="$user->isSuspended() ? __('opes.users.status_suspended') : __('opes.users.status_active')"/>
                </div>
                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.users.column_role') }}</dt>
                        <dd>
                            @foreach ($user->roles as $userRole)
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $roleBadgeTone[$userRole->name] ?? $defaultRoleTone }}">
                                    {{ $userRole->name }}
                                </span>
                            @endforeach
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.users.column_last_login') }}</dt>
                        <dd>{{ __('opes.users.never_logged_in') }}</dd>
                    </div>
                </dl>
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail. The mockup shows a donut chart here; a real charting
         library is a dependency decision the owner has not made, so this pass
         deliberately does NOT integrate one (task brief). Instead: a
         CSS-only conic-gradient ring built from real active/suspended counts
         for the rows actually on screen (see $pageActive/$pageSuspended
         above) - honestly scoped to "this page", not oversold as system-wide. --}}
    <x-slot:rail>
        <x-shell.panel :title="__('opes.users.rail_role_distribution')">
            <x-shell.donut :slices="$roleDistribution"
                           :centre-value="number_format($userStats['total'])"
                           :centre-label="__('opes.users.kpi_total')"
                           stacked
                           :size="132"
                           :thickness="22"
                           class="py-1"/>
        </x-shell.panel>
    </x-slot:rail>
</x-list-screen>
