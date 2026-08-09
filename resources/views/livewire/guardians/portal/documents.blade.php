<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', ['studentId' => $studentId, 'childName' => $childName, 'active' => 'documents'])

    @if ($canSchoolIssued)
        <div class="rounded border border-sand bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.guardian_portal.documents_school_issued') }}</h2>

            @if ($schoolIssued->isEmpty())
                <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.guardian_portal.documents_empty') }}</p>
            @else
                <ul class="mt-2 divide-y divide-sand text-sm">
                    @foreach ($schoolIssued as $document)
                        <li class="flex items-center justify-between gap-2 py-1.5">
                            <span class="font-mono text-xs">{{ $document->serial ?? '—' }}</span>
                            <span class="text-charcoal/60">{{ $document->issued_at }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-charcoal/50">{{ __('opes.guardian_portal.documents_download_note') }}</p>
            @endif
        </div>
    @endif

    @if ($canGuardianSupplied)
        <div class="rounded border border-sand bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.guardian_portal.documents_guardian_supplied') }}</h2>

            @if ($guardianSupplied->isEmpty())
                <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.guardian_portal.documents_empty') }}</p>
            @else
                <ul class="mt-2 divide-y divide-sand text-sm">
                    @foreach ($guardianSupplied as $document)
                        <li class="flex items-center justify-between gap-2 py-1.5">
                            <span>{{ $document->title }}</span>
                            <x-status-pill :status="$document->verification_status === 'verified' ? 'ok' : ($document->verification_status === 'rejected' ? 'red' : 'amber')"
                                           :label="$document->verification_status"/>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
