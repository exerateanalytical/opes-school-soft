<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'health',
    ])

    @unless ($canFull)
        {{-- Row 3 is not a degraded row 4. It is the whole, correct answer for
             an emergency contact, so it is stated rather than left as a gap. --}}
        <p class="rounded border border-border-secondary bg-surface-green px-4 py-3 text-sm text-charcoal/70">
            {{ __('opes.guardian_portal.health_emergency_scope') }}
        </p>
    @endunless

    <section aria-labelledby="portal-health-records" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <h2 id="portal-health-records" class="text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.health_title') }}
        </h2>

        @if ($records->isEmpty())
            <p class="mt-3 text-sm text-charcoal/60">{{ __('opes.guardian_portal.health_empty') }}</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($records as $record)
                    <li wire:key="health-{{ $loop->index }}" class="rounded border border-border-secondary p-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-charcoal">{{ $record->summary }}</p>
                                <p class="text-xs text-charcoal/60">{{ $record->condition_type }}</p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($record->severity)
                                    <span class="rounded bg-warning-bg px-2 py-0.5 text-xs font-medium text-warning">
                                        {{ $record->severity }}
                                    </span>
                                @endif
                                @if (($record->is_emergency_relevant ?? false))
                                    <span class="rounded bg-danger-bg px-2 py-0.5 text-xs font-medium text-danger">
                                        {{ __('opes.guardian_portal.health_emergency_scope') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- `detail` is only ever selected for a row-4 caller;
                             an emergency-scope read never puts it on the wire. --}}
                        @if ($canFull && ($record->detail ?? null))
                            <p class="mt-2 whitespace-pre-line text-sm text-charcoal/80">{{ $record->detail }}</p>
                        @endif

                        @if ($record->recorded_at)
                            <p class="mt-2 text-xs text-charcoal/50">{{ $record->recorded_at }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
