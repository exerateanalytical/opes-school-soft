<div class="min-w-0 space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.staff_portal.title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.staff_portal.greeting', ['name' => $staff->first_name ?? '']) }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded border border-border-primary bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.profile') }}</h2>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.staff_no') }}</dt><dd class="font-mono">{{ $staff->staff_no ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.name') }}</dt><dd>{{ trim(($staff->first_name ?? '').' '.($staff->last_name ?? '')) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.role') }}</dt><dd>{{ $contract->contract_role ?? __('opes.staff_portal.no_active_contract') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.phone') }}</dt><dd>{{ $staff->phone ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.staff_portal.email') }}</dt><dd>{{ $staff->email ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="rounded border border-border-primary bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.change_password') }}</h2>

            <form wire:submit.prevent="changePassword" class="mt-2 space-y-2">
                <div>
                    <label for="staff-portal-current" class="block text-xs text-charcoal/60">{{ __('opes.staff_portal.password_current') }}</label>
                    <input id="staff-portal-current" type="password" wire:model="currentPassword"
                           class="mt-0.5 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                    @error('currentPassword') <p class="mt-0.5 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="staff-portal-new" class="block text-xs text-charcoal/60">{{ __('opes.staff_portal.password_new') }}</label>
                    <input id="staff-portal-new" type="password" wire:model="newPassword"
                           class="mt-0.5 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                    @error('newPassword') <p class="mt-0.5 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="staff-portal-confirm" class="block text-xs text-charcoal/60">{{ __('opes.staff_portal.password_confirm') }}</label>
                    <input id="staff-portal-confirm" type="password" wire:model="newPasswordConfirmation"
                           class="mt-0.5 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                    @error('newPasswordConfirmation') <p class="mt-0.5 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('opes.staff_portal.password_save') }}
                </button>
            </form>
        </div>

        <div class="rounded border border-border-primary bg-white p-4 sm:col-span-2">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.panel_timetable') }}</h2>

            @php
                $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
            @endphp

            @if (count($timetableSlots) === 0)
                <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.staff_portal.panel_scheduled') }}</p>
            @else
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-charcoal/60">
                                <th class="py-1 pr-3">Day</th>
                                <th class="py-1 pr-3">Period</th>
                                <th class="py-1 pr-3">Subject</th>
                                <th class="py-1 pr-3">Class</th>
                                <th class="py-1 pr-3">Room</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($timetableSlots as $slot)
                                <tr class="border-t border-border-primary/60">
                                    <td class="py-1 pr-3">{{ $dayNames[$slot->day_of_week] ?? $slot->day_of_week }}</td>
                                    <td class="py-1 pr-3">{{ $slot->period_name }}</td>
                                    <td class="py-1 pr-3">{{ $slot->subject_name }}</td>
                                    <td class="py-1 pr-3">{{ $slot->class_group_name }}</td>
                                    <td class="py-1 pr-3">{{ $slot->room_name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="rounded border border-border-primary bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.panel_leave') }}</h2>

            @if (count($leaveBalances) === 0)
                <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.staff_portal.panel_scheduled') }}</p>
            @else
                <dl class="mt-2 space-y-1 text-sm">
                    @foreach ($leaveBalances as $balance)
                        <div class="flex justify-between gap-3">
                            <dt class="text-charcoal/60">{{ $balance->leave_type_name }}</dt>
                            <dd class="font-mono">{{ number_format((float) $balance->balance, 2) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if (count($leaveRequests) > 0)
                <div class="mt-3 space-y-1 border-t border-border-primary/60 pt-2 text-xs">
                    @foreach ($leaveRequests as $request)
                        <div class="flex justify-between gap-3">
                            <span class="text-charcoal/60">{{ $request->leave_type_name }} · {{ $request->starts_on }} → {{ $request->ends_on }}</span>
                            <span class="font-medium">{{ $request->status }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded border border-border-primary bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.staff_portal.panel_payslips') }}</h2>

            @if (count($payslips) === 0)
                <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.staff_portal.panel_scheduled') }}</p>
            @else
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($payslips as $payslip)
                        <li class="flex items-center justify-between gap-3">
                            <span>{{ $payslip->payroll_month }} ({{ $payslip->run_type }}) — {{ number_format($payslip->net) }}</span>
                            <button type="button" wire:click="downloadPayslip({{ $payslip->payroll_item_id }})"
                                    class="rounded border border-primary px-2 py-1 text-xs font-medium text-primary hover:bg-primary hover:text-white">
                                Download
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
