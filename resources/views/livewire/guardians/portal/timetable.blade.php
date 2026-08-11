<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'timetable',
    ])

    @if ($byDay === [])
        <x-empty-state :message="__('opes.guardian_portal.timetable_empty')"/>
    @else
        <div class="space-y-4">
            @foreach ($byDay as $day => $slots)
                <section wire:key="tt-day-{{ $day }}"
                         aria-labelledby="portal-tt-{{ $day }}"
                         class="rounded border border-border-primary bg-white shadow-sm">
                    <h2 id="portal-tt-{{ $day }}"
                        class="rounded-t bg-surface-green px-4 py-2 text-sm font-semibold text-chrome">
                        {{ __('opes.guardian_portal.timetable_day_'.$day) }}
                    </h2>

                    <ul class="divide-y divide-border-secondary">
                        @foreach ($slots as $slot)
                            <li wire:key="tt-{{ $day }}-{{ $loop->index }}"
                                class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2.5 text-sm">
                                <span class="w-28 shrink-0 tabular-nums text-charcoal/60">
                                    {{ \Illuminate\Support\Str::substr((string) $slot->starts_at, 0, 5) }}–{{ \Illuminate\Support\Str::substr((string) $slot->ends_at, 0, 5) }}
                                </span>
                                <span class="min-w-0 flex-1 font-medium text-charcoal">
                                    {{ $slot->subject_name ?? $slot->period_name }}
                                </span>
                                @if ($slot->room_name)
                                    <span class="shrink-0 text-xs text-charcoal/60">{{ $slot->room_name }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
