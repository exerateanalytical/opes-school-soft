<div class="min-w-0 space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.staff_portal.title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.staff_portal.greeting', ['name' => $staff->first_name ?? '']) }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded border border-sand bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.profile') }}</h2>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.staff_no') }}</dt><dd class="font-mono">{{ $staff->staff_no ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.name') }}</dt><dd>{{ trim(($staff->first_name ?? '').' '.($staff->last_name ?? '')) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.role') }}</dt><dd>{{ $contract->contract_role ?? __('opes.staff_portal.no_active_contract') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.phone') }}</dt><dd>{{ $staff->phone ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.email') }}</dt><dd>{{ $staff->email ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="rounded border border-sand bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.change_password') }}</h2>

            <form wire:submit.prevent="changePassword" class="mt-2 space-y-2">
                <div>
                    <label for="staff-portal-current" class="block text-xs text-charcoal/60">{{ __('opes.staff_portal.password_current') }}</label>
                    <input id="staff-portal-current" type="password" wire:model="currentPassword"
                           class="mt-0.5 w-full rounded border border-sand px-2 py-1.5 text-sm">
                    @error('currentPassword') <p class="mt-0.5 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="staff-portal-new" class="block text-xs text-charcoal/60">{{ __('opes.staff_portal.password_new') }}</label>
                    <input id="staff-portal-new" type="password" wire:model="newPassword"
                           class="mt-0.5 w-full rounded border border-sand px-2 py-1.5 text-sm">
                    @error('newPassword') <p class="mt-0.5 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="staff-portal-confirm" class="block text-xs text-charcoal/60">{{ __('opes.staff_portal.password_confirm') }}</label>
                    <input id="staff-portal-confirm" type="password" wire:model="newPasswordConfirmation"
                           class="mt-0.5 w-full rounded border border-sand px-2 py-1.5 text-sm">
                    @error('newPasswordConfirmation') <p class="mt-0.5 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('opes.staff_portal.password_save') }}
                </button>
            </form>
        </div>

        @foreach (['timetable', 'leave', 'payslips'] as $panel)
            <div class="rounded border border-dashed border-sand bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.panel_'.$panel) }}</h2>
                <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.staff_portal.panel_scheduled') }}</p>
            </div>
        @endforeach
    </div>
</div>
