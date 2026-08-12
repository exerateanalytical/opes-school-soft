@php
    /*
     * `/portal/children/{s}/results` - built to mobile/results-overview.png.
     *
     * Every number is read from the stored report-card SNAPSHOT and never
     * recomputed (01-assessment 13.3), so this screen and the printed bulletin
     * agree by construction. `rank` is absent without row 9 - that absence is
     * authorization, not missing data, which is why the tile disappears rather
     * than showing a dash.
     */
    $locale = app()->getLocale();
    $payload = $card['payload'] ?? null;
    $subjects = is_array($payload['subjects'] ?? null) ? $payload['subjects'] : [];
    $usesCoefficients = is_array($payload['totals'] ?? null);
    $generalAverage = is_array($payload['general_average'] ?? null) ? $payload['general_average'] : null;
    $rank = is_array($payload['rank'] ?? null) ? $payload['rank'] : null;
@endphp

<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'results',
    ])

    @if ($periods === [])
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="book" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.results_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        {{-- Term selector - the design's chip row. --}}
        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <div class="inline-flex gap-2">
                @foreach ($periods as $period)
                    @php $isActive = $card && $card['snapshot_id'] === $period['id']; @endphp
                    <button type="button" wire:click="selectSnapshot({{ $period['id'] }})"
                            @if ($isActive) aria-current="true" @endif
                            @class([
                                'shrink-0 rounded-xl border px-4 py-2.5 text-sm font-semibold',
                                'border-portal-green bg-portal-green text-white' => $isActive,
                                'border-border-primary bg-white text-charcoal/70 hover:border-primary/40' => ! $isActive,
                            ])>
                        {{ $period['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($card)
            {{-- Performance at a glance. --}}
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.results_average')" icon="chart"
                                      :action="__('opes.guardian_portal.results_issued_on', ['date' => \Illuminate\Support\Carbon::parse($card['issued_at'])->translatedFormat('d M Y')])"/>
                </div>

                @if ($generalAverage !== null)
                    <div class="grid grid-cols-2 divide-x divide-y divide-border-secondary sm:grid-cols-4 sm:divide-y-0">
                        <x-portal.stat icon="chart" tone="primary"
                                       :label="__('opes.guardian_portal.results_average')"
                                       :value="$generalAverage['display'] ?? '—'"/>

                        @isset($payload['mention'])
                            <x-portal.stat icon="check" tone="success"
                                           :label="__('opes.guardian_portal.results_mention')"
                                           :value="$payload['mention'] ?? '—'"/>
                        @endisset

                        {{-- Absent entirely without row 9. --}}
                        @if ($rank !== null)
                            <x-portal.stat icon="users" tone="primary"
                                           :label="__('opes.guardian_portal.results_rank')"
                                           :value="($rank['is_ranked'] ?? false)
                                               ? ($rank['position'] ?? '—').' / '.($rank['denominator'] ?? '—')
                                               : __('opes.guardian_portal.results_not_ranked')"/>
                        @endif

                        <x-portal.stat icon="book" tone="primary"
                                       :label="__('opes.guardian_portal.results_subject')"
                                       :value="(string) count($subjects)"/>
                    </div>
                @else
                    <p class="px-4 pb-5 text-sm text-charcoal/70 sm:px-5">
                        {{ $payload['competency_note'] ?? __('opes.guardian_portal.results_not_assessed') }}
                    </p>
                @endif
            </x-portal.card>

            {{-- Promotion appears only with row 10 AND an applied decision:
                 telling a parent their child is provisionally repeating a year
                 before the school has applied it would be the worst possible
                 false alarm. --}}
            @if ($promotion !== null)
                <x-portal.card tone="green" class="flex items-center gap-3">
                    <x-portal.icon name="check" tone="success"/>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-charcoal">{{ __('opes.guardian_portal.results_promotion') }}</p>
                        <p class="text-sm text-charcoal/70">
                            {{ $promotion['outcome'] ?? __('opes.guardian_portal.results_not_assessed') }}
                            @if ($promotion['annual_average'])
                                <span aria-hidden="true">&middot;</span>
                                {{ __('opes.guardian_portal.results_average') }}: {{ $promotion['annual_average'] }}
                            @endif
                        </p>
                    </div>
                </x-portal.card>
            @endif

            {{-- Subject performance, with the design's progress rail. --}}
            @if ($subjects !== [])
                <x-portal.card :padded="false">
                    <div class="p-4 sm:p-5">
                        <x-portal.section :title="__('opes.guardian_portal.results_subject')" icon="book"/>
                    </div>

                    <div class="divide-y divide-border-secondary pb-1">
                        @foreach ($subjects as $subject)
                            @php
                                $score = $subject['subject_score'] ?? null;
                                $pct = is_numeric($score) ? max(0, min(100, (float) $score * ($score <= 20 ? 5 : 1))) : null;
                            @endphp

                            <div wire:key="subj-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3">
                                <x-portal.icon name="book" tone="primary" size="sm"/>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-charcoal">
                                        {{ $locale === 'fr'
                                            ? ($subject['subject_name_fr'] ?? $subject['subject_name'] ?? '')
                                            : ($subject['subject_name'] ?? '') }}
                                    </p>

                                    @if ($pct !== null)
                                        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-secondary">
                                            <div class="h-full rounded-full bg-portal-green" style="width: {{ $pct }}%"></div>
                                        </div>
                                    @endif

                                    @if ($usesCoefficients && ($subject['coefficient'] ?? null) !== null)
                                        <p class="mt-1 text-xs text-charcoal/55">
                                            {{ __('opes.guardian_portal.results_coefficient') }} {{ $subject['coefficient'] }}
                                        </p>
                                    @endif
                                </div>

                                <span class="shrink-0 text-sm font-bold tabular-nums text-charcoal">
                                    {{ $score ?? '—' }}
                                </span>

                                @if (($subject['appreciation'] ?? null) !== null)
                                    <span class="hidden shrink-0 rounded-full bg-portal-tint px-2.5 py-0.5 text-xs font-semibold text-primary sm:inline">
                                        {{ $subject['appreciation'] }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-portal.card>
            @endif
        @endif
    @endif
</div>
