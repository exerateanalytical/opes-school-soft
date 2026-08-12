{{--
    `/portal/children/{s}/documents` - built to mobile/child-documents.png.

    Two shelves, two grants. Only row 23 has bytes - the guardian uploaded
    them. Row 22 carries a verification code instead, because the only path to
    a signed PDF is RenderDocument, gated on the staff permission
    `documents.print`. See ChildDocuments for the full argument.
--}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'documents',
    ])

    @if ($canSchoolIssued)
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.documents_school_issued')" icon="shield"/>
            </div>

            @if ($schoolIssued->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.documents_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary">
                    @foreach ($schoolIssued as $document)
                        <x-portal.row wire:key="doc-s-{{ $document->id }}"
                                      :title="$document->serial ?? '#'.$document->id"
                                      :subtitle="\Illuminate\Support\Str::substr((string) $document->issued_at, 0, 10)"
                                      icon="shield" tone="gold"
                                      :trailing="__('opes.guardian_portal.receipt_verify')"
                                      trailingTone="gold"
                                      :chevron="false"/>
                    @endforeach
                </div>

                <p class="px-4 py-4 text-xs text-charcoal/55 sm:px-5">
                    {{ __('opes.guardian_portal.documents_download_note') }}
                </p>
            @endif
        </x-portal.card>
    @endif

    @if ($canGuardianSupplied)
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.documents_guardian_supplied')" icon="file"/>
            </div>

            @if ($guardianSupplied->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.documents_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($guardianSupplied as $document)
                        <div wire:key="doc-g-{{ $document->id }}" class="flex items-center gap-3 px-4 py-3">
                            <x-portal.icon name="file" tone="primary"/>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-charcoal">{{ $document->title }}</p>
                                @if ($document->expires_on)
                                    <p class="text-xs text-charcoal/60">
                                        {{ __('opes.guardian_portal.documents_expires_on', ['date' => $document->expires_on]) }}
                                    </p>
                                @endif
                            </div>

                            <span @class([
                                'shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-portal-chip text-portal-success' => $document->verification_status === 'verified',
                                'bg-portal-danger-soft text-portal-danger' => $document->verification_status === 'rejected',
                                'bg-warning-bg text-warning' => ! in_array($document->verification_status, ['verified', 'rejected'], true),
                            ])>{{ $document->verification_status }}</span>

                            {{-- Row 23 has real bytes. Row 22 above has none. --}}
                            <a href="{{ route('portal.children.documents.download', [$studentId, 'supplied', $document->id]) }}"
                               class="shrink-0 rounded-lg border border-border-primary px-2.5 py-1.5 text-xs font-semibold text-primary hover:border-primary/50">
                                {{ __('opes.guardian_portal.documents_download') }}
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-portal.card>
    @endif
</div>
