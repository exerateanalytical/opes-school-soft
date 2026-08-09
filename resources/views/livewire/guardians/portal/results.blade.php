@php
    $locale = app()->getLocale();
    $payload = $card['payload'] ?? null;
    $subjects = is_array($payload['subjects'] ?? null) ? $payload['subjects'] : [];
    $usesCoefficients = is_array($payload['totals'] ?? null);
    $generalAverage = is_array($payload['general_average'] ?? null) ? $payload['general_average'] : null;
    $rank = is_array($payload['rank'] ?? null) ? $payload['rank'] : null;
@endphp
<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', ['studentId' => $studentId, 'childName' => $childName, 'active' => 'results'])

    @if ($periods === [])
        <x-empty-state :message="__('opes.guardian_portal.results_empty')"/>
    @else
        <div class="flex flex-wrap gap-2">
            @foreach ($periods as $period)
                <button type="button" wire:click="selectSnapshot({{ $period['id'] }})"
                        @if ($card && $card['snapshot_id'] === $period['id']) aria-current="true" @endif
                        class="rounded-full border px-3 py-1 text-xs font-medium {{ $card && $card['snapshot_id'] === $period['id']
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-sand text-charcoal/70 hover:border-primary/40' }}">
                    {{ $period['label'] }}
                </button>
            @endforeach
        </div>

        @if ($card)
            <div class="rounded border border-sand bg-white p-4">
                <p class="text-xs text-charcoal/60">
                    {{ __('opes.guardian_portal.results_generation', ['n' => $card['generation']]) }}
                    <span aria-hidden="true"> &middot; </span>
                    {{ __('opes.guardian_portal.results_issued_on', ['date' => \Illuminate\Support\Carbon::parse($card['issued_at'])->translatedFormat('d F Y')]) }}
                </p>

                @if ($generalAverage !== null)
                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded border border-sand bg-sand/30 p-3">
                            <p class="text-[11px] uppercase tracking-wide text-charcoal/60">{{ __('opes.guardian_portal.results_average') }}</p>
                            <p class="text-lg font-semibold text-charcoal">{{ $generalAverage['display'] ?? '—' }}</p>
                        </div>
                        @if (isset($payload['mention']))
                            <div class="rounded border border-sand bg-sand/30 p-3">
                                <p class="text-[11px] uppercase tracking-wide text-charcoal/60">{{ __('opes.guardian_portal.results_mention') }}</p>
                                <p class="text-lg font-semibold text-charcoal">{{ $payload['mention'] ?? '—' }}</p>
                            </div>
                        @endif
                        @if ($rank !== null)
                            <div class="rounded border border-sand bg-sand/30 p-3">
                                <p class="text-[11px] uppercase tracking-wide text-charcoal/60">{{ __('opes.guardian_portal.results_rank') }}</p>
                                <p class="text-lg font-semibold text-charcoal">
                                    {{ $rank['is_ranked'] ?? false ? ($rank['position'] ?? '—').' / '.($rank['denominator'] ?? '—') : __('opes.guardian_portal.results_not_ranked') }}
                                </p>
                            </div>
                        @endif
                        @if (isset($payload['gpa']))
                            <div class="rounded border border-sand bg-sand/30 p-3">
                                <p class="text-[11px] uppercase tracking-wide text-charcoal/60">GPA</p>
                                <p class="text-lg font-semibold text-charcoal">{{ $payload['gpa'] }}</p>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="mt-3 text-sm text-charcoal/70">{{ $payload['competency_note'] ?? __('opes.guardian_portal.results_not_assessed') }}</p>
                @endif

                @if ($promotion !== null)
                    <div class="mt-3 rounded border border-primary/30 bg-primary/5 p-3 text-sm">
                        <p class="font-medium text-charcoal">{{ __('opes.guardian_portal.results_promotion') }}</p>
                        <p class="text-charcoal/70">
                            {{ $promotion['outcome'] ?? __('opes.guardian_portal.results_not_assessed') }}
                            @if ($promotion['annual_average'])
                                <span aria-hidden="true"> &middot; </span>{{ __('opes.guardian_portal.results_average') }}: {{ $promotion['annual_average'] }}
                            @endif
                        </p>
                    </div>
                @endif

                @if ($subjects !== [])
                    <div class="mt-4 min-w-0 overflow-x-auto rounded border border-sand">
                        <table class="w-full min-w-[28rem] border-collapse text-sm">
                            <thead class="border-b border-sand bg-sand/40 text-left">
                                <tr>
                                    <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.results_subject') }}</th>
                                    <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.results_score') }}</th>
                                    @if ($usesCoefficients)
                                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.results_coefficient') }}</th>
                                    @endif
                                    <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.results_appreciation') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand">
                                @foreach ($subjects as $subject)
                                    <tr>
                                        <td class="px-3 py-2 text-charcoal">{{ $locale === 'fr' ? ($subject['subject_name_fr'] ?? $subject['subject_name'] ?? '') : ($subject['subject_name'] ?? '') }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-charcoal">{{ $subject['subject_score'] ?? '—' }}</td>
                                        @if ($usesCoefficients)
                                            <td class="px-3 py-2 text-right font-mono text-charcoal">{{ $subject['coefficient'] ?? '—' }}</td>
                                        @endif
                                        <td class="px-3 py-2 text-charcoal/80">{{ $subject['appreciation'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (($payload['conduct']['recorded'] ?? false) || ($payload['remarks']['entries'] ?? []) !== [])
                    <div class="mt-4 text-sm text-charcoal/80">
                        @if ($payload['remarks']['entries'] ?? [])
                            <p class="font-medium text-charcoal">{{ __('opes.guardian_portal.results_remarks') }}</p>
                            <ul class="mt-1 list-disc pl-5">
                                @foreach ($payload['remarks']['entries'] as $entry)
                                    <li>{{ $entry['remark'] ?? ($entry['text'] ?? '') }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
