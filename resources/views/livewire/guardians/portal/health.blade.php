{{-- `/portal/children/{s}/health` - built to mobile/health-overview.png. --}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'health',
    ])

    @unless ($canFull)
        {{-- Row 3 is not a degraded row 4. It is the whole, correct answer for
             an emergency contact, so it is stated rather than left as a gap. --}}
        <x-portal.card tone="green" class="flex items-start gap-3">
            <x-portal.icon name="shield" tone="primary" size="sm"/>
            <p class="text-sm text-charcoal/70">{{ __('opes.guardian_portal.health_emergency_scope') }}</p>
        </x-portal.card>
    @endunless

    @if ($records->isEmpty())
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="heart" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.health_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.health_title')" icon="heart"/>
            </div>

            <div class="space-y-3 px-4 pb-4 sm:px-5 sm:pb-5">
                @foreach ($records as $record)
                    <div wire:key="health-{{ $loop->index }}"
                         @class([
                             'rounded-xl border p-3',
                             'border-portal-danger/30 bg-portal-danger-soft' => (bool) ($record->is_emergency_relevant ?? false),
                             'border-border-secondary' => ! (bool) ($record->is_emergency_relevant ?? false),
                         ])>
                        <div class="flex flex-wrap items-start gap-3">
                            <x-portal.icon name="heart"
                                           :tone="($record->is_emergency_relevant ?? false) ? 'danger' : 'primary'"
                                           size="sm"/>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-charcoal">{{ $record->summary }}</p>
                                <p class="text-xs text-charcoal/60">{{ $record->condition_type }}</p>
                            </div>

                            @if ($record->severity)
                                <span class="shrink-0 rounded-full bg-warning-bg px-2.5 py-0.5 text-xs font-semibold text-warning-text">
                                    {{ $record->severity }}
                                </span>
                            @endif
                        </div>

                        {{-- `detail` is only ever selected for a row-4 caller;
                             an emergency-scope read never puts it on the wire. --}}
                        @if ($canFull && ($record->detail ?? null))
                            <p class="mt-2 whitespace-pre-line text-sm text-charcoal/75">{{ $record->detail }}</p>
                        @endif

                        @if ($record->recorded_at)
                            <p class="mt-2 text-xs text-charcoal/50">{{ $record->recorded_at }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-portal.card>
    @endif
</div>
