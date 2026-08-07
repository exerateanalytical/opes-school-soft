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
    $defaultRoleTone = 'border-sand bg-sand/60 text-charcoal/70';

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
            @foreach (['Assign Role', 'User Permissions', 'Activity Log'] as $unbuilt)
                <li>
                    <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
                          class="flex cursor-not-allowed items-center gap-2 rounded px-2 py-1.5 text-sm text-white/40">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-white/30" aria-hidden="true"></span>
                        {{ $unbuilt }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endpush

<x-list-screen
    :title="__('opes.users.title')"
    :breadcrumb="[__('opes.users.breadcrumb_dashboard'), __('opes.users.breadcrumb_users')]"
    :paginator="$users"
    :empty-message="__('opes.users.empty')"
    rail-title="Status overview"
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
        <x-kpi-card label="Total users" :value="$users->total()" icon-bg="bg-primary" class="col-span-2 sm:col-span-1">
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
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="users-filter-role" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.role_label') }}</span>
            <select id="users-filter-role" wire:model.live="role"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($roleOptions as $roleOption)
                    <option value="{{ $roleOption->value }}">{{ $roleOption->label(app()->getLocale()) }}</option>
                @endforeach
            </select>
        </label>

        <label for="users-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.status_label') }}</span>
            <select id="users-filter-status" wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                <option value="active">{{ __('opes.users.status_active') }}</option>
                <option value="suspended">{{ __('opes.users.status_suspended') }}</option>
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.users.column_user') }}</th>
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
            <td class="px-4 py-2.5">
                @foreach ($user->roles as $userRole)
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $roleBadgeTone[$userRole->name] ?? $defaultRoleTone }}">
                        {{ $userRole->name }}
                    </span>
                @endforeach
            </td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$user->isSuspended() ? 'red' : 'ok'"
                                :label="$user->isSuspended() ? __('opes.users.status_suspended') : __('opes.users.status_active')"/>
            </td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ __('opes.users.never_logged_in') }}</td>
            <td class="px-4 py-2.5">
                <div class="flex items-center justify-end gap-1 text-charcoal/50">
                    <span class="rounded p-1.5" title="{{ __('opes.users.column_actions') }}">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    </span>
                    <span class="rounded p-1.5">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                    </span>
                    <span class="rounded p-1.5">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                    </span>
                </div>
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($users as $user)
            <article class="rounded border border-sand bg-white p-3">
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
        <div class="rounded border border-sand bg-white p-4">
            <div class="mx-auto flex h-32 w-32 items-center justify-center rounded-full"
                 style="background: conic-gradient(var(--color-primary) 0deg {{ $activeDeg }}deg, var(--color-heritage-red) {{ $activeDeg }}deg 360deg);">
                <div class="flex h-24 w-24 flex-col items-center justify-center rounded-full bg-white text-center">
                    <span class="text-lg font-semibold text-charcoal">{{ $users->getCollection()->count() }}</span>
                    <span class="text-[10px] text-charcoal/60">This page</span>
                </div>
            </div>
            <ul class="mt-3 space-y-1 text-xs text-charcoal/70">
                <li class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-primary" aria-hidden="true"></span>
                    {{ __('opes.users.status_active') }}: {{ $pageActive }}
                </li>
                <li class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-heritage-red" aria-hidden="true"></span>
                    {{ __('opes.users.status_suspended') }}: {{ $pageSuspended }}
                </li>
            </ul>
        </div>
    </x-slot:rail>
</x-list-screen>
