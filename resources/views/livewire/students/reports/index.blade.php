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
            title="Students & Guardians Reports"
            :breadcrumb="['Dashboard', 'Reports', 'Students & Guardians Reports']"
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
                @if (in_array($report, ['student_register', 'admission_register', 'attendance_summary'], true))
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

                @if (count($statusOptions) > 0)
                    <label for="report-filter-status" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Status</span>
                        <select id="report-filter-status" wire:model.live="status"
                                class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All statuses</option>
                            @foreach ($statusOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($report, ['student_register', 'admission_register', 'attendance_summary'], true))
                    <label for="report-filter-date-from" class="flex min-w-[9rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">From</span>
                        <input type="date" id="report-filter-date-from" wire:model.live="dateFrom"
                               class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                    </label>
                    <label for="report-filter-date-to" class="flex min-w-[9rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">To</span>
                        <input type="date" id="report-filter-date-to" wire:model.live="dateTo"
                               class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                    </label>
                @endif
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
                    @if ($report === 'guardian_directory')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Guardian No.</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Phone</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Linked Students</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Portal Status</th>
                    @elseif ($report === 'admission_register')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Admission No.</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Admission Date</th>
                    @elseif ($report === 'attendance_summary')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Present</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Absent</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Late</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Excused</th>
                    @else
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Gender</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="report-{{ $report }}-{{ $row->id ?? $loop->index }}" class="hover:bg-sand/30">
                    @if ($report === 'guardian_directory')
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->guardian_no }}</td>
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->phone }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->students_count }}</td>
                        <td class="px-4 py-2.5">
                            <x-status-pill :status="$row->portal_user_id ? 'ok' : 'amber'" :label="$row->portal_user_id ? 'Active' : 'Not activated'"/>
                        </td>
                    @elseif ($report === 'admission_register')
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->admission_no }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->first_admission_date }}</td>
                    @elseif ($report === 'attendance_summary')
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->sessions_present }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->sessions_absent }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->sessions_late }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->sessions_excused }}</td>
                    @else
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->gender }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ str_replace('_', ' ', $row->status) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-list-screen>
    </div>

    {{-- Print-only clean table: the on-screen x-list-screen (KPIs, filters,
         nav chrome) is hidden by .print-hide above; this simple table is
         what actually prints. --}}
    <div class="hidden print:block">
        <h1 class="mb-4 text-xl font-semibold">Students & Guardians Reports — {{ $reportTabs[array_search($report, array_column($reportTabs, 'value'), true)]['label'] ?? 'Report' }}</h1>
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-black/40 text-left">
                    @if ($report === 'guardian_directory')
                        <th class="py-1">Guardian No.</th>
                        <th class="py-1">Name</th>
                        <th class="py-1">Phone</th>
                        <th class="py-1 text-right">Linked Students</th>
                        <th class="py-1">Portal Status</th>
                    @elseif ($report === 'admission_register')
                        <th class="py-1">Admission No.</th>
                        <th class="py-1">Matricule</th>
                        <th class="py-1">Student</th>
                        <th class="py-1">Class</th>
                        <th class="py-1">Admission Date</th>
                    @elseif ($report === 'attendance_summary')
                        <th class="py-1">Matricule</th>
                        <th class="py-1">Student</th>
                        <th class="py-1">Class</th>
                        <th class="py-1">Period</th>
                        <th class="py-1 text-right">Present</th>
                        <th class="py-1 text-right">Absent</th>
                        <th class="py-1 text-right">Late</th>
                        <th class="py-1 text-right">Excused</th>
                    @else
                        <th class="py-1">Matricule</th>
                        <th class="py-1">Student</th>
                        <th class="py-1">Class</th>
                        <th class="py-1">Gender</th>
                        <th class="py-1">Status</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-black/10" wire:key="print-{{ $report }}-{{ $row->id ?? $loop->index }}">
                        @if ($report === 'guardian_directory')
                            <td class="py-1">{{ $row->guardian_no }}</td>
                            <td class="py-1">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                            <td class="py-1">{{ $row->phone }}</td>
                            <td class="py-1 text-right">{{ $row->students_count }}</td>
                            <td class="py-1">{{ $row->portal_user_id ? 'Active' : 'Not activated' }}</td>
                        @elseif ($report === 'admission_register')
                            <td class="py-1">{{ $row->admission_no }}</td>
                            <td class="py-1">{{ $row->matricule }}</td>
                            <td class="py-1">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                            <td class="py-1">{{ $row->class_group_name ?? '—' }}</td>
                            <td class="py-1">{{ $row->first_admission_date }}</td>
                        @elseif ($report === 'attendance_summary')
                            <td class="py-1">{{ $row->matricule }}</td>
                            <td class="py-1">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                            <td class="py-1">{{ $row->class_group_name ?? '—' }}</td>
                            <td class="py-1">{{ $row->period_name }}</td>
                            <td class="py-1 text-right">{{ $row->sessions_present }}</td>
                            <td class="py-1 text-right">{{ $row->sessions_absent }}</td>
                            <td class="py-1 text-right">{{ $row->sessions_late }}</td>
                            <td class="py-1 text-right">{{ $row->sessions_excused }}</td>
                        @else
                            <td class="py-1">{{ $row->matricule }}</td>
                            <td class="py-1">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                            <td class="py-1">{{ $row->class_group_name ?? '—' }}</td>
                            <td class="py-1">{{ $row->gender }}</td>
                            <td class="py-1">{{ str_replace('_', ' ', $row->status) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
