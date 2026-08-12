{{-- `/portal/children/{s}/assignments` - mobile/assignments.png. --}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'timetable',
    ])

    {{-- Homework has no guardian endpoint and no matrix row. Wiring this to
         the staff tables would bypass the 32-row table entirely, so the gap is
         stated rather than filled. --}}
    <x-portal.card tone="green" class="flex items-start gap-3">
        <x-portal.icon name="help" tone="primary" size="sm"/>
        <p class="text-sm text-charcoal/70">{{ __('opes.guardian_portal.academics_assignments_note') }}</p>
    </x-portal.card>

    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.timetable_title')" icon="clock"/>
        </div>

        @if ($timetableSlots->isEmpty())
            <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.timetable_empty') }}</p>
        @else
            <div class="divide-y divide-border-secondary pb-1">
                @foreach ($timetableSlots as $slot)
                    <div wire:key="asg-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3 sm:px-5">
                        <span class="w-20 shrink-0 text-xs font-semibold tabular-nums text-charcoal/60">
                            {{ \Illuminate\Support\Str::substr((string) $slot->starts_at, 0, 5) }}<br>
                            {{ \Illuminate\Support\Str::substr((string) $slot->ends_at, 0, 5) }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-charcoal">
                                {{ $slot->subject_name ?? $slot->period_name }}
                            </span>
                            <span class="block truncate text-xs text-charcoal/60">
                                {{ __('opes.guardian_portal.timetable_day_'.$slot->day_of_week) }}@if ($slot->room_name)
                                    <span aria-hidden="true">&middot;</span> {{ $slot->room_name }}
                                @endif
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-portal.card>
</div>
