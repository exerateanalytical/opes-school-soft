{{-- `/portal/children/{s}/timetable` - the class week, row 26. --}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'timetable',
    ])

    @if ($byDay === [])
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="clock" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.timetable_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        <div class="space-y-4">
            @foreach ($byDay as $day => $slots)
                <x-portal.card wire:key="tt-day-{{ $day }}" :padded="false">
                    <div class="flex items-center gap-3 rounded-t-2xl bg-portal-tint px-4 py-3">
                        <x-portal.icon name="calendar" tone="primary" size="sm"/>
                        <h2 class="text-sm font-bold text-primary">
                            {{ __('opes.guardian_portal.timetable_day_'.$day) }}
                        </h2>
                    </div>

                    <div class="divide-y divide-border-secondary">
                        @foreach ($slots as $slot)
                            <div wire:key="tt-{{ $day }}-{{ $loop->index }}"
                                 class="flex items-center gap-3 px-4 py-3">
                                <span class="w-24 shrink-0 text-xs font-semibold tabular-nums text-charcoal/60">
                                    {{ \Illuminate\Support\Str::substr((string) $slot->starts_at, 0, 5) }}<br>
                                    {{ \Illuminate\Support\Str::substr((string) $slot->ends_at, 0, 5) }}
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-charcoal">
                                        {{ $slot->subject_name ?? $slot->period_name }}
                                    </span>
                                    @if ($slot->room_name)
                                        <span class="block truncate text-xs text-charcoal/60">
                                            {{ __('opes.guardian_portal.timetable_room') }}: {{ $slot->room_name }}
                                        </span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-portal.card>
            @endforeach
        </div>
    @endif
</div>
