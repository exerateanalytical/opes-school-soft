@php
    $locale = app()->getLocale();
    $payload = $card['payload'] ?? null;
    $subjects = is_array($payload['subjects'] ?? null) ? $payload['subjects'] : [];
    $usesCoefficients = is_array($payload['totals'] ?? null);
    $generalAverage = is_array($payload['general_average'] ?? null) ? $payload['general_average'] : null;
    $rank = is_array($payload['rank'] ?? null) ? $payload['rank'] : null;

    // A subject score out of 20 (the francophone scale) becomes a percentage
    // for the bar only. The displayed FIGURE is always the snapshot's own.
    $asPercent = static function ($score): ?float {
        if (! is_numeric($score)) {
            return null;
        }

        $value = (float) $score;

        return max(0.0, min(100.0, $value <= 20 ? $value * 5 : $value));
    };

    $titles = [
        'subjects' => __('opes.guardian_portal.results_subject'),
        'analytics' => __('opes.guardian_portal.academics_analytics'),
        'terms' => __('opes.guardian_portal.academics_terms'),
        'report-card' => __('opes.guardian_portal.academics_report_card'),
        'bulletin' => __('opes.guardian_portal.academics_bulletin'),
        'transcript' => __('opes.guardian_portal.academics_transcript'),
    ];
@endphp

<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'results',
    ])

    {{-- The academic sub-views. Each is a different presentation of the same
         published snapshots, so they share one strip rather than hiding behind
         separate URLs a parent would never find. --}}
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="inline-flex gap-2">
            @foreach ([
                'subjects' => 'book',
                'terms' => 'calendar',
                'analytics' => 'chart',
                'report-card' => 'file',
                'bulletin' => 'shield',
                'transcript' => 'id',
            ] as $key => $icon)
                <a href="{{ route('portal.children.academics', [$studentId, $key]) }}"
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

    @if ($card === null)
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="book" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.results_empty') }}</p>
            </div>
        </x-portal.card>
    @else

        {{-- ----------------------------------------------------- subjects -- --}}
        @if ($view === 'subjects')
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.results_subject')" icon="book"/>
                </div>

                <div class="divide-y divide-border-secondary pb-1">
                    @forelse ($subjects as $subject)
                        @php $pct = $asPercent($subject['subject_score'] ?? null); @endphp

                        <div wire:key="sub-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3 sm:px-5">
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
                            </div>

                            <span class="shrink-0 text-sm font-bold tabular-nums text-charcoal">
                                {{ $subject['subject_score'] ?? '—' }}
                            </span>

                            @if (($subject['appreciation'] ?? null) !== null)
                                <span class="hidden shrink-0 rounded-full bg-portal-tint px-2.5 py-0.5 text-xs font-semibold text-primary sm:inline">
                                    {{ $subject['appreciation'] }}
                                </span>
                            @endif
                        </div>
                    @empty
                        <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.results_not_assessed') }}</p>
                    @endforelse
                </div>
            </x-portal.card>
        @endif

        {{-- ---------------------------------------------------- analytics -- --}}
        @if ($view === 'analytics')
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.academics_analytics')" icon="chart"/>
                </div>

                <div class="grid grid-cols-2 divide-x divide-y divide-border-secondary sm:grid-cols-4 sm:divide-y-0">
                    <x-portal.stat icon="chart" tone="primary"
                                   :label="__('opes.guardian_portal.results_average')"
                                   :value="$generalAverage['display'] ?? '—'"/>
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
                    <x-portal.stat icon="calendar" tone="primary"
                                   :label="__('opes.guardian_portal.academics_terms')"
                                   :value="(string) count($periods)"/>
                </div>
            </x-portal.card>

            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.academics_trend')" icon="chart"/>
                </div>

                <div class="space-y-3 px-4 pb-5 sm:px-5">
                    @foreach ($periods as $period)
                        @php
                            $avg = $period['payload']['general_average']['display'] ?? null;
                            $pct = $asPercent(is_string($avg) ? str_replace(',', '.', $avg) : $avg);
                        @endphp

                        <div wire:key="trend-{{ $period['id'] }}">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium text-charcoal">{{ $period['label'] }}</span>
                                <span class="font-bold tabular-nums text-primary">{{ $avg ?? '—' }}</span>
                            </div>
                            <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-surface-secondary">
                                <div class="h-full rounded-full bg-portal-green" style="width: {{ $pct ?? 0 }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-portal.card>
        @endif

        {{-- -------------------------------------------- terms / transcript -- --}}
        @if ($view === 'terms' || $view === 'transcript')
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="$titles[$view]" :icon="$view === 'terms' ? 'calendar' : 'id'"/>
                </div>

                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($periods as $period)
                        <x-portal.row wire:key="per-{{ $period['id'] }}"
                                      :title="$period['label']"
                                      :subtitle="\Illuminate\Support\Str::substr($period['issued_at'], 0, 10)"
                                      :icon="$view === 'terms' ? 'calendar' : 'file'"
                                      tone="primary"
                                      :trailing="$period['payload']['general_average']['display'] ?? '—'"
                                      :href="route('portal.children.academics', [$studentId, 'report-card']).'?snapshot='.$period['id']"/>
                    @endforeach
                </div>

                @if ($view === 'transcript')
                    {{-- A transcript is a multi-year document and the platform
                         has no endpoint for one (spec 1 non-goals). What this
                         can honestly show is every PUBLISHED period, and it
                         says so rather than implying the record is complete. --}}
                    <p class="px-4 pb-5 text-xs text-charcoal/55 sm:px-5">
                        {{ __('opes.guardian_portal.academics_transcript_note') }}
                    </p>
                @endif
            </x-portal.card>
        @endif

        {{-- --------------------------------------- report card / bulletin -- --}}
        @if ($view === 'report-card' || $view === 'bulletin')
            <x-portal.card :padded="false">
                @if ($view === 'bulletin')
                    {{-- The francophone form. Not a translation of the English
                         report card - a bulletin scolaire is a differently
                         shaped document, with the établissement block the
                         system expects. Same snapshot, different presentation. --}}
                    <div class="rounded-t-2xl bg-portal-green px-4 py-4 text-center">
                        <p class="text-sm font-bold tracking-[0.2em] text-portal-gold">{{ __('opes.shell.brand') }}</p>
                        <p class="mt-1 text-xs text-white/70">BULLETIN SCOLAIRE</p>
                        <p class="mt-2 text-sm font-semibold text-white">{{ $childName }}</p>
                    </div>
                @else
                    <div class="p-4 sm:p-5">
                        <x-portal.section :title="__('opes.guardian_portal.academics_report_card')" icon="file"/>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[26rem] text-sm">
                        <thead>
                            <tr class="border-b border-border-primary text-left text-xs uppercase tracking-wide text-charcoal/55">
                                <th scope="col" class="px-4 py-2 font-semibold">{{ __('opes.guardian_portal.results_subject') }}</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">{{ __('opes.guardian_portal.results_score') }}</th>
                                @if ($usesCoefficients)
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">{{ __('opes.guardian_portal.results_coefficient') }}</th>
                                @endif
                                <th scope="col" class="px-4 py-2 font-semibold">{{ __('opes.guardian_portal.results_appreciation') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-secondary">
                            @foreach ($subjects as $subject)
                                <tr wire:key="rc-{{ $loop->index }}">
                                    <td class="px-4 py-2 text-charcoal">
                                        {{ $locale === 'fr'
                                            ? ($subject['subject_name_fr'] ?? $subject['subject_name'] ?? '')
                                            : ($subject['subject_name'] ?? '') }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-charcoal">{{ $subject['subject_score'] ?? '—' }}</td>
                                    @if ($usesCoefficients)
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-charcoal">{{ $subject['coefficient'] ?? '—' }}</td>
                                    @endif
                                    <td class="px-4 py-2 text-charcoal/75">{{ $subject['appreciation'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="divide-y divide-border-secondary px-4 py-2 text-sm sm:px-5">
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-charcoal/70">{{ __('opes.guardian_portal.results_average') }}</dt>
                        <dd class="font-bold text-charcoal">{{ $generalAverage['display'] ?? '—' }}</dd>
                    </div>

                    @if ($rank !== null)
                        <div class="flex items-center justify-between py-2.5">
                            <dt class="text-charcoal/70">{{ __('opes.guardian_portal.results_rank') }}</dt>
                            <dd class="font-bold text-charcoal">
                                {{ ($rank['is_ranked'] ?? false)
                                    ? ($rank['position'] ?? '—').' / '.($rank['denominator'] ?? '—')
                                    : __('opes.guardian_portal.results_not_ranked') }}
                            </dd>
                        </div>
                    @endif

                    @if ($promotion !== null)
                        <div class="flex items-center justify-between py-2.5">
                            <dt class="text-charcoal/70">{{ __('opes.guardian_portal.results_promotion') }}</dt>
                            <dd class="font-bold text-portal-success">{{ $promotion['outcome'] ?? '—' }}</dd>
                        </div>
                    @endif
                </dl>

                {{-- No download button. RenderDocument is the only path to a
                     signed PDF and gates on `documents.print`, a staff
                     permission - see ChildDocuments. --}}
                <p class="px-4 pb-5 text-xs text-charcoal/55 sm:px-5">
                    {{ __('opes.guardian_portal.documents_download_note') }}
                </p>
            </x-portal.card>
        @endif
    @endif
</div>
