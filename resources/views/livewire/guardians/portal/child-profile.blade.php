@php
    $childName = $student ? trim($student->first_name.' '.$student->last_name) : '';
@endphp
<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', ['studentId' => $studentId, 'childName' => $childName, 'active' => 'profile'])

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded border border-sand bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.guardian_portal.profile_identity') }}</h2>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_matricule') }}</dt><dd class="font-mono">{{ $student->matricule ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_class') }}</dt><dd>{{ $className ?? '—' }}</dd></div>
            </dl>

            @if ($canDetail)
                <dl class="mt-3 space-y-1 border-t border-sand pt-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_dob') }}</dt><dd>{{ $student->date_of_birth ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_gender') }}</dt><dd>{{ $student->gender ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_nationality') }}</dt><dd>{{ $student->nationality ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_address') }}</dt>
                        <dd class="text-right">{{ collect([$student->address_line ?? null, $student->city ?? null, $student->region ?? null])->filter()->implode(', ') ?: '—' }}</dd>
                    </div>
                </dl>

                @if ($canFullMedical)
                    <dl class="mt-3 space-y-1 border-t border-sand pt-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_blood_group') }}</dt><dd>{{ $student->blood_group ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-charcoal/60">{{ __('opes.guardian_portal.profile_genotype') }}</dt><dd>{{ $student->genotype ?? '—' }}</dd></div>
                    </dl>
                @endif
            @else
                <p class="mt-3 border-t border-sand pt-3 text-xs text-charcoal/50">{{ __('opes.guardian_portal.profile_detail_restricted') }}</p>
            @endif
        </div>

        @if ($canEmergencyMedical || $canFullMedical)
            <div class="rounded border border-sand bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.guardian_portal.profile_medical') }}</h2>

                @php $records = $canFullMedical ? $fullMedical : $emergencyMedical; @endphp

                @if ($records->isEmpty())
                    <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.guardian_portal.profile_medical_empty') }}</p>
                @else
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach ($records as $record)
                            <li class="rounded border border-sand/70 bg-sand/20 p-2">
                                <p class="font-medium text-charcoal">{{ $record->condition_type }} <span class="font-normal text-charcoal/60">({{ $record->severity }})</span></p>
                                <p class="text-charcoal/80">{{ $record->summary }}</p>
                                @if ($canFullMedical && ($record->detail ?? null))
                                    <p class="mt-1 text-xs text-charcoal/60">{{ $record->detail }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if ($canOtherGuardians)
            <div class="rounded border border-sand bg-white p-4 sm:col-span-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.guardian_portal.profile_other_guardians') }}</h2>

                @if ($otherGuardians->isEmpty())
                    <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.guardian_portal.profile_other_guardians_empty') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-sand text-sm">
                        @foreach ($otherGuardians as $other)
                            <li class="flex items-center justify-between py-1.5">
                                <span>{{ trim($other->first_name.' '.$other->last_name) }}</span>
                                <span class="text-charcoal/60">{{ __('opes.guardians_screen.relationship_'.$other->relationship) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>
</div>
