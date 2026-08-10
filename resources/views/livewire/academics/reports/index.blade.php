@php
    $tabs = $reportTabs;
@endphp

<div class="min-w-0 space-y-4">
    <style>
        @media print {
            .print-hide { display: none !important; }
            .print-shell { box-shadow: none !important; border: none !important; }
        }
    </style>

    <div class="print-hide">
        <x-list-screen
            title="Academic Reports"
            :breadcrumb="['Dashboard', 'Reports', 'Academic Reports']"
            :paginator="$rows"
            empty-message="No data matches this report's filters yet."
        >
            <x-slot:actions>
                <button type="button" wire:click="exportExcel"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export Excel
                </button>
                <button type="button" wire:click="exportPdf"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Print
                </button>
            </x-slot:actions>

            <x-slot:filters>
                @if (in_array($report, ['class_list', 'timetable'], true))
                    <label for="report-filter-class-group" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Class group</span>
                        <select id="report-filter-class-group" wire:model.live="classGroup"
                                class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All class groups</option>
                            @foreach ($classGroupOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if ($report === 'subject_allocation')
                    <label for="report-filter-class-level" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Class level</span>
                        <select id="report-filter-class-level" wire:model.live="classLevel"
                                class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All class levels</option>
                            @foreach ($classLevelOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <label for="report-filter-academic-year" class="flex min-w-[11rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Academic year</span>
                    <select id="report-filter-academic-year" wire:model.live="academicYear"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All academic years</option>
                        @foreach ($academicYearOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            </x-slot:filters>

            <x-slot:tabs>
                @foreach ($tabs as $tabOption)
                    <button type="button" wire:click="selectReport('{{ $tabOption['value'] }}')"
                            @if ($report === $tabOption['value']) aria-current="page" @endif
                            class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $report === $tabOption['value']
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                        {{ $tabOption['label'] }}
                    </button>
                @endforeach
            </x-slot:tabs>

            <x-slot:head>
                <tr class="bg-chrome text-white">
                    @if ($report === 'subject_allocation')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class Level</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject Code</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject Name</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Coefficient</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Optional</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Active</th>
                    @elseif ($report === 'timetable')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Day</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Time</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Teacher</th>
                    @elseif ($report === 'promotion')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Decision</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Outcome</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Target Class</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Decided On</th>
                    @else
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Gender</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Roll No.</th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="report-{{ $report }}-{{ $row->id ?? $loop->index }}" class="hover:bg-sand/30">
                    @if ($report === 'subject_allocation')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->class_level_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->subject_code }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->subject_name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->coefficient }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->is_optional ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2.5">
                            <x-status-pill :status="$row->is_active ? 'ok' : 'red'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                        </td>
                    @elseif ($report === 'timetable')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->class_group_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][(int) $row->day_of_week] ?? $row->day_of_week }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ substr((string) $row->starts_at, 0, 5) }} - {{ substr((string) $row->ends_at, 0, 5) }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->subject_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ trim($row->staff_first_name.' '.$row->staff_last_name) }}</td>
                    @elseif ($report === 'promotion')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->decision ?? '—' }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->outcome ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->target_class_group_key ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->decided_at }}</td>
                    @else
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->gender }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->roll_number ?? '—' }}</td>
                    @endif
                </tr>
            @endforeach
        </x-list-screen>
    </div>

    {{-- Print-only clean table: the on-screen x-list-screen (KPIs, filters,
         nav chrome) is hidden by .print-hide above; this simple table is
         what actually prints. --}}
    <div class="hidden print:block">
        <h1 class="mb-4 text-xl font-semibold">Academic Reports — {{ $reportTabs[array_search($report, array_column($reportTabs, 'value'), true)]['label'] ?? 'Report' }}</h1>
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-black/40 text-left">
                    @if ($report === 'subject_allocation')
                        <th class="py-1">Class Level</th>
                        <th class="py-1">Subject Code</th>
                        <th class="py-1">Subject Name</th>
                        <th class="py-1 text-right">Coefficient</th>
                        <th class="py-1">Optional</th>
                        <th class="py-1">Active</th>
                    @elseif ($report === 'timetable')
                        <th class="py-1">Class</th>
                        <th class="py-1">Day</th>
                        <th class="py-1">Period</th>
                        <th class="py-1">Time</th>
                        <th class="py-1">Subject</th>
                        <th class="py-1">Teacher</th>
                    @elseif ($report === 'promotion')
                        <th class="py-1">Student</th>
                        <th class="py-1">Matricule</th>
                        <th class="py-1">Decision</th>
                        <th class="py-1">Outcome</th>
                        <th class="py-1">Target Class</th>
                        <th class="py-1">Decided On</th>
                    @else
                        <th class="py-1">Class</th>
                        <th class="py-1">Matricule</th>
                        <th class="py-1">Student</th>
                        <th class="py-1">Gender</th>
                        <th class="py-1 text-right">Roll No.</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-black/10" wire:key="print-{{ $report }}-{{ $row->id ?? $loop->index }}">
                        @if ($report === 'subject_allocation')
                            <td class="py-1">{{ $row->class_level_name }}</td>
                            <td class="py-1">{{ $row->subject_code }}</td>
                            <td class="py-1">{{ $row->subject_name }}</td>
                            <td class="py-1 text-right">{{ $row->coefficient }}</td>
                            <td class="py-1">{{ $row->is_optional ? 'Yes' : 'No' }}</td>
                            <td class="py-1">{{ $row->is_active ? 'Yes' : 'No' }}</td>
                        @elseif ($report === 'timetable')
                            <td class="py-1">{{ $row->class_group_name }}</td>
                            <td class="py-1">{{ ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][(int) $row->day_of_week] ?? $row->day_of_week }}</td>
                            <td class="py-1">{{ $row->period_name }}</td>
                            <td class="py-1">{{ substr((string) $row->starts_at, 0, 5) }} - {{ substr((string) $row->ends_at, 0, 5) }}</td>
                            <td class="py-1">{{ $row->subject_name }}</td>
                            <td class="py-1">{{ trim($row->staff_first_name.' '.$row->staff_last_name) }}</td>
                        @elseif ($report === 'promotion')
                            <td class="py-1">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                            <td class="py-1">{{ $row->matricule }}</td>
                            <td class="py-1">{{ $row->decision ?? '—' }}</td>
                            <td class="py-1">{{ $row->outcome ?? '—' }}</td>
                            <td class="py-1">{{ $row->target_class_group_key ?? '—' }}</td>
                            <td class="py-1">{{ $row->decided_at }}</td>
                        @else
                            <td class="py-1">{{ $row->class_group_name }}</td>
                            <td class="py-1">{{ $row->matricule }}</td>
                            <td class="py-1">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                            <td class="py-1">{{ $row->gender }}</td>
                            <td class="py-1 text-right">{{ $row->roll_number ?? '—' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
