{{--
    `/portal/children/{s}/contacts` - mobile/emergency-important-contacts.png
    and teacher-school-contact.png.
--}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'profile',
    ])

    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.contacts_title')" icon="users"/>
        </div>

        @if ($others->isEmpty())
            <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">
                {{ __('opes.guardian_portal.profile_other_guardians_empty') }}
            </p>
        @else
            <div class="divide-y divide-border-secondary">
                @foreach ($others as $other)
                    @php $name = trim($other->first_name.' '.$other->last_name); @endphp

                    {{-- No call button. Row 31 sends names and relationship
                         only, so there would be nothing to dial - and a
                         disabled one would imply the number is being withheld
                         rather than never sent at all. --}}
                    <div wire:key="ct-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3 sm:px-5">
                        <x-portal.avatar :name="$name" tone="green"/>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-charcoal">{{ $name }}</span>
                            <span class="block text-xs text-charcoal/60">
                                {{ __('opes.guardians_screen.relationship_'.$other->relationship) }}
                            </span>
                        </span>

                        @if ($other->is_emergency_contact)
                            <span class="shrink-0 rounded-full bg-portal-danger-soft px-2.5 py-0.5 text-xs font-semibold text-portal-danger">
                                {{ __('opes.guardian_portal.contacts_title') }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <p class="px-4 py-4 text-xs text-charcoal/55 sm:px-5">
            {{ __('opes.guardian_portal.contacts_note') }}
        </p>
    </x-portal.card>

    {{-- The school's own contacts ARE reachable - those are public. --}}
    <x-portal.card :padded="false">
        <div class="p-4 sm:p-5">
            <x-portal.section :title="__('opes.guardian_portal.contacts_school')" icon="phone"/>
        </div>

        <div class="divide-y divide-border-secondary pb-1">
            <x-portal.row :title="__('opes.guardian_portal.contacts_teacher')"
                          :subtitle="__('opes.guardian_portal.help_message')"
                          icon="chat" tone="primary"
                          :href="route('portal.messages')"/>

            <x-portal.row :title="__('opes.guardian_portal.contacts_office')"
                          :subtitle="__('opes.guardian_portal.help_title')"
                          icon="help" tone="primary"
                          :href="route('portal.help')"/>

            @if ($canMeet)
                <x-portal.row :title="__('opes.guardian_portal.meeting_title')"
                              :subtitle="__('opes.guardian_portal.meeting_intro')"
                              icon="calendar" tone="primary"
                              :href="route('portal.children.meeting', $studentId)"/>
            @endif
        </div>
    </x-portal.card>
</div>
