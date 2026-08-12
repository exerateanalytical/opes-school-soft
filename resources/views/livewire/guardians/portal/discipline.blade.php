{{--
    `/portal/children/{s}/discipline` - built to mobile/behaviour-discipline.png.

    Three grants meet here and the UI keeps them apart honestly: the LIST
    (row 19) says a case exists, the NARRATIVE (row 20) says what it says, and
    the SIGNATURE (row 21) acknowledges a sanction. Only cases with
    `visibility = 'guardian'` are ever selected, so a case naming another minor
    is invisible whatever the flags say.
--}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'discipline',
    ])

    @if ($cases->isEmpty())
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="check" tone="success" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.discipline_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        @foreach ($cases as $case)
            @php $sanctions = $sanctionsByCase->get($case->id, collect()); @endphp

            <x-portal.card wire:key="case-{{ $case->id }}" :padded="false"
                           :tone="$case->is_positive ? 'green' : 'white'">
                <div class="flex items-start gap-3 p-4 sm:p-5">
                    <x-portal.icon :name="$case->is_positive ? 'check' : 'alert'"
                                   :tone="$case->is_positive ? 'success' : 'warning'"/>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-charcoal">
                                {{ app()->getLocale() === 'fr' && $case->category_name_fr
                                    ? $case->category_name_fr
                                    : $case->category_name }}
                            </h2>

                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-portal-chip text-portal-success' => $case->is_positive,
                                'bg-warning-bg text-warning' => ! $case->is_positive,
                            ])>{{ $case->status }}</span>
                        </div>

                        <p class="mt-0.5 text-xs text-charcoal/60">{{ $case->occurred_on }}</p>

                        {{-- Present only with row 20. Its absence is a school
                             decision, not a gap, so nothing is stubbed here. --}}
                        @if ($case->description)
                            <p class="mt-2 whitespace-pre-line text-sm text-charcoal/75">{{ $case->description }}</p>
                        @endif

                        @if ($case->resolution_note)
                            <p class="mt-2 text-sm text-charcoal/75">
                                <span class="font-semibold">{{ __('opes.guardian_portal.discipline_resolution') }}:</span>
                                {{ $case->resolution_note }}
                            </p>
                        @endif
                    </div>
                </div>

                @if ($sanctions->isNotEmpty())
                    <div class="border-t border-border-secondary">
                        <p class="px-4 pt-3 text-xs font-bold uppercase tracking-wide text-charcoal/50 sm:px-5">
                            {{ __('opes.guardian_portal.discipline_sanctions') }}
                        </p>

                        <div class="divide-y divide-border-secondary">
                            @foreach ($sanctions as $sanction)
                                <div wire:key="sanction-{{ $sanction->id }}"
                                     class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                                    <x-portal.icon name="shield" tone="primary" size="sm"/>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-charcoal">{{ $sanction->type }}</p>
                                        <p class="text-xs text-charcoal/60">
                                            {{ collect([$sanction->starts_on, $sanction->ends_on])->filter()->join(' → ') }}
                                        </p>
                                    </div>

                                    @if ($sanction->acknowledged_at)
                                        <span class="shrink-0 rounded-full bg-portal-chip px-2.5 py-0.5 text-xs font-semibold text-portal-success">
                                            {{ __('opes.guardian_portal.discipline_acknowledged') }}
                                        </span>
                                    @elseif ($canAcknowledge)
                                        {{-- Offered only with row 21, and never
                                             for an already-signed sanction:
                                             WHEN a parent signed is
                                             evidentiary and the server refuses
                                             to rewrite it. --}}
                                        <button type="button" wire:click="acknowledge({{ $sanction->id }})"
                                                class="shrink-0 rounded-xl bg-primary px-3 py-2 text-xs font-semibold text-white hover:bg-chrome-light">
                                            {{ __('opes.guardian_portal.ack_button') }}
                                        </button>
                                    @else
                                        <span class="shrink-0 rounded-full bg-warning-bg px-2.5 py-0.5 text-xs font-semibold text-warning">
                                            {{ __('opes.guardian_portal.discipline_pending_ack') }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-portal.card>
        @endforeach
    @endif
</div>
