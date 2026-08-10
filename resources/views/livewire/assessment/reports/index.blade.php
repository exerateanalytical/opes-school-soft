@php
    $tabs = [
        ['value' => 'marksheet', 'label' => 'Mark Sheet', 'count' => $tabCounts['marksheet']],
        ['value' => 'results', 'label' => 'Results Register', 'count' => $tabCounts['results']],
        ['value' => 'statistics', 'label' => 'Class Statistics Summary', 'count' => $tabCounts['statistics']],
        ['value' => 'exams', 'label' => 'Exam Schedule Register', 'count' => $tabCounts['exams']],
    ];

    $examStatusTone = [
        'planned' => 'amber',
        'scheduled' => 'blue',
        'in_progress' => 'blue',
        'marked' => 'ok',
        'cancelled' => 'red',
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    <x-list-screen
        title="Assessment Reports"
        :breadcrumb="['Dashboard', 'Assessment', 'Reports']"
        :paginator="$rows"
        empty-message="No records match these filters yet. Marks, results, statistics and exams appear here once they are recorded."
    >
        <x-slot:actions>
            <button type="button" wire:click="exportExcel"
                    class="rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary print:hidden">
                Export Excel
            </button>
            <button type="button" wire:click="exportPdf"
                    class="rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary print:hidden">
                Export PDF
            </button>
            <button type="button" onclick="window.print()"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90 print:hidden">
                Print
            </button>
        </x-slot:actions>

        {{-- KPI strip: dataset-wide counts, one per report. --}}
        <x-slot:kpis>
            <x-kpi-card label="Marks Scored" :value="$kpis['marks']" icon-bg="bg-primary">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Student Results" :value="$kpis['results']" icon-bg="bg-badge-blue">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19V5a2 2 0 012-2h10a2 2 0 012 2v14"/><path stroke-linecap="round" d="M9 7h6M9 11h6M9 15h3"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Classes With Statistics" :value="$kpis['statistics']" icon-bg="bg-badge-purple">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Scheduled Exams" :value="$kpis['exams']" icon-bg="bg-badge-orange">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16"/></svg>
                </x-slot:icon>
            </x-kpi-card>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="reports-filter-period" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Period</span>
                <select id="reports-filter-period" wire:model.live="period"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All periods</option>
                    @foreach ($periodOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="reports-filter-class" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Class</span>
                <select id="reports-filter-class" wire:model.live="classGroup"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All classes</option>
                    @foreach ($classGroupOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>

            @if ($tab === 'marksheet' || $tab === 'exams')
                <label for="reports-filter-subject" class="flex min-w-[11rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Subject</span>
                    <select id="reports-filter-subject" wire:model.live="subject"
                            class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All subjects</option>
                        @foreach ($subjectOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label for="reports-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="reports-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Search student, matricule, class..."
                       class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
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
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Result</th>
                @elseif ($tab === 'statistics')
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Students</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Mean</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Lowest</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Highest</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Pass Rate</th>
                @elseif ($tab === 'exams')
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Time</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                @else
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Score</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">State</th>
                @endif
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr wire:key="report-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
                @if ($tab === 'results')
                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->general_average_rounded ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->rank_position !== null ? $row->rank_position.'/'.$row->rank_denominator : '—' }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->is_pass ? 'ok' : 'red'" :label="$row->is_pass ? 'Pass' : 'Fail'"/>
                    </td>
                @elseif ($tab === 'statistics')
                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->class_group_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->n }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->mean ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->min_score ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->max_score ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->pass_rate !== null ? $row->pass_rate.'%' : '—' }}</td>
                @elseif ($tab === 'exams')
                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->subject_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->scheduled_on }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ substr((string) $row->starts_at, 0, 5) }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->room_name ?? '—' }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$examStatusTone[$row->status] ?? 'amber'" :label="ucfirst(str_replace('_', ' ', $row->status))"/>
                    </td>
                @else
                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->subject_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->score ?? '—' }}</td>
                    <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ str_replace('_', ' ', $row->state) }}</td>
                @endif
            </tr>
        @endforeach

        {{-- Mobile cards: the two or three columns that matter on a handset. --}}
        <x-slot:cards>
            @foreach ($rows as $row)
                <article wire:key="report-card-{{ $tab }}-{{ $row->id }}"
                         class="rounded border border-sand bg-white p-3">
                    @if ($tab === 'results')
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                            <x-status-pill :status="$row->is_pass ? 'ok' : 'red'" :label="$row->is_pass ? 'Pass' : 'Fail'"/>
                        </div>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->class_group_name }} · {{ $row->period_name }} · Avg {{ $row->general_average_rounded ?? '—' }}</p>
                    @elseif ($tab === 'statistics')
                        <p class="font-medium text-charcoal">{{ $row->class_group_name }} · {{ $row->period_name }}</p>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->n }} students · Mean {{ $row->mean ?? '—' }} · Pass {{ $row->pass_rate !== null ? $row->pass_rate.'%' : '—' }}</p>
                    @elseif ($tab === 'exams')
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-medium text-charcoal">{{ $row->subject_name }}</p>
                            <x-status-pill :status="$examStatusTone[$row->status] ?? 'amber'" :label="ucfirst(str_replace('_', ' ', $row->status))"/>
                        </div>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->class_group_name }} · {{ $row->scheduled_on }} {{ substr((string) $row->starts_at, 0, 5) }} · {{ $row->room_name ?? 'No room' }}</p>
                    @else
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->subject_name }} · {{ $row->class_group_name }} · {{ $row->period_name }} · {{ $row->score ?? str_replace('_', ' ', $row->state) }}</p>
                    @endif
                </article>
            @endforeach
        </x-slot:cards>
    </x-list-screen>

    <style>
        @media print {
            nav, aside, .print\:hidden { display: none !important; }
        }
    </style>
</div>
