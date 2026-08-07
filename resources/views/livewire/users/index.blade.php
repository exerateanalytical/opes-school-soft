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

    <x-slot:filters>
        <label class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.search_label') }}</span>
            <input type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('opes.users.search_placeholder') }}"
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.role_label') }}</span>
            <select wire:model.live="role"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($roleOptions as $roleOption)
                    <option value="{{ $roleOption->value }}">{{ $roleOption->label(app()->getLocale()) }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.status_label') }}</span>
            <select wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                <option value="active">{{ __('opes.users.status_active') }}</option>
                <option value="suspended">{{ __('opes.users.status_suspended') }}</option>
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr>
            <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.users.column_user') }}</th>
            <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.users.column_role') }}</th>
            <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.users.column_status') }}</th>
            <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.users.column_last_login') }}</th>
            <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.users.column_actions') }}</th>
        </tr>
    </x-slot:head>

    @foreach ($users as $user)
        <tr>
            <td class="px-4 py-2">
                <div class="font-medium text-charcoal">{{ $user->name }}</div>
                <div class="text-xs text-charcoal/60">{{ $user->email }}</div>
            </td>
            <td class="px-4 py-2 text-charcoal">
                {{ $user->roles->map(fn ($role) => $role->name)->implode(', ') }}
            </td>
            <td class="px-4 py-2">
                <x-status-pill :status="$user->isSuspended() ? 'red' : 'ok'"
                                :label="$user->isSuspended() ? __('opes.users.status_suspended') : __('opes.users.status_active')"/>
            </td>
            <td class="px-4 py-2 text-charcoal/70">{{ __('opes.users.never_logged_in') }}</td>
            <td class="px-4 py-2 text-charcoal/70">&nbsp;</td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($users as $user)
            <article class="rounded border border-sand bg-white p-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-medium text-charcoal">{{ $user->name }}</div>
                        <div class="text-xs text-charcoal/60">{{ $user->email }}</div>
                    </div>
                    <x-status-pill :status="$user->isSuspended() ? 'red' : 'ok'"
                                    :label="$user->isSuspended() ? __('opes.users.status_suspended') : __('opes.users.status_active')"/>
                </div>
                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.users.column_role') }}</dt>
                        <dd>{{ $user->roles->map(fn ($role) => $role->name)->implode(', ') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.users.column_last_login') }}</dt>
                        <dd>{{ __('opes.users.never_logged_in') }}</dd>
                    </div>
                </dl>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
