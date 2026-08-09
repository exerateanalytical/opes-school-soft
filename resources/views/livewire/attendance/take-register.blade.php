{{-- Take Attendance — 09-ui §8.7, after the 'Attendance.png' mockup: filter
     bar (class · date · session), roster with Present/Absent/Late/Excused
     radios, Mark All Present / Clear All / one Save. Radios are deferred
     wire:model — NOTHING round-trips until Save (07-students §9.9's one-
     batched-save contract); the KPI chips are tallied client-side by Alpine. --}}

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('attendance.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>{{ __('attendance.breadcrumb_attendance') }}</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('attendance.take_title') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('attendance.take_title') }}</h1>
    </div>

    @error('save')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">{{ $message }}</p>
    @enderror
    @error('date')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">{{ $message }}</p>
    @enderror
    @error('marks')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">{{ $message }}</p>
    @enderror
    @error('register')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">{{ $message }}</p>
    @enderror
    @error('timetable_slot_id')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">{{ $message }}</p>
    @enderror

    {{-- ── Filter bar: class · date · session (· slot in per-lesson) ──── --}}
    <div class="rounded border border-sand bg-white p-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block text-xs font-medium text-charcoal/70">
                {{ __('attendance.filter_class') }}
                <select wire:model.live="classGroupId"
                        class="mt-1 w-full rounded border-sand text-sm focus:border-primary focus:ring-primary">
                    <option value="">{{ __('attendance.select_class') }}</option>
                    @foreach ($classGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-xs font-medium text-charcoal/70">
                {{ __('attendance.filter_date') }}
                <input type="date" wire:model.live="date"
                       class="mt-1 w-full rounded border-sand text-sm focus:border-primary focus:ring-primary"/>
            </label>

            @if (! $perLesson)
                <label class="block text-xs font-medium text-charcoal/70">
                    {{ __('attendance.filter_session') }}
                    <select wire:model.live="session"
                            class="mt-1 w-full rounded border-sand text-sm focus:border-primary focus:ring-primary">
                        <option value="full_day">{{ __('attendance.session.full_day') }}</option>
                        <option value="morning">{{ __('attendance.session.morning') }}</option>
                        <option value="afternoon">{{ __('attendance.session.afternoon') }}</option>
                    </select>
                </label>
            @else
                <label class="block text-xs font-medium text-charcoal/70">
                    {{ __('attendance.filter_slot') }}
                    <select wire:model.live="timetableSlotId"
                            class="mt-1 w-full rounded border-sand text-sm focus:border-primary focus:ring-primary">
                        <option value="">{{ __('attendance.select_slot') }}</option>
                        @foreach ($slots as $slot)
                            <option value="{{ $slot->id }}">{{ $slot->period_name }} — {{ $slot->subject_name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
    </div>

    @if ($classGroupId === '')
        <x-empty-state :message="__('attendance.pick_class')"/>
    @elseif ($roster === [])
        <x-empty-state :message="__('attendance.empty_roster')"/>
    @else
        @php
            $selectedName = optional($classGroups->firstWhere('id', (int) $classGroupId))->name;
        @endphp

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            {{-- ── Roster card ───────────────────────────────────────── --}}
            <div class="min-w-0 xl:col-span-2"
                 x-data="{
                     counts: { present: 0, absent: 0, late: 0, excused: 0, sick: 0, suspended: 0 },
                     tally() {
                         const c = { present: 0, absent: 0, late: 0, excused: 0, sick: 0, suspended: 0 };
                         this.$root.querySelectorAll('input[type=radio]:checked').forEach((r) => {
                             if (c[r.value] !== undefined) c[r.value]++;
                         });
                         this.counts = c;
                     },
                 }"
                 x-init="tally()"
                 @change="tally()">
                <div class="rounded border border-sand bg-white">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-sand px-4 py-3">
                        <h2 class="text-sm font-semibold text-charcoal">
                            {{ __('attendance.taking_for', ['class' => $selectedName]) }}
                        </h2>
                        <p class="text-xs text-charcoal/60">{{ __('attendance.date_label') }}: {{ $date }}</p>
                    </div>

                    {{-- KPI chips, Alpine-tallied — no request per tap. --}}
                    <div class="grid grid-cols-2 gap-2 px-4 pt-3 sm:grid-cols-4">
                        <div class="rounded border border-primary/30 bg-primary/5 px-3 py-2">
                            <p class="text-xs font-medium text-charcoal/60">{{ __('attendance.status.present') }}</p>
                            <p class="text-xl font-semibold text-primary" x-text="counts.present"></p>
                        </div>
                        <div class="rounded border border-heritage-red/30 bg-heritage-red/5 px-3 py-2">
                            <p class="text-xs font-medium text-charcoal/60">{{ __('attendance.status.absent') }}</p>
                            <p class="text-xl font-semibold text-heritage-red" x-text="counts.absent + counts.sick"></p>
                        </div>
                        <div class="rounded border border-heritage-yellow/50 bg-heritage-yellow/10 px-3 py-2">
                            <p class="text-xs font-medium text-charcoal/60">{{ __('attendance.status.late') }}</p>
                            <p class="text-xl font-semibold text-charcoal" x-text="counts.late"></p>
                        </div>
                        <div class="rounded border border-sand bg-cream px-3 py-2">
                            <p class="text-xs font-medium text-charcoal/60">{{ __('attendance.total_students') }}</p>
                            <p class="text-xl font-semibold text-charcoal">{{ count($roster) }}</p>
                        </div>
                    </div>

                    @if ($isTaken)
                        <div class="mx-4 mt-3 rounded border border-heritage-yellow bg-heritage-yellow/10 px-3 py-2 text-sm text-charcoal" role="status">
                            {{ __('attendance.already_taken', ['status' => $register?->status->label() ?? '']) }}
                        </div>
                    @endif

                    <div class="-mx-px mt-3 overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-cream text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">#</th>
                                    <th scope="col" class="px-4 py-2">{{ __('attendance.col_admission_no') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('attendance.col_full_name') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('attendance.col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($roster as $row)
                                    @php
                                        $enrollmentId = (int) $row->enrollment_id;
                                        $isSuspended = (string) $row->enrollment_status === 'suspended';
                                        $disabled = $isTaken && ! $canAmend;
                                    @endphp
                                    <tr wire:key="roster-{{ $enrollmentId }}">
                                        <td class="px-4 py-2 text-charcoal/60">{{ $loop->iteration }}</td>
                                        <td class="px-4 py-2 font-mono text-xs text-charcoal/80">{{ $row->admission_no ?? $row->matricule }}</td>
                                        <td class="px-4 py-2 font-medium text-charcoal">
                                            {{ $row->first_name }} {{ $row->last_name }}
                                            @if ($isSuspended)
                                                <x-status-pill status="amber" :label="__('attendance.status.suspended')" class="ml-1"/>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2">
                                            @if ($isSuspended)
                                                {{-- §9.5: suspended students stay in expected and are
                                                     recorded as suspended — not a teacher's choice. --}}
                                                <input type="hidden" wire:model="marks.{{ $enrollmentId }}"/>
                                                <span class="text-xs text-charcoal/60">{{ __('attendance.suspended_note') }}</span>
                                            @else
                                                <div class="flex flex-wrap items-center gap-3">
                                                    @foreach ($statusOptions as $option)
                                                        <label class="inline-flex items-center gap-1 text-xs text-charcoal/80">
                                                            <input type="radio"
                                                                   name="mark-{{ $enrollmentId }}"
                                                                   value="{{ $option->value }}"
                                                                   wire:model="marks.{{ $enrollmentId }}"
                                                                   @disabled($disabled)
                                                                   class="h-3.5 w-3.5 border-sand text-primary focus:ring-primary"/>
                                                            {{ $option->label() }}
                                                        </label>
                                                    @endforeach
                                                    @if (($marks[$enrollmentId] ?? '') === 'late')
                                                        <input type="number" min="1" max="480" placeholder="{{ __('attendance.minutes') }}"
                                                               wire:model="minutesLate.{{ $enrollmentId }}"
                                                               class="w-20 rounded border-sand text-xs focus:border-primary focus:ring-primary"/>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-sand px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="markAllPresent"
                                    class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('attendance.mark_all_present') }}
                            </button>
                            <button type="button" wire:click="clearAll"
                                    class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('attendance.clear_all') }}
                            </button>
                        </div>

                        @if (! $isTaken)
                            <button type="button" wire:click="save"
                                    class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                                {{ __('attendance.save') }}
                            </button>
                        @elseif ($canAmend)
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="text" wire:model="amendReason"
                                       placeholder="{{ __('attendance.amend_reason_placeholder') }}"
                                       class="w-56 rounded border-sand text-sm focus:border-primary focus:ring-primary"/>
                                <button type="button" wire:click="amend"
                                        class="rounded border border-chrome bg-chrome px-4 py-1.5 text-sm font-semibold text-white hover:bg-chrome-light">
                                    {{ __('attendance.save_amendment') }}
                                </button>
                            </div>
                        @endif
                    </div>
                    @error('amendReason')
                        <p class="px-4 pb-3 text-sm text-heritage-red" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- ── Right rail: saved-register summary ─────────────────── --}}
            <div class="min-w-0 space-y-4">
                <div class="rounded border border-sand bg-white p-4">
                    <h2 class="text-sm font-semibold text-charcoal">{{ __('attendance.summary_title') }}</h2>

                    @if ($register === null)
                        {{-- No register: the summary is NOT RECORDED — an em
                             dash, never 0% (C5). --}}
                        <p class="mt-3 text-2xl font-semibold text-charcoal" title="{{ __('opes.ui.no_data') }}">—</p>
                        <p class="mt-1 text-xs text-charcoal/60">{{ __('attendance.no_register_yet') }}</p>
                    @else
                        @php
                            $denominator = $register->expected_count
                                - $register->records->where('status', \App\Modules\Attendance\Domain\AttendanceStatus::Suspended)->count();
                            $rate = $denominator > 0
                                ? round(($register->present_count + $register->late_count) / $denominator * 100, 1)
                                : null;
                        @endphp
                        <p class="mt-3 text-2xl font-semibold {{ $rate === null ? 'text-charcoal' : 'text-primary' }}">
                            {{ $rate === null ? '—' : number_format($rate, 1).'%' }}
                        </p>
                        <dl class="mt-3 space-y-1 text-sm">
                            <div class="flex justify-between"><dt class="text-charcoal/60">{{ __('attendance.expected') }}</dt><dd class="font-medium">{{ $register->expected_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-charcoal/60">{{ __('attendance.status.present') }}</dt><dd class="font-medium">{{ $register->present_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-charcoal/60">{{ __('attendance.status.absent') }}</dt><dd class="font-medium">{{ $register->absent_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-charcoal/60">{{ __('attendance.status.late') }}</dt><dd class="font-medium">{{ $register->late_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-charcoal/60">{{ __('attendance.status.excused') }}</dt><dd class="font-medium">{{ $register->excused_count }}</dd></div>
                        </dl>
                        <p class="mt-3">
                            <x-status-pill :status="$register->status === \App\Modules\Attendance\Domain\RegisterStatus::Open ? 'amber' : 'ok'"
                                           :label="$register->status->label()"/>
                        </p>
                    @endif
                </div>

                <div class="rounded border border-sand bg-white p-4">
                    <h2 class="text-sm font-semibold text-charcoal">{{ __('attendance.quick_actions') }}</h2>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li>
                            <a href="{{ url('/attendance') }}" class="text-primary hover:underline">
                                {{ __('attendance.title') }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ url('/attendance/coverage') }}" class="text-primary hover:underline">
                                {{ __('attendance.coverage_title') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    @endif
</div>
