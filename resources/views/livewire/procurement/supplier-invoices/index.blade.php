@php
    /** Supplier invoice list (03-tax-procurement §10): search + status filter,
        blocking states (match exception, unresolved withholding) as KPIs. */
@endphp

<div class="space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 p-2 text-sm text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

<x-list-screen
    :title="__('opes.supplier_invoice_screen.title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.supplier_invoice_screen.title')]"
    :paginator="$invoices"
    :empty-message="__('opes.supplier_invoice_screen.empty')"
>
    <x-slot:subnav>@include("livewire.procurement._subnav")</x-slot:subnav>
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_pending_approval')" :value="$kpis['pending_approval']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_match_exceptions')" :value="$kpis['match_exceptions']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_withholding_unresolved')" :value="$kpis['withholding_unresolved']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.2 8.2a4 4 0 016.9 2.8c0 2-3 3-3 3m.1 3h.01M12 21a9 9 0 110-18 9 9 0 010 18z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_posted')" :value="$kpis['posted']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="invoices-search" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.search') }}</span>
            <input id="invoices-search" type="search" wire:model.live.debounce.300ms="search"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="invoices-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.filter_status') }}</span>
            <select id="invoices-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach (['draft', 'pending_approval', 'match_exception', 'approved', 'posted', 'partially_paid', 'paid', 'cancelled'] as $option)
                    <option value="{{ $option }}">{{ str_replace('_', ' ', $option) }}</option>
                @endforeach
            </select>
        </label>

        <a href="{{ url('/procurement/invoices/capture') }}"
           class="self-end rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.supplier_invoice_screen.new_invoice') }}
        </a>

        @if ($canManageInvoices)
            <button type="button" wire:click="toggleCreditNoteForm"
                    class="self-end rounded border border-primary px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary/10">
                {{ $showCreditNoteForm ? 'Cancel' : 'Issue credit note' }}
            </button>
        @endif
    </x-slot:filters>

    @if ($showCreditNoteForm)
        <div class="mb-4 rounded border border-border-primary bg-white p-3">
            <p class="mb-2 text-sm font-medium">Issue a supplier credit note (avoir)</p>
            @error('creditNote') <p class="mb-1 text-xs text-heritage-red">{{ $message }}</p> @enderror

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Original invoice (optional)</span>
                    <select wire:model.live="creditNoteInvoiceId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">—</option>
                        @foreach ($postedInvoices as $invoice)
                            <option value="{{ $invoice->id }}">{{ $invoice->internal_no }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.supplier') }}</span>
                    <select wire:model="creditNoteSupplierId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">—</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->code }} {{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Credit note date</span>
                    <input type="date" wire:model="creditNoteDate" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Reason type</span>
                    <select wire:model="creditNoteReasonType" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        @foreach ($creditNoteReasonTypes as $reasonType)
                            <option value="{{ $reasonType }}">{{ str_replace('_', ' ', $reasonType) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label class="mt-3 flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Reason note</span>
                <textarea wire:model="creditNoteReasonNote" rows="2" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"></textarea>
            </label>

            <table class="mt-3 w-full text-xs">
                <thead>
                    <tr class="text-left text-charcoal/70">
                        <th class="px-1 py-1">Invoice line</th>
                        <th class="px-1 py-1">{{ __('opes.supplier_invoice_screen.line_description') }}</th>
                        <th class="px-1 py-1">{{ __('opes.supplier_invoice_screen.line_quantity') }}</th>
                        <th class="px-1 py-1">{{ __('opes.supplier_invoice_screen.line_unit_price') }}</th>
                        <th class="px-1 py-1">{{ __('opes.supplier_invoice_screen.line_tax_code') }}</th>
                        <th class="px-1 py-1">{{ __('opes.supplier_invoice_screen.line_account') }}</th>
                        <th class="px-1 py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($creditNoteLines as $index => $line)
                        <tr wire:key="cn-line-{{ $index }}">
                            <td class="px-1 py-1">
                                <select wire:model="creditNoteLines.{{ $index }}.supplier_invoice_line_id" class="w-full rounded border border-border-primary px-1.5 py-1">
                                    <option value="">—</option>
                                    @foreach ($creditNoteInvoiceLines as $invoiceLine)
                                        <option value="{{ $invoiceLine->id }}">#{{ $invoiceLine->line_no }} {{ $invoiceLine->description }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-1 py-1"><input type="text" wire:model="creditNoteLines.{{ $index }}.description" class="w-full rounded border border-border-primary px-1.5 py-1"/></td>
                            <td class="px-1 py-1"><input type="text" wire:model="creditNoteLines.{{ $index }}.quantity" class="w-16 rounded border border-border-primary px-1.5 py-1"/></td>
                            <td class="px-1 py-1"><input type="number" wire:model="creditNoteLines.{{ $index }}.unit_price_ht" class="w-24 rounded border border-border-primary px-1.5 py-1"/></td>
                            <td class="px-1 py-1">
                                <select wire:model="creditNoteLines.{{ $index }}.tax_code_id" class="w-full rounded border border-border-primary px-1.5 py-1">
                                    <option value="">—</option>
                                    @foreach ($taxCodes as $taxCode)
                                        <option value="{{ $taxCode->id }}">{{ $taxCode->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-1 py-1">
                                <select wire:model="creditNoteLines.{{ $index }}.expense_account_id" class="w-full rounded border border-border-primary px-1.5 py-1">
                                    <option value="">—</option>
                                    @foreach ($expenseAccounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-1 py-1">
                                <button type="button" wire:click="removeCreditNoteLine({{ $index }})" class="text-heritage-red">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="addCreditNoteLine" class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal hover:bg-sand/40">
                    {{ __('opes.supplier_invoice_screen.add_line') }}
                </button>
                <button type="button" wire:click="saveCreditNote" wire:confirm="Issue this credit note?"
                        class="rounded border border-primary bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90">
                    Issue credit note
                </button>
            </div>
        </div>
    @endif

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_internal_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_supplier_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_supplier') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_invoice_date') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_due_date') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.supplier_invoice_screen.col_total_ttc') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.supplier_invoice_screen.col_net_payable') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_match') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_status') }}</th>
        @if ($canManageInvoices)
            <th class="px-3 py-2 text-left"><span class="sr-only">Actions</span></th>
        @endif
    </x-slot:head>

    @foreach ($invoices as $invoice)
        <tr wire:key="invoice-{{ $invoice->id }}" class="border-t border-border-primary/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">
                <a href="{{ url('/procurement/invoices/'.$invoice->id) }}" class="text-primary hover:underline">{{ $invoice->internal_no }}</a>
                <a href="{{ url('/procurement/invoices/capture?invoice='.$invoice->id) }}" class="ml-2 text-xs text-charcoal/50 hover:underline">edit</a>
            </td>
            <td class="px-3 py-2 font-mono text-sm">{{ $invoice->supplier_invoice_no }}</td>
            <td class="px-3 py-2 text-sm">{{ $invoice->supplier_name }}</td>
            <td class="px-3 py-2 text-sm">{{ $invoice->invoice_date }}</td>
            <td class="px-3 py-2 text-sm">{{ $invoice->due_date }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ number_format((int) $invoice->total_ttc, 0, ',', ' ') }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ number_format((int) $invoice->net_payable, 0, ',', ' ') }}</td>
            <td class="px-3 py-2 text-sm">
                @if ($invoice->match_status === 'exception')
                    <x-status-pill status="red" :label="__('opes.supplier_invoice_screen.match_exception')"/>
                @elseif ($invoice->match_status === 'matched')
                    <x-status-pill status="ok" :label="__('opes.supplier_invoice_screen.match_matched')"/>
                @elseif ($invoice->match_status === 'overridden')
                    <x-status-pill status="amber" :label="__('opes.supplier_invoice_screen.match_overridden_pill')"/>
                @else
                    <span class="text-charcoal/50">—</span>
                @endif
                @if ($invoice->withholding_unresolved)
                    <x-status-pill status="amber" :label="__('opes.supplier_invoice_screen.withholding_unresolved')"/>
                @endif
            </td>
            <td class="px-3 py-2 text-sm">{{ str_replace('_', ' ', (string) $invoice->status) }}</td>
            @if ($canManageInvoices)
                <td class="px-3 py-2 text-sm">
                    @if (! in_array($invoice->status, ['partially_paid', 'paid', 'cancelled'], true))
                        <button type="button" wire:click="startCancel({{ $invoice->id }})"
                                class="rounded border border-heritage-red px-2 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/10">
                            Cancel
                        </button>
                    @endif
                </td>
            @endif
        </tr>

        @if ($canManageInvoices && $cancellingId === $invoice->id)
            <tr wire:key="invoice-cancel-{{ $invoice->id }}" class="border-t border-border-primary/60 bg-sand/10">
                <td colspan="9" class="px-3 py-3">
                    <div class="space-y-2">
                        <p class="text-sm font-medium">Cancel {{ $invoice->internal_no }}</p>
                        @error('cancelReason') <p class="text-xs text-heritage-red">{{ $message }}</p> @enderror
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Cancellation reason</span>
                            <textarea wire:model="cancelReason" rows="2" class="w-full max-w-lg rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"></textarea>
                        </label>
                        <div class="flex gap-2">
                            <button type="button" wire:click="confirmCancel" wire:confirm="Cancel this supplier invoice?"
                                    class="rounded border border-heritage-red bg-heritage-red px-3 py-1.5 text-xs font-medium text-white hover:bg-heritage-red/90">
                                Confirm cancellation
                            </button>
                            <button type="button" wire:click="cancelCancel" class="rounded border border-border-primary px-3 py-1.5 text-xs font-medium text-charcoal hover:bg-sand/40">
                                Close
                            </button>
                        </div>
                    </div>
                </td>
            </tr>
        @endif
    @endforeach

    <x-slot:cards>
        @foreach ($invoices as $invoice)
            <article wire:key="invoice-card-{{ $invoice->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-center justify-between">
                    <a href="{{ url('/procurement/invoices/capture?invoice='.$invoice->id) }}" class="font-mono text-sm text-primary hover:underline">{{ $invoice->internal_no }}</a>
                    <span class="text-xs text-charcoal/60">{{ str_replace('_', ' ', (string) $invoice->status) }}</span>
                </div>
                <p class="mt-1 text-sm">{{ $invoice->supplier_name }} · {{ $invoice->supplier_invoice_no }}</p>
                <p class="mt-1 font-mono text-sm">{{ number_format((int) $invoice->total_ttc, 0, ',', ' ') }} FCFA</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
</div>
