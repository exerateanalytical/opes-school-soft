{{-- `/portal/children/{s}/attendance` - built to mobile/attendance.png. --}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'attendance',
    ])

    @if ($summaries->isEmpty())
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="calendar" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.attendance_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        @php $latest = $summaries->first(); @endphp

        {{-- The headline tiles the design leads with. --}}
        <x-portal.card :padded="false">
            <div class="grid grid-cols-2 divide-x divide-y divide-border-secondary sm:grid-cols-4 sm:divide-y-0">
                <x-portal.stat icon="check" tone="success"
                               :label="__('opes.guardian_portal.attendance_present')"
                               :value="(string) $latest->sessions_present"/>
                <x-portal.stat icon="alert" tone="danger"
                               :label="__('opes.guardian_portal.attendance_absent')"
                               :value="(string) $latest->sessions_absent"/>
                <x-portal.stat icon="clock" tone="warning"
                               :label="__('opes.guardian_portal.attendance_late')"
                               :value="(string) $latest->sessions_late"/>
                <x-portal.stat icon="calendar" tone="primary"
                               :label="__('opes.guardian_portal.attendance_expected')"
                               :value="(string) $latest->sessions_expected"/>
            </div>
        </x-portal.card>

        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.attendance_summary')" icon="chart"/>
            </div>

            <div class="divide-y divide-border-secondary pb-1">
                @foreach ($summaries as $summary)
                    @php
                        $expected = (int) $summary->sessions_expected;
                        $rate = $expected > 0 ? (int) round(((int) $summary->sessions_present / $expected) * 100) : null;
                    @endphp

                    <x-portal.row wire:key="att-{{ $loop->index }}"
                                  :title="app()->getLocale() === 'fr' && $summary->period_name_fr ? $summary->period_name_fr : $summary->period_name"
                                  :subtitle="__('opes.guardian_portal.attendance_present').': '.$summary->sessions_present.'  •  '.__('opes.guardian_portal.attendance_absent').': '.$summary->sessions_absent"
                                  icon="calendar" tone="primary"
                                  :trailing="$rate === null ? null : $rate.'%'"
                                  :trailingTone="$rate === null ? null : ($rate >= 90 ? 'success' : 'warning')"
                                  :chevron="false"/>
                @endforeach
            </div>
        </x-portal.card>
    @endif

    @if (! $canDetail)
        {{-- Said plainly. An empty session table would read as "your child has
             never been marked", which is a very different and much more
             alarming claim than "your school shares totals with you". --}}
        <x-portal.card tone="green" class="flex items-start gap-3">
            <x-portal.icon name="help" tone="primary" size="sm"/>
            <p class="text-sm text-charcoal/70">{{ __('opes.guardian_portal.attendance_summary_only') }}</p>
        </x-portal.card>
    @elseif ($records->isNotEmpty())
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.attendance_detail')" icon="clock"/>
            </div>

            <div class="divide-y divide-border-secondary pb-1">
                @foreach ($records as $record)
                    <x-portal.row wire:key="att-rec-{{ $loop->index }}"
                                  :title="(string) $record->session_date"
                                  :subtitle="trim(($record->session ?? '').($record->is_justified ? '  •  '.__('opes.guardian_portal.attendance_justified') : ''))"
                                  :icon="$record->status === 'present' ? 'check' : 'alert'"
                                  :tone="$record->status === 'present' ? 'success' : ($record->status === 'absent' ? 'danger' : 'warning')"
                                  :trailing="(string) $record->status"
                                  :trailingTone="$record->status === 'present' ? 'success' : ($record->status === 'absent' ? 'danger' : 'warning')"
                                  :chevron="false"/>
                @endforeach
            </div>
        </x-portal.card>
    @endif
</div>
