@php $locale = app()->getLocale(); @endphp
<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', ['studentId' => $studentId, 'childName' => $childName, 'active' => 'discipline'])

    @if ($cases->isEmpty())
        <x-empty-state :message="__('opes.guardian_portal.discipline_empty')"/>
    @else
        <div class="space-y-3">
            @foreach ($cases as $case)
                <div class="rounded border border-border-primary bg-white p-4" wire:key="portal-discipline-{{ $case->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">
                            {{ $locale === 'fr' && $case->category_name_fr ? $case->category_name_fr : $case->category_name }}
                        </p>
                        <x-status-pill :status="$case->status === 'resolved' ? 'ok' : ($case->status === 'dismissed' ? 'ok' : 'amber')" :label="$case->status"/>
                    </div>
                    <p class="text-xs text-charcoal/60">{{ $case->occurred_on }}</p>
                    <p class="mt-2 text-sm text-charcoal/80">{{ $case->description }}</p>

                    @if ($case->resolution_note)
                        <p class="mt-1 text-xs text-charcoal/60">{{ __('opes.guardian_portal.discipline_resolution') }}: {{ $case->resolution_note }}</p>
                    @endif

                    @if (($sanctionsByCase->get($case->id) ?? collect())->isNotEmpty())
                        <div class="mt-3 border-t border-border-primary pt-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/60">{{ __('opes.guardian_portal.discipline_sanctions') }}</p>
                            <ul class="mt-1 space-y-1 text-sm">
                                @foreach ($sanctionsByCase->get($case->id) as $sanction)
                                    <li class="flex items-center justify-between gap-2">
                                        <span>{{ $sanction->type }} &middot; {{ $sanction->starts_on }}@if ($sanction->ends_on) &ndash; {{ $sanction->ends_on }}@endif</span>
                                        <x-status-pill :status="$sanction->acknowledged_at ? 'ok' : 'amber'"
                                                       :label="$sanction->acknowledged_at ? __('opes.guardian_portal.discipline_acknowledged') : __('opes.guardian_portal.discipline_pending_ack')"/>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
