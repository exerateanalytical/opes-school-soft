@php
    use App\Support\Money\Money;

    $methodLabels = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile Money',
        'bank' => 'Bank',
    ];

    $invoiceStatusTone = [
        'draft' => 'amber',
        'issued' => 'ok',
        'cancelled' => 'red',
    ];

    $paymentStatusTone = [
        'cleared' => 'ok',
        'pending' => 'amber',
        'bounced' => 'red',
    ];
@endphp

<div class="min-w-0 space-y-4">
    {{-- Print header, hidden on screen - @media print flips the visibility. --}}
    <div class="print-only hidden">
        <h1 class="text-lg font-bold">Fees Reports - {{ collect($tabs)->firstWhere('value', $tab)['label'] ?? '' }}</h1>
        <p class="text-xs text-charcoal/60">Generated {{ now()->format('Y-m-d H:i') }}</p>
    </div>

<x-list-screen
    title="Fees Reports"
    :breadcrumb="['Dashboard', 'Fees', 'Reports']"
    :paginator="$rows"
    empty-message="No records match these filters yet."
    class="print-area"
>
    <x-slot:actions>
        <button type="button" wire:click="exportExcel" wire:loading.attr="disabled"
                class="no-print rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            Export Excel
        </button>
        <button type="button" wire:click="exportPdf" wire:loading.attr="disabled"
                class="no-print rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            Export PDF
        </button>
        <button type="button" onclick="window.print()"
                class="no-print rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
            Print
        </button>
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card label="Total Collected" :value="Money::of($kpis['collected'])->format(false)" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v10M9 9.5c0-1.4 1.3-2.5 3-2.5s3 .8 3 2c0 3-6 1.5-6 4.5 0 1.2 1.3 2 3 2s3-1.1 3-2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Outstanding Balance" :value="Money::of($kpis['outstanding'])->format(false)" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 005.4-5.4l-2.4 2.4-2.3-2.3 2.4-2.4z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Students with Balance" :value="$kpis['invoiced_students']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Invoices Issued" :value="$kpis['invoices_issued']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19V5a2 2 0 012-2h10a2 2 0 012 2v14"/><path stroke-linecap="round" d="M9 7h6M9 11h6M9 15h3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="fees-report-filter-class" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Class</span>
            <select id="fees-report-filter-class" wire:model.live="classGroup"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All classes</option>
                @foreach ($classGroupOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="fees-report-filter-year" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Academic year</span>
            <select id="fees-report-filter-year" wire:model.live="academicYear"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All years</option>
                @foreach ($academicYearOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($statusOptions !== [])
            <label for="fees-report-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="fees-report-filter-status" wire:model.live="status"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="fees-report-filter-from" class="flex min-w-[9rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">From</span>
            <input id="fees-report-filter-from" type="date" wire:model.live="dateFrom"
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="fees-report-filter-to" class="flex min-w-[9rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">To</span>
            <input id="fees-report-filter-to" type="date" wire:model.live="dateTo"
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
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'outstanding')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Invoiced Total</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Paid / Settled</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Balance Due</th>
            @elseif ($tab === 'invoices')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Invoice No</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'payments')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Receipt No</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Method</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Method</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Transactions</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total Collected</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        @if ($tab === 'outstanding')
            <tr wire:key="fees-report-outstanding-{{ $row->student_id }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->first_name }} {{ $row->last_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->invoiced_total)->format(false) }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->invoiced_total - (int) $row->balance)->format(false) }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-heritage-red">{{ Money::of((int) $row->balance)->format(false) }}</td>
            </tr>
        @elseif ($tab === 'invoices')
            <tr wire:key="fees-report-invoice-{{ $row->id }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->invoice_no ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->first_name }} {{ $row->last_name }} ({{ $row->matricule }})</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->issue_date }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->gross_total)->format(false) }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$invoiceStatusTone[$row->status] ?? 'ok'" :label="ucfirst($row->status)"/>
                </td>
            </tr>
        @elseif ($tab === 'payments')
            <tr wire:key="fees-report-payment-{{ $row->id }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->receipt_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->first_name }} {{ $row->last_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->value_date }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $methodLabels[$row->payment_method] ?? $row->payment_method }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->amount)->format(false) }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$paymentStatusTone[$row->clearing_state] ?? 'ok'" :label="ucfirst($row->clearing_state)"/>
                </td>
            </tr>
        @else
            <tr wire:key="fees-report-collection-{{ $row->value_date }}-{{ $row->payment_method }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->value_date }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $methodLabels[$row->payment_method] ?? $row->payment_method }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->txn_count }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->total_amount)->format(false) }}</td>
            </tr>
        @endif
    @endforeach
</x-list-screen>
</div>

@push('styles')
<style>
    @media print {
        .no-print { display: none !important; }
        .print-only { display: block !important; }
        body * { visibility: hidden; }
        .print-area, .print-area *, .print-only, .print-only * { visibility: visible; }
        .print-area { position: absolute; left: 0; top: 0; width: 100%; }
    }
</style>
@endpush
