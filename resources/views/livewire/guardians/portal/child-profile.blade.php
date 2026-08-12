{{--
    `/portal/children/{s}/profile` - built to mobile/child-profile.png and
    child-overview.png.

    Row 1 is the floor - identity, always. Row 2 adds the detail block, and its
    absence is a school decision rather than a gap, so the screen says so
    instead of showing a page of dashes.
--}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => trim($student->first_name.' '.$student->last_name),
        'active' => 'profile',
    ])

    {{-- Identity - row 1, granted on every valid link. --}}
    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.profile_identity')" icon="id"/>
        </div>

        <dl class="divide-y divide-border-secondary px-4 pb-2 text-sm sm:px-5">
            @foreach (array_filter([
                ['id', __('opes.guardian_portal.profile_matricule'), $student->matricule],
                ['book', __('opes.guardian_portal.profile_class'), $className],
                ['calendar', __('opes.guardian_portal.profile_dob'), $canDetail ? $student->date_of_birth : null],
                ['user', __('opes.guardian_portal.profile_gender'), $student->gender ?? null],
                ['globe', __('opes.guardian_portal.profile_nationality'), $canDetail ? ($student->nationality ?? null) : null],
                ['pin', __('opes.guardian_portal.profile_address'), $canDetail ? ($student->address_line ?? null) : null],
            ], fn (array $row): bool => $row[2] !== null && $row[2] !== '') as $index => [$icon, $label, $value])
                <div class="flex items-center gap-3 py-3" wire:key="ident-{{ $index }}">
                    <x-portal.icon :name="$icon" tone="primary" size="sm"/>
                    <dt class="min-w-0 flex-1 text-charcoal/70">{{ $label }}</dt>
                    <dd class="shrink-0 text-right font-semibold text-charcoal">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @unless ($canDetail)
            <p class="px-4 pb-4 text-xs text-charcoal/55 sm:px-5">
                {{ __('opes.guardian_portal.profile_detail_restricted') }}
            </p>
        @endunless
    </x-portal.card>

    {{-- Medical - rows 3 and 4. The emergency scope is a complete answer for
         an emergency contact, not a degraded one. --}}
    @if ($canEmergencyMedical || $canFullMedical)
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.profile_medical')" icon="heart"
                                  :action="__('opes.guardian_portal.health_title')"
                                  :href="route('portal.children.health', $studentId)"/>
            </div>

            @php $records = $canFullMedical ? $fullMedical : $emergencyMedical; @endphp

            @if ($records === null || $records->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.profile_medical_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($records as $record)
                        <x-portal.row wire:key="med-{{ $loop->index }}"
                                      :title="$record->summary ?? $record->condition_type"
                                      :subtitle="$record->condition_type ?? null"
                                      icon="heart"
                                      :tone="($record->is_emergency_relevant ?? false) ? 'danger' : 'primary'"
                                      :trailing="$record->severity ?? null"
                                      trailingTone="warning"
                                      :chevron="false"/>
                    @endforeach
                </div>
            @endif
        </x-portal.card>
    @endif

    {{-- Row 31: names and relationship ONLY. The server sends no phone, no
         email, no ID number - a parent may know who else is on the record
         without getting a directory of the other family. --}}
    @if ($canOtherGuardians)
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.profile_other_guardians')" icon="users"/>
            </div>

            @if ($otherGuardians->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.profile_other_guardians_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($otherGuardians as $other)
                        @php $name = trim($other->first_name.' '.$other->last_name); @endphp
                        <div wire:key="og-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <x-portal.avatar :name="$name" tone="green"/>
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-charcoal">{{ $name }}</span>
                            <span class="shrink-0 rounded-full bg-portal-tint px-2.5 py-0.5 text-xs font-semibold text-primary">
                                {{ __('opes.guardians_screen.relationship_'.$other->relationship) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-portal.card>
    @endif

    {{-- Row 27. The only way into the meeting screen. --}}
    @if (app(\App\Modules\Guardians\Policies\GuardianPortalPolicy::class)
            ->allows(\App\Modules\Guardians\Domain\GuardianCapability::R27RequestGuardianMeeting, $studentId))
        <x-portal.card tone="green" class="flex flex-wrap items-center gap-4">
            <x-portal.icon name="calendar" tone="primary" size="lg"/>

            <div class="min-w-0 flex-1">
                <p class="text-base font-bold text-charcoal">{{ __('opes.guardian_portal.meeting_title') }}</p>
                <p class="mt-0.5 text-sm text-charcoal/70">{{ __('opes.guardian_portal.meeting_intro') }}</p>
            </div>

            <a href="{{ route('portal.children.meeting', $studentId) }}"
               class="shrink-0 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-portal-green-soft">
                {{ __('opes.guardian_portal.meeting_submit') }}
            </a>
        </x-portal.card>
    @endif
</div>
