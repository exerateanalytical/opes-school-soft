@php
    $tabs = [
        ['value' => 'results', 'label' => 'Period Results', 'count' => $tabCounts['results']],
        ['value' => 'statistics', 'label' => 'Class Statistics', 'count' => $tabCounts['statistics']],
        ['value' => 'publications', 'label' => 'Publications', 'count' => $tabCounts['publications']],
        ['value' => 'periods', 'label' => 'Periods', 'count' => $tabCounts['periods']],
    ];

    $publicationTone = [
        'draft' => 'amber',
        'marks_open' => 'amber',
        'marks_closed' => 'amber',
        'publishing' => 'amber',
        'published' => 'ok',
        'unpublished' => 'red',
    ];

    $publicationLabel = [
        'draft' => 'Draft',
        'marks_open' => 'Marks open',
        'marks_closed' => 'Marks closed',
        'publishing' => 'Publishing',
        'published' => 'Published',
        'unpublished' => 'Unpublished',
    ];

    $periodTone = [
        'planned' => 'amber',
        'open' => 'ok',
        'closed' => 'red',
    ];

    $periodLabel = [
        'planned' => 'Planned',
        'open' => 'Open',
        'closed' => 'Closed',
    ];
@endphp

<div class="min-w-0 space-y-4">
@if (session('status'))
    {{-- `ok` is x-status-pill's semantic tone name, not a palette token: as a
         Tailwind colour class it resolves to nothing, so this flash rendered
         as unstyled text. The success flash uses the same primary tokens the
         rest of the module's status banners do. --}}
    <div class="mb-3 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">{{ session('status') }}</div>
@endif

@if (session('error'))
    <div class="mb-3 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red">{{ session('error') }}</div>
@endif

@error('publish')
    <div class="mb-3 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red">{{ $message }}</div>
@enderror

@error('period')
    <div class="mb-3 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red">{{ $message }}</div>
@enderror

<div class="mb-4 rounded border border-border-primary bg-white">
    <div class="flex items-center justify-between px-4 py-3">
        <h2 class="text-sm font-semibold text-charcoal">Compute Results</h2>
        <button type="button" wire:click="toggleComputeForm"
                class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ $showComputeForm ? 'Cancel' : 'Compute Results' }}
        </button>
    </div>

    @if ($showComputeForm)
        <form wire:submit.prevent="computeResults" class="flex flex-wrap items-end gap-3 border-t border-border-primary px-4 py-3">
            <label for="compute-period" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Period</span>
                <select id="compute-period" wire:model="computePeriodId"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">Choose a period</option>
                    @foreach ($periodOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="compute-class" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Class</span>
                <select id="compute-class" wire:model="computeClassGroupId"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">Choose a class</option>
                    @foreach ($classGroupOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>

            <button type="submit"
                    class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                Run
            </button>

            @error('computePeriodId')
                <p class="w-full text-sm font-medium text-heritage-red">{{ $message }}</p>
            @enderror
        </form>
    @endif
</div>

<x-list-screen
    title="Assessment Results"
    :breadcrumb="['Dashboard', 'Assessment', 'Results']"
    :paginator="$rows"
    empty-message="No results match these filters yet. Period results, class statistics and publications appear here once the pipeline has run."
    rail-title="Results Overview"
>
    <x-slot:actions>
        {{-- The in-context door to marks ENTRY: /results is where a teacher
             already looks at a class's marks, and /marks had zero inbound
             links anywhere. Behind marks.enter, matching its route. --}}
        @can('marks.enter')
            <a href="{{ route('marks.entry') }}"
               class="rounded-lg border border-primary bg-primary px-3.5 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                {{ __('opes.nav.marks') }}
            </a>
        @endcan
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card label="Students with Results" :value="$kpis['students_with_results']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Classes with Statistics" :value="$kpis['classes_with_statistics']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9l8-5 8 5v10"/><path stroke-linecap="round" d="M9 19v-6h6v6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Published Periods" :value="$kpis['published_periods']" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19V5a2 2 0 012-2h10a2 2 0 012 2v14"/><path stroke-linecap="round" d="M9 7h6M9 11h6M9 15h3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="results-filter-period" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Period</span>
            <select id="results-filter-period" wire:model.live="period"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All periods</option>
                @foreach ($periodOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="results-filter-class" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Class</span>
            <select id="results-filter-class" wire:model.live="classGroup"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All classes</option>
                @foreach ($classGroupOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="results-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="results-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search student, class..."
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'results')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Average</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Rank</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Report card</th>
            @elseif ($tab === 'statistics')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">N</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Mean</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Highest</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Lowest</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Median</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Pass Rate</th>
            @elseif ($tab === 'publications')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Published At</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Published By</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Generation</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Action</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Starts</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Ends</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Action</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="results-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'results')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->general_average_rounded ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->is_ranked ? $row->rank_position.'/'.$row->rank_denominator : '—' }}</td>
                <td class="px-4 py-2.5">
                    @if ($row->general_average_rounded === null)
                        <x-status-pill status="amber" :label="'NC'" />
                    @else
                        <x-status-pill :status="$row->is_pass ? 'ok' : 'red'" :label="$row->is_pass ? 'Pass' : 'Fail'"/>
                    @endif
                </td>
                {{-- 10-documents §6.1: the bulletin opens INLINE in a new tab
                     (Fees\Http\Controllers\PrintInvoiceController is the
                     established shape). The link is built with url() rather
                     than route() because the route is wired centrally in
                     routes/web.php; PrintReportCard itself refuses, with a
                     422 and an explanation, when the period has not been
                     published and therefore has no snapshot to print. --}}
                <td class="px-4 py-2.5">
                    <a href="{{ url('/assessment/report-cards/'.$row->enrollment_id.'/'.$row->assessment_period_id.'/print') }}"
                       target="_blank" rel="noopener"
                       class="text-sm font-medium text-chrome underline underline-offset-2 hover:text-charcoal">
                        Print report card
                    </a>
                </td>
            @elseif ($tab === 'statistics')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->class_group_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->n }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->mean ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->max_score ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->min_score ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->median ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->pass_rate !== null ? $row->pass_rate.'%' : '—' }}</td>
            @elseif ($tab === 'publications')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->class_group_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->published_at ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->publisher_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->generation }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$publicationTone[$row->status] ?? 'amber'" :label="$publicationLabel[$row->status] ?? $row->status"/>
                </td>
                <td class="px-4 py-2.5">
                    @if (! in_array($row->status, ['published', 'publishing'], true))
                        <button type="button"
                                wire:click="publishPeriod({{ $row->assessment_period_id }}, {{ $row->class_group_id }})"
                                wire:confirm="Publish results for this period? This cannot be undone."
                                class="rounded bg-primary px-2.5 py-1 text-xs font-medium text-white hover:bg-primary/90">
                            Publish
                        </button>
                    @endif
                </td>
            @else
                <td class="px-4 py-2.5 font-mono text-xs text-charcoal/70">{{ $row->code }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->starts_on }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->ends_on }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$periodTone[$row->status] ?? 'amber'" :label="$periodLabel[$row->status] ?? $row->status"/>
                </td>
                <td class="px-4 py-2.5">
                    @if ($row->status === 'planned' && $canOpenPeriod)
                        <button type="button"
                                wire:click="openPeriod({{ $row->id }})"
                                wire:confirm="Open this period? Marks entry becomes available for every enrolled student."
                                class="rounded bg-primary px-2.5 py-1 text-xs font-medium text-white hover:bg-primary/90">
                            Open
                        </button>
                    @elseif ($row->status === 'open' && $canClosePeriod)
                        <button type="button"
                                wire:click="closePeriod({{ $row->id }})"
                                wire:confirm="Close this period? Marks entry stops immediately."
                                class="rounded border border-heritage-red px-2.5 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/10">
                            Close
                        </button>
                    @elseif ($row->status === 'closed' && $canOpenPeriod)
                        <button type="button"
                                wire:click="openPeriod({{ $row->id }})"
                                wire:confirm="Re-open this period? Marks entry becomes available again."
                                class="rounded border border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary/10">
                            Re-open
                        </button>
                    @endif
                </td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="results-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-border-primary bg-white p-3">
                @if ($tab === 'results')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        @if ($row->general_average_rounded === null)
                            <x-status-pill status="amber" :label="'NC'" />
                        @else
                            <x-status-pill :status="$row->is_pass ? 'ok' : 'red'" :label="$row->is_pass ? 'Pass' : 'Fail'"/>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->class_group_name }} · {{ $row->period_name }} · {{ $row->general_average_rounded ?? '—' }}</p>
                    <a href="{{ url('/assessment/report-cards/'.$row->enrollment_id.'/'.$row->assessment_period_id.'/print') }}"
                       target="_blank" rel="noopener"
                       class="mt-2 inline-block text-sm font-medium text-chrome underline underline-offset-2">
                        Print report card
                    </a>
                @elseif ($tab === 'statistics')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->class_group_name }}</p>
                        <span class="text-sm text-charcoal/70">{{ $row->pass_rate !== null ? $row->pass_rate.'%' : '—' }}</span>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->period_name }} · N {{ $row->n }} · Mean {{ $row->mean ?? '—' }}</p>
                @elseif ($tab === 'publications')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->class_group_name }}</p>
                        <x-status-pill :status="$publicationTone[$row->status] ?? 'amber'" :label="$publicationLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->period_name }} · {{ $row->published_at ?? 'Not published' }}</p>
                    @if (! in_array($row->status, ['published', 'publishing'], true))
                        <button type="button"
                                wire:click="publishPeriod({{ $row->assessment_period_id }}, {{ $row->class_group_id }})"
                                wire:confirm="Publish results for this period? This cannot be undone."
                                class="mt-2 rounded bg-primary px-2.5 py-1 text-xs font-medium text-white hover:bg-primary/90">
                            Publish
                        </button>
                    @endif
                @else
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->name }}</p>
                        <x-status-pill :status="$periodTone[$row->status] ?? 'amber'" :label="$periodLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->code }} · {{ $row->starts_on }} – {{ $row->ends_on }}</p>
                    @if ($row->status === 'planned' && $canOpenPeriod)
                        <button type="button"
                                wire:click="openPeriod({{ $row->id }})"
                                wire:confirm="Open this period? Marks entry becomes available for every enrolled student."
                                class="mt-2 rounded bg-primary px-2.5 py-1 text-xs font-medium text-white hover:bg-primary/90">
                            Open
                        </button>
                    @elseif ($row->status === 'open' && $canClosePeriod)
                        <button type="button"
                                wire:click="closePeriod({{ $row->id }})"
                                wire:confirm="Close this period? Marks entry stops immediately."
                                class="mt-2 rounded border border-heritage-red px-2.5 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/10">
                            Close
                        </button>
                    @elseif ($row->status === 'closed' && $canOpenPeriod)
                        <button type="button"
                                wire:click="openPeriod({{ $row->id }})"
                                wire:confirm="Re-open this period? Marks entry becomes available again."
                                class="mt-2 rounded border border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary/10">
                            Re-open
                        </button>
                    @endif
                @endif
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
</div>
