@php
    use App\Support\Money\Money;

    $tabs = [
        ['value' => 'trial-balance', 'label' => 'Trial Balance'],
        ['value' => 'general-ledger', 'label' => 'General Ledger'],
        ['value' => 'journal-register', 'label' => 'Journal Register'],
        ['value' => 'account-statement', 'label' => 'Account Statement'],
        ['value' => 'treasury-position', 'label' => 'Treasury Position'],
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
        title="Financial Reports"
        :breadcrumb="['Dashboard', 'Ledger', 'Financial Reports']"
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
            <label for="fr-fiscal-year" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Fiscal year</span>
                <select id="fr-fiscal-year" wire:model.live="fiscalYearId"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All</option>
                    @foreach ($fiscalYearOptions as $fiscalYear)
                        <option value="{{ $fiscalYear->id }}">{{ $fiscalYear->code }}</option>
                    @endforeach
                </select>
            </label>

            @if ($tab !== 'trial-balance' && $tab !== 'account-statement' && $tab !== 'treasury-position')
                <label for="fr-accounting-period" class="flex min-w-[10rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Accounting period</span>
                    <select id="fr-accounting-period" wire:model.live="accountingPeriodId"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All</option>
                        @foreach ($accountingPeriodOptions as $period)
                            <option value="{{ $period->id }}">{{ $period->period_month->format('Y-m') }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($tab === 'account-statement')
                <label for="fr-account" class="flex min-w-[14rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Account</span>
                    <select id="fr-account" wire:model.live="accountId"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">Select an account</option>
                        @foreach ($accountOptions as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            {{-- resetFilters() existed on the component with nothing bound to it;
                 this is that control, not new behaviour. --}}
            <div class="flex flex-col justify-end">
                <button type="button" wire:click="resetFilters"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    {{ __('opes.ui.reset') }}
                </button>
            </div>
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
            @if ($tab === 'trial-balance')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Account</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Debit</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Credit</th>
                </tr>
            @elseif ($tab === 'general-ledger')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Journal</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Account</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Label</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Debit</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Credit</th>
                </tr>
            @elseif ($tab === 'treasury-position')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Account</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Debits</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Credits</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Balance</th>
                </tr>
            @elseif ($tab === 'journal-register')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Reference</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Journal</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total</th>
                </tr>
            @else
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Piece No</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Label</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Debit</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Credit</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Running balance</th>
                </tr>
            @endif
        </x-slot:head>

        @if ($tab === 'trial-balance')
            @foreach ($rows as $row)
                <tr wire:key="tb-row-{{ $row->account_id }}">
                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $row->code }}</td>
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->name }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->total_debit === 0 ? '' : Money::of($row->total_debit)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->total_credit === 0 ? '' : Money::of($row->total_credit)->format(false) }}</td>
                </tr>
            @endforeach
        @elseif ($tab === 'general-ledger')
            @foreach ($rows as $row)
                <tr wire:key="gl-row-{{ $row->line_id }}">
                    <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Carbon::parse($row->date)->format('d/m/Y') }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->journal_code }}</td>
                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $row->account_code }} — {{ $row->account_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->label }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ (int) $row->debit === 0 ? '' : Money::of((int) $row->debit)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ (int) $row->credit === 0 ? '' : Money::of((int) $row->credit)->format(false) }}</td>
                </tr>
            @endforeach
        @elseif ($tab === 'treasury-position')
            @foreach ($rows as $row)
                <tr wire:key="tp-row-{{ $row->account_id }}">
                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $row->code }}</td>
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/70">{{ $row->type_label }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->total_debit === 0 ? '' : Money::of($row->total_debit)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->total_credit === 0 ? '' : Money::of($row->total_credit)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono font-semibold {{ $row->balance < 0 ? 'text-heritage-red' : 'text-charcoal' }}">{{ Money::of($row->balance)->format(false) }}</td>
                </tr>
            @endforeach
        @elseif ($tab === 'journal-register')
            @foreach ($rows as $entry)
                <tr wire:key="jr-row-{{ $entry->id }}">
                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $entry->piece_no ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $entry->date->format('d/m/Y') }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $journalOptions->get($entry->journal_id)?->code ?? '—' }}</td>
                    <td class="px-4 py-2.5">
                        {{-- A journal entry is draft|posted|reversed. `draft` carries no
                             accounting reality yet, so it must NOT read as a settled green
                             pill; `reversed` is the only red one. --}}
                        <x-status-pill :status="match ($entry->status) { 'reversed' => 'red', 'draft' => 'amber', default => 'ok' }" :label="ucfirst($entry->status)"/>
                    </td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($entry->total_debit)->format(false) }}</td>
                </tr>
            @endforeach
        @else
            @foreach ($rows as $row)
                <tr wire:key="as-row-{{ $row->line_id }}">
                    <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Carbon::parse($row->date)->format('d/m/Y') }}</td>
                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $row->piece_no ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->label }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->debit === 0 ? '' : Money::of($row->debit)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->credit === 0 ? '' : Money::of($row->credit)->format(false) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($row->running_balance)->format(false) }}</td>
                </tr>
            @endforeach
        @endif
    </x-list-screen>
</div>
