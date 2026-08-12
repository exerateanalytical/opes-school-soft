@php
    $titles = [
        'history' => __('opes.guardian_portal.health_history_title'),
        'immunisations' => __('opes.guardian_portal.health_immunisations_title'),
        'documents' => __('opes.guardian_portal.health_documents_title'),
        'card' => __('opes.guardian_portal.health_id_title'),
    ];

    // The emergency-relevant rows are what an ID card carries.
    $emergency = $records->filter(fn (object $r): bool => (bool) ($r->is_emergency_relevant ?? false));
@endphp

<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'health',
    ])

    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="inline-flex gap-2">
            @foreach (['history' => 'heart', 'immunisations' => 'shield', 'documents' => 'file', 'card' => 'id'] as $key => $icon)
                <a href="{{ route('portal.children.health-detail', [$studentId, $key]) }}"
                   @if ($view === $key) aria-current="page" @endif
                   @class([
                       'flex shrink-0 items-center gap-2 rounded-xl border px-3.5 py-2.5 text-sm font-semibold',
                       'border-portal-green bg-portal-green text-white' => $view === $key,
                       'border-border-primary bg-white text-charcoal/70 hover:border-primary/40' => $view !== $key,
                   ])>
                    <x-portal.icon :name="$icon" bare size="sm"/>
                    {{ $titles[$key] }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- ------------------------------------------- history / immunisations -- --}}
    @if ($view === 'history' || $view === 'immunisations')
        @php $rows = $view === 'immunisations' ? $immunisations : $records; @endphp

        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="$titles[$view]" :icon="$view === 'immunisations' ? 'shield' : 'heart'"/>
            </div>

            @if ($rows->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">
                    {{ $view === 'immunisations'
                        ? __('opes.guardian_portal.health_immunisations_empty')
                        : __('opes.guardian_portal.health_empty') }}
                </p>
            @else
                <div class="space-y-3 px-4 pb-5 sm:px-5">
                    @foreach ($rows as $record)
                        <div wire:key="hd-{{ $loop->index }}"
                             @class([
                                 'rounded-xl border p-3',
                                 'border-portal-danger/30 bg-portal-danger-soft' => (bool) ($record->is_emergency_relevant ?? false),
                                 'border-border-secondary' => ! (bool) ($record->is_emergency_relevant ?? false),
                             ])>
                            <div class="flex items-start gap-3">
                                <x-portal.icon :name="$view === 'immunisations' ? 'shield' : 'heart'"
                                               :tone="($record->is_emergency_relevant ?? false) ? 'danger' : 'primary'"
                                               size="sm"/>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-charcoal">{{ $record->summary }}</p>
                                    <p class="text-xs text-charcoal/60">{{ $record->condition_type }}</p>
                                </div>

                                @if ($record->severity)
                                    <span class="shrink-0 rounded-full bg-warning-bg px-2.5 py-0.5 text-xs font-semibold text-warning">
                                        {{ $record->severity }}
                                    </span>
                                @endif
                            </div>

                            {{-- Only ever present for a row-4 caller. --}}
                            @if ($canFull && ($record->detail ?? null))
                                <p class="mt-2 whitespace-pre-line text-sm text-charcoal/75">{{ $record->detail }}</p>
                            @endif

                            @if ($record->recorded_at)
                                <p class="mt-2 text-xs text-charcoal/50">{{ $record->recorded_at }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-portal.card>
    @endif

    {{-- ------------------------------------------------------- documents -- --}}
    @if ($view === 'documents')
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.health_documents_title')" icon="file"/>
            </div>

            @if ($documents->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.documents_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($documents as $document)
                        <div wire:key="md-{{ $document->id }}" class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <x-portal.icon name="file" tone="primary"/>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-charcoal">{{ $document->title }}</p>
                                @if ($document->expires_on)
                                    <p class="text-xs text-charcoal/60">
                                        {{ __('opes.guardian_portal.documents_expires_on', ['date' => $document->expires_on]) }}
                                    </p>
                                @endif
                            </div>

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

    {{-- ------------------------------------------------------ health card -- --}}
    @if ($view === 'card')
        @unless ($revealed)
            {{-- A shoulder-surfing guard, not a security control - and it says
                 so. Anyone holding this unlocked browser can reach the same
                 facts through the Health tab, so pretending otherwise would
                 misrepresent the threat model. --}}
            <x-portal.card>
                <div class="flex flex-col items-center gap-4 py-6 text-center">
                    <x-portal.icon name="shield" tone="primary" size="lg"/>
                    <p class="max-w-sm text-sm text-charcoal/70">{{ __('opes.guardian_portal.id_card_hidden') }}</p>

                    <button type="button" wire:click="reveal"
                            class="rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-portal-green-soft">
                        {{ __('opes.guardian_portal.id_card_reveal') }}
                    </button>
                </div>
            </x-portal.card>
        @else
            <x-portal.card :padded="false">
                <div class="rounded-t-2xl bg-portal-green px-4 py-4 text-center">
                    <p class="text-sm font-bold tracking-[0.2em] text-portal-gold">{{ __('opes.shell.brand') }}</p>
                    <p class="mt-1 text-xs text-white/70">{{ __('opes.guardian_portal.health_id_title') }}</p>
                </div>

                <div class="flex items-center gap-4 p-4 sm:p-5">
                    <x-portal.avatar :name="$childName" size="xl" tone="green"
                                     :photo="route('portal.photo.child', $studentId)"/>

                    <div class="min-w-0">
                        <p class="text-lg font-bold text-charcoal">{{ $childName }}</p>
                        <p class="font-mono text-sm text-charcoal/60">{{ $matricule }}</p>
                    </div>
                </div>

                @if ($emergency->isEmpty())
                    <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.health_empty') }}</p>
                @else
                    <div class="divide-y divide-border-secondary border-t border-border-secondary">
                        @foreach ($emergency as $record)
                            <x-portal.row wire:key="hc-{{ $loop->index }}"
                                          :title="$record->summary"
                                          :subtitle="$record->condition_type"
                                          icon="alert" tone="danger"
                                          :trailing="$record->severity"
                                          trailingTone="danger"
                                          :chevron="false"/>
                        @endforeach
                    </div>
                @endif

                <p class="px-4 py-4 text-xs text-charcoal/55 sm:px-5">
                    {{ __('opes.guardian_portal.health_id_note') }}
                </p>
            </x-portal.card>
        @endunless
    @endif
</div>
