@php
    use App\Support\Money\Money;

    $tabs = [
        ['value' => 'declarations', 'label' => 'Declarations Register'],
        ['value' => 'withholding', 'label' => 'Withholding Register'],
        ['value' => 'tax-codes', 'label' => 'Tax Code Configuration Summary'],
        ['value' => 'vat-summary', 'label' => 'VAT Summary'],
    ];
@endphp

<div class="min-w-0 space-y-4 print:space-y-2">
    <style>
        @media print {
            nav, .no-print { display: none !important; }
            body { background: #fff; }
        }
    </style>

    <x-list-screen
        title="Tax Reports"
        :breadcrumb="['Dashboard', 'Tax', 'Tax Reports']"
        :paginator="$rows"
        empty-message="No data for the selected filters."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2 no-print">
                <button type="button" wire:click="exportExcel"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export Excel
                </button>
                <button type="button" wire:click="exportPdf"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    Print
                </button>
            </div>
        </x-slot:actions>

        <x-slot:filters>
            @if ($tab === 'declarations' || $tab === 'vat-summary')
                <label for="tr-fiscal-year" class="flex min-w-[10rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Fiscal year</span>
                    <select id="tr-fiscal-year" wire:model.live="fiscalYearId"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All</option>
                        @foreach ($fiscalYearOptions as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}">{{ $fiscalYear->code }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($tab === 'declarations')
                <label for="tr-declaration-type" class="flex min-w-[12rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Declaration type</span>
                    <select id="tr-declaration-type" wire:model.live="declarationType"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All</option>
                        @foreach ($declarationTypeOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if (count($statusOptions) > 0)
                <label for="tr-status" class="flex min-w-[10rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Status</span>
                    <select id="tr-status" wire:model.live="status"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All</option>
                        @foreach ($statusOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
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
            @if ($tab === 'withholding')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Attestation No</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Supplier</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Base</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Rate</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Withheld</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Issued</th>
                </tr>
            @elseif ($tab === 'tax-codes')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Rate</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Direction</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Active</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Effective from</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Effective to</th>
                </tr>
            @elseif ($tab === 'vat-summary')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Fiscal Year</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Basis</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Rate</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Numerator</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Denominator</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Confirmed</th>
                </tr>
            @else
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Filed date</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Due date</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount declared</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount paid</th>
                </tr>
            @endif
        </x-slot:head>

        @if ($tab === 'withholding')
            @foreach ($rows as $row)
                <tr wire:key="wh-row-{{ $row->id }}">
                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $row->attestation_no }}</td>
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->supplier_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ sprintf('%04d-%02d', $row->period_year, $row->period_month) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->base_amount)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ number_format($row->rate_bp_applied / 1000, 2) }}%</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->withheld_amount)->format(false) }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->status === 'cancelled' ? 'red' : ($row->status === 'issued' ? 'ok' : 'amber')" :label="ucfirst($row->status)"/>
                    </td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->issued_at !== null ? \Illuminate\Support\Carbon::parse($row->issued_at)->format('d/m/Y') : '—' }}</td>
                </tr>
            @endforeach
        @elseif ($tab === 'tax-codes')
            @foreach ($rows as $row)
                <tr wire:key="tc-row-{{ $row->id }}">
                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $row->code }}</td>
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->tax_type }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ number_format($row->rate_bp / 1000, 2) }}%</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ ucfirst($row->direction) }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->is_active ? 'ok' : 'amber'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                    </td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Carbon::parse($row->effective_from)->format('d/m/Y') }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->effective_to !== null ? \Illuminate\Support\Carbon::parse($row->effective_to)->format('d/m/Y') : '—' }}</td>
                </tr>
            @endforeach
        @elseif ($tab === 'vat-summary')
            @foreach ($rows as $row)
                <tr wire:key="vs-row-{{ $row->id }}">
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->fiscal_year_code }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ ucfirst($row->basis) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ number_format($row->rate_bp / 1000, 2) }}%</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->numerator_amount)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->denominator_amount)->format(false) }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->confirmed_at !== null ? 'ok' : 'amber'" :label="$row->confirmed_at !== null ? 'Confirmed' : 'Pending'"/>
                    </td>
                </tr>
            @endforeach
        @else
            @foreach ($rows as $row)
                <tr wire:key="dr-row-{{ $row->id }}">
                    <td class="px-4 py-2.5 text-charcoal">{{ match ($row->declaration_type) {
                        'tva_monthly' => 'TVA (monthly)',
                        'withholding_monthly' => 'Withholding (monthly)',
                        'dsf_annual' => 'DSF (annual)',
                        default => str_replace('_', ' ', $row->declaration_type),
                    } }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_month > 0 ? sprintf('%04d-%02d', $row->period_year, $row->period_month) : $row->period_year }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="in_array($row->status, ['filed', 'paid'], true) ? 'ok' : ($row->status === 'cancelled' ? 'red' : 'amber')" :label="ucfirst(str_replace('_', ' ', $row->status))"/>
                    </td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->filed_at !== null ? \Illuminate\Support\Carbon::parse($row->filed_at)->format('d/m/Y') : '—' }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->due_date !== null ? \Illuminate\Support\Carbon::parse($row->due_date)->format('d/m/Y') : '—' }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->amount_declared)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of((int) $row->amount_paid)->format(false) }}</td>
                </tr>
            @endforeach
        @endif
    </x-list-screen>
</div>
