@php
    $tabs = [
        ['value' => 'staff', 'label' => 'Staff Register', 'count' => $tabCounts['staff']],
        ['value' => 'contracts', 'label' => 'Contract Register', 'count' => $tabCounts['contracts']],
        ['value' => 'leave', 'label' => 'Leave Register', 'count' => $tabCounts['leave']],
        ['value' => 'payslips', 'label' => 'Payslip Summary', 'count' => $tabCounts['payslips']],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    <x-list-screen
        title="HR & Payroll Reports"
        :breadcrumb="['Reports', 'HR & Payroll']"
        :paginator="$rows"
        empty-message="No records match the current filters."
    >
        <x-slot:actions>
            <button type="button" wire:click="exportExcel"
                    class="print:hidden rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Export Excel
            </button>
            <button type="button" wire:click="exportPdf"
                    class="print:hidden rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Export PDF
            </button>
            <button type="button" onclick="window.print()"
                    class="print:hidden rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                Print
            </button>
        </x-slot:actions>

        <x-slot:kpis>
            <x-kpi-card label="Total staff" :value="$kpis['total_staff']"/>
            <x-kpi-card label="Active contracts" :value="$kpis['active_contracts']"/>
            <x-kpi-card label="Pending leave" :value="$kpis['pending_leave']"/>
            <x-kpi-card label="Selected run net pay" :value="number_format($kpis['latest_run_net_pay'])"/>
        </x-slot:kpis>

        <x-slot:filters>
            @if (in_array($tab, ['staff', 'contracts', 'leave', 'payslips'], true))
                <label for="report-filter-department" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Department</span>
                    <select id="report-filter-department" wire:model.live="department"
                            class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">All departments</option>
                        @foreach ($departmentOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($statusOptions !== [])
                <label for="report-filter-status" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Status</span>
                    <select id="report-filter-status" wire:model.live="status"
                            class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">All statuses</option>
                        @foreach ($statusOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($tab === 'payslips')
                <label for="report-filter-payroll-run" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Payroll run</span>
                    <select id="report-filter-payroll-run" wire:model.live="payrollRun"
                            class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a run</option>
                        @foreach ($payrollRunOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </x-slot:filters>

        <x-slot:tabs>
            @foreach ($tabs as $t)
                <button type="button" wire:click="selectTab('{{ $t['value'] }}')"
                        class="print:hidden whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium
                               {{ $tab === $t['value'] ? 'border-primary text-primary' : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ $t['label'] }}
                    <span class="ml-1 text-xs text-charcoal/50">({{ $t['count'] }})</span>
                </button>
            @endforeach
        </x-slot:tabs>

        @if ($tab === 'contracts')
            <x-slot:head>
                <tr>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Staff No</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Staff Name</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Contract Type</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Department</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Position</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Starts On</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Ends On</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Status</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr>
                    <td class="px-3 py-2">{{ $row->staff_no }}</td>
                    <td class="px-3 py-2">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                    <td class="px-3 py-2">{{ $row->contract_type }}</td>
                    <td class="px-3 py-2">{{ $row->department_name }}</td>
                    <td class="px-3 py-2">{{ $row->position_name }}</td>
                    <td class="px-3 py-2">{{ $row->starts_on }}</td>
                    <td class="px-3 py-2">{{ $row->ends_on ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $row->ends_on === null ? 'Active' : 'Ended' }}</td>
                </tr>
            @endforeach

            <x-slot:cards>
                @foreach ($rows as $row)
                    <article class="rounded border border-sand bg-white p-3">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }} ({{ $row->staff_no }})</p>
                        <p class="text-sm text-charcoal/70">{{ $row->contract_type }} · {{ $row->department_name }} · {{ $row->position_name }}</p>
                        <p class="text-sm text-charcoal/70">{{ $row->starts_on }} – {{ $row->ends_on ?? 'present' }}</p>
                    </article>
                @endforeach
            </x-slot:cards>
        @elseif ($tab === 'leave')
            <x-slot:head>
                <tr>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Staff No</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Staff Name</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Leave Type</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Starts On</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Ends On</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Working Days</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Status</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr>
                    <td class="px-3 py-2">{{ $row->staff_no }}</td>
                    <td class="px-3 py-2">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                    <td class="px-3 py-2">{{ $row->leave_type_name }}</td>
                    <td class="px-3 py-2">{{ $row->starts_on }}</td>
                    <td class="px-3 py-2">{{ $row->ends_on }}</td>
                    <td class="px-3 py-2">{{ $row->working_days }}</td>
                    <td class="px-3 py-2">{{ ucfirst($row->status) }}</td>
                </tr>
            @endforeach

            <x-slot:cards>
                @foreach ($rows as $row)
                    <article class="rounded border border-sand bg-white p-3">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }} ({{ $row->staff_no }})</p>
                        <p class="text-sm text-charcoal/70">{{ $row->leave_type_name }} · {{ $row->starts_on }} – {{ $row->ends_on }} · {{ $row->working_days }} day(s)</p>
                        <p class="text-sm text-charcoal/70">{{ ucfirst($row->status) }}</p>
                    </article>
                @endforeach
            </x-slot:cards>
        @elseif ($tab === 'payslips')
            <x-slot:head>
                <tr>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Staff No</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Staff Name</th>
                    <th class="px-3 py-2 text-right font-medium text-charcoal/70">Gross</th>
                    <th class="px-3 py-2 text-right font-medium text-charcoal/70">Total Deductions</th>
                    <th class="px-3 py-2 text-right font-medium text-charcoal/70">Net Pay</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr>
                    <td class="px-3 py-2">{{ $row->staff_no }}</td>
                    <td class="px-3 py-2">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($row->gross) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($row->total_employee_deductions) }}</td>
                    <td class="px-3 py-2 text-right font-medium">{{ number_format($row->net) }}</td>
                </tr>
            @endforeach

            <x-slot:cards>
                @foreach ($rows as $row)
                    <article class="rounded border border-sand bg-white p-3">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }} ({{ $row->staff_no }})</p>
                        <p class="text-sm text-charcoal/70">Gross {{ number_format($row->gross) }} · Deductions {{ number_format($row->total_employee_deductions) }}</p>
                        <p class="text-sm font-medium text-charcoal">Net {{ number_format($row->net) }}</p>
                    </article>
                @endforeach
            </x-slot:cards>
        @else
            <x-slot:head>
                <tr>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Staff No</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">First Name</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Last Name</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Department</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Position</th>
                    <th class="px-3 py-2 font-medium text-charcoal/70">Status</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr>
                    <td class="px-3 py-2">{{ $row->staff_no }}</td>
                    <td class="px-3 py-2">{{ $row->first_name }}</td>
                    <td class="px-3 py-2">{{ $row->last_name }}</td>
                    <td class="px-3 py-2">{{ $row->department_name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $row->position_name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $row->status)) }}</td>
                </tr>
            @endforeach

            <x-slot:cards>
                @foreach ($rows as $row)
                    <article class="rounded border border-sand bg-white p-3">
                        <p class="font-medium text-charcoal">{{ $row->first_name }} {{ $row->last_name }} ({{ $row->staff_no }})</p>
                        <p class="text-sm text-charcoal/70">{{ $row->department_name ?? '-' }} · {{ $row->position_name ?? '-' }}</p>
                        <p class="text-sm text-charcoal/70">{{ ucfirst(str_replace('_', ' ', $row->status)) }}</p>
                    </article>
                @endforeach
            </x-slot:cards>
        @endif
    </x-list-screen>
</div>

@once
    <style>
        @media print {
            nav, header, aside, .print\:hidden {
                display: none !important;
            }
        }
    </style>
@endonce
