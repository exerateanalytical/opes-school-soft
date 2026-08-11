@php
    /** Supplier invoice capture (03-tax-procurement §10): line grid, then
        match panel + tax panel with the applied withholding rule named. */
@endphp

<div class="mx-auto max-w-6xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60">
        {{ __('opes.nav.dashboard') }} / <a href="{{ url('/procurement/invoices') }}" class="text-primary hover:underline">{{ __('opes.supplier_invoice_screen.title') }}</a> / {{ __('opes.supplier_invoice_screen.capture_title') }}
    </nav>

    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.supplier_invoice_screen.capture_title') }}</h1>

    @if ($error)
        <div class="rounded border border-badge-red/40 bg-badge-red/10 px-3 py-2 text-sm text-badge-red" role="alert">{{ $error }}</div>
    @endif

    @if ($notice)
        <div class="rounded border border-badge-blue/40 bg-badge-blue/10 px-3 py-2 text-sm text-charcoal" role="status">{{ $notice }}</div>
    @endif

    @if ($invoiceModel === null)
        {{-- ── Capture form ─────────────────────────────────────────── --}}
        <section class="rounded border border-border-primary bg-white p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.supplier') }}</span>
                    <select wire:model="supplierId" class="rounded border border-border-primary px-2 py-1.5">
                        <option value="">—</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.supplier_invoice_no') }}</span>
                    <input type="text" wire:model="supplierInvoiceNo" class="rounded border border-border-primary px-2 py-1.5"/>
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.invoice_date') }}</span>
                    <input type="date" wire:model="invoiceDate" class="rounded border border-border-primary px-2 py-1.5"/>
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.due_date') }}</span>
                    <input type="date" wire:model="dueDate" class="rounded border border-border-primary px-2 py-1.5"/>
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.purchase_order') }}</span>
                    <input type="text" inputmode="numeric" wire:model="purchaseOrderId" class="rounded border border-border-primary px-2 py-1.5" placeholder="PO id"/>
                </label>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead>
                        <tr class="bg-sand/40 text-left text-xs uppercase text-charcoal/70">
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_description') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_quantity') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_unit_price') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_discount') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_tax_code') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_account') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_po_line') }}</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $index => $row)
                            <tr wire:key="row-{{ $index }}" class="border-t border-border-primary/60">
                                <td class="px-2 py-1"><input type="text" wire:model="rows.{{ $index }}.description" class="w-full rounded border border-border-primary px-2 py-1"/></td>
                                <td class="px-2 py-1"><input type="text" inputmode="decimal" wire:model="rows.{{ $index }}.quantity" class="w-20 rounded border border-border-primary px-2 py-1"/></td>
                                <td class="px-2 py-1"><input type="text" inputmode="numeric" wire:model="rows.{{ $index }}.unit_price_ht" class="w-28 rounded border border-border-primary px-2 py-1"/></td>
                                <td class="px-2 py-1"><input type="text" inputmode="numeric" wire:model="rows.{{ $index }}.discount_rate_bp" class="w-20 rounded border border-border-primary px-2 py-1"/></td>
                                <td class="px-2 py-1">
                                    <select wire:model="rows.{{ $index }}.tax_code_id" class="w-32 rounded border border-border-primary px-2 py-1">
                                        <option value="">—</option>
                                        @foreach ($taxCodes as $taxCode)
                                            <option value="{{ $taxCode->id }}">{{ $taxCode->code }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-2 py-1">
                                    <select wire:model="rows.{{ $index }}.expense_account_id" class="w-40 rounded border border-border-primary px-2 py-1">
                                        <option value="">—</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-2 py-1"><input type="text" inputmode="numeric" wire:model="rows.{{ $index }}.purchase_order_line_id" class="w-20 rounded border border-border-primary px-2 py-1"/></td>
                                <td class="px-2 py-1">
                                    <button type="button" wire:click="removeRow({{ $index }})" class="text-badge-red hover:underline">{{ __('opes.supplier_invoice_screen.remove_line') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex items-center gap-3">
                <button type="button" wire:click="addRow" class="rounded border border-border-primary px-3 py-1.5 text-sm hover:bg-sand/30">{{ __('opes.supplier_invoice_screen.add_line') }}</button>
                <button type="button" wire:click="save" class="rounded bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">{{ __('opes.supplier_invoice_screen.save') }}</button>
            </div>
        </section>
    @else
        {{-- ── Captured invoice: match + tax panels ─────────────────── --}}
        <section class="rounded border border-border-primary bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-mono text-lg">{{ $invoiceModel->internal_no }}</h2>
                <div class="flex items-center gap-2 text-sm">
                    <span>{{ str_replace('_', ' ', $invoiceModel->status->value) }}</span>
                    @if ($invoiceModel->withholding_unresolved)
                        <x-status-pill status="amber" :label="__('opes.supplier_invoice_screen.withholding_unresolved')"/>
                    @endif
                </div>
            </div>

            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <div><dt class="text-xs text-charcoal/60">{{ __('opes.supplier_invoice_screen.col_supplier_no') }}</dt><dd class="font-mono">{{ $invoiceModel->supplier_invoice_no }}</dd></div>
                <div><dt class="text-xs text-charcoal/60">{{ __('opes.supplier_invoice_screen.subtotal_ht') }}</dt><dd class="font-mono">{{ number_format($invoiceModel->subtotal_ht, 0, ',', ' ') }}</dd></div>
                <div><dt class="text-xs text-charcoal/60">{{ __('opes.supplier_invoice_screen.tax_total') }}</dt><dd class="font-mono">{{ number_format($invoiceModel->tax_total, 0, ',', ' ') }}</dd></div>
                <div><dt class="text-xs text-charcoal/60">{{ __('opes.supplier_invoice_screen.withholding_total') }}</dt><dd class="font-mono">{{ number_format($invoiceModel->withholding_total, 0, ',', ' ') }}</dd></div>
                <div><dt class="text-xs text-charcoal/60">{{ __('opes.supplier_invoice_screen.net_payable') }}</dt><dd class="font-mono font-semibold">{{ number_format($invoiceModel->net_payable, 0, ',', ' ') }}</dd></div>
            </dl>
        </section>

        <section class="rounded border border-border-primary bg-white p-4">
            <h3 class="text-sm font-semibold text-charcoal">{{ __('opes.supplier_invoice_screen.panel_lines') }}</h3>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[64rem] text-sm">
                    <thead>
                        <tr class="bg-sand/40 text-left text-xs uppercase text-charcoal/70">
                            <th class="px-2 py-2">#</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_description') }}</th>
                            <th class="px-2 py-2 text-right">{{ __('opes.supplier_invoice_screen.line_amount_ht') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.line_tax_code') }}</th>
                            <th class="px-2 py-2 text-right">{{ __('opes.supplier_invoice_screen.tax') }}</th>
                            <th class="px-2 py-2 text-right">{{ __('opes.supplier_invoice_screen.deductible') }}</th>
                            <th class="px-2 py-2 text-right">{{ __('opes.supplier_invoice_screen.non_deductible') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.withholding_rule') }}</th>
                            <th class="px-2 py-2 text-right">{{ __('opes.supplier_invoice_screen.withheld') }}</th>
                            <th class="px-2 py-2">{{ __('opes.supplier_invoice_screen.col_match') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoiceLines as $line)
                            <tr wire:key="line-{{ $line->line_no }}" class="border-t border-border-primary/60 {{ $line->match_status === 'exception' ? 'bg-badge-red/5' : '' }}">
                                <td class="px-2 py-1.5">{{ $line->line_no }}</td>
                                <td class="px-2 py-1.5">{{ $line->description }}</td>
                                <td class="px-2 py-1.5 text-right font-mono">{{ number_format((int) $line->amount_ht, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1.5 font-mono">{{ $line->tax_code }}</td>
                                <td class="px-2 py-1.5 text-right font-mono">{{ number_format((int) $line->tax_amount, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1.5 text-right font-mono">{{ number_format((int) $line->deductible_tax_amount, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1.5 text-right font-mono">{{ number_format((int) $line->non_deductible_tax_amount, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1.5 text-xs">{{ $line->withholding_rule_name ?? ($line->withholding_reason ? str_replace('_', ' ', (string) $line->withholding_reason) : '—') }}</td>
                                <td class="px-2 py-1.5 text-right font-mono">{{ number_format((int) $line->withholding_amount, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1.5 text-xs">
                                    @if ($line->match_status === 'exception')
                                        <span class="font-medium text-badge-red">{{ str_replace('_', ' ', (string) $line->match_exception_reason) }}</span>
                                        <span class="block text-charcoal/60">
                                            {{ __('opes.supplier_invoice_screen.price_variance') }}: {{ number_format((int) $line->price_variance, 0, ',', ' ') }}
                                            · {{ __('opes.supplier_invoice_screen.quantity_variance') }}: {{ $line->quantity_variance }}
                                        </span>
                                    @else
                                        {{ str_replace('_', ' ', (string) $line->match_status) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-wrap items-end gap-3 rounded border border-border-primary bg-white p-4">
            @if ($invoiceModel->status->value === 'match_exception')
                <label class="flex min-w-[18rem] flex-col gap-1 text-sm">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.override_reason') }}</span>
                    <input type="text" wire:model="overrideReason" class="rounded border border-border-primary px-2 py-1.5"/>
                </label>
                <button type="button" wire:click="overrideMatch" class="rounded border border-badge-orange px-3 py-1.5 text-sm text-badge-orange hover:bg-badge-orange/10">
                    {{ __('opes.supplier_invoice_screen.override_match') }}
                </button>
            @endif

            @if ($invoiceModel->status->value === 'pending_approval')
                @if ($invoiceModel->match_status->value === 'not_required')
                    <label class="flex min-w-[16rem] flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.unmatched_reason') }}</span>
                        <input type="text" wire:model="unmatchedReason" class="rounded border border-border-primary px-2 py-1.5"/>
                    </label>
                @endif
                @if ($invoiceModel->withholding_unresolved)
                    <label class="flex min-w-[16rem] flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.waive_reason') }}</span>
                        <input type="text" wire:model="waiveReason" class="rounded border border-border-primary px-2 py-1.5"/>
                    </label>
                @endif
                <button type="button" wire:click="approve" class="rounded bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('opes.supplier_invoice_screen.approve') }}
                </button>
            @endif

            @if ($invoiceModel->status->value === 'approved')
                <button type="button" wire:click="post" class="rounded bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('opes.supplier_invoice_screen.post') }}
                </button>
            @endif

            <a href="{{ url('/procurement/invoices') }}" class="text-sm text-primary hover:underline">{{ __('opes.supplier_invoice_screen.back') }}</a>
        </section>
    @endif
</div>
