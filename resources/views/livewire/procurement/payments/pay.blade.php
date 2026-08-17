@php
    /** Supplier payment workflow (03-tax-procurement §10): select supplier →
        open invoices → allocate → method → withholding preview → record.
        The §6.4 preview names what the state takes before a franc moves. */
@endphp

<div class="mx-auto max-w-6xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60">
        {{ __('opes.nav.dashboard') }} / <a href="{{ url('/procurement/payments') }}" class="text-primary hover:underline">{{ __('opes.supplier_payment_screen.title') }}</a> / {{ __('opes.supplier_payment_screen.pay_title') }}
    </nav>

    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.supplier_payment_screen.pay_title') }}</h1>

    @error('payment')
        <div class="rounded border border-badge-red/40 bg-badge-red/10 px-3 py-2 text-sm text-badge-red" role="alert">{{ $message }}</div>
    @enderror

    @if ($recordedAs !== null)
        <div class="rounded border border-badge-blue/40 bg-badge-blue/10 px-3 py-2 text-sm text-charcoal" role="status">
            {{ __('opes.supplier_payment_screen.recorded_as') }} <span class="font-mono">{{ $recordedAs }}</span>
        </div>
    @endif

    <section class="rounded border border-border-primary bg-white p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.supplier') }}</span>
                <select wire:model.live="supplierId" class="rounded border border-border-primary px-2 py-1.5">
                    <option value="">—</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ $supplier->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.payment_date') }}</span>
                <input type="date" wire:model.live="paymentDate" class="rounded border border-border-primary px-2 py-1.5"/>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.method') }}</span>
                <select wire:model="paymentMethod" class="rounded border border-border-primary px-2 py-1.5">
                    @foreach ($paymentMethods as $method)
                        <option value="{{ $method }}">{{ str_replace('_', ' ', $method) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.treasury_account') }}</span>
                <select wire:model="treasuryAccountId" class="rounded border border-border-primary px-2 py-1.5">
                    <option value="">—</option>
                    @foreach ($treasuryAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name_fr }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.reference') }}</span>
                <input type="text" wire:model="reference" class="rounded border border-border-primary px-2 py-1.5"/>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.fee_amount') }}</span>
                <input type="number" min="0" wire:model="feeAmount" class="rounded border border-border-primary px-2 py-1.5"/>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.fee_bearer') }}</span>
                <select wire:model="feeBearer" class="rounded border border-border-primary px-2 py-1.5">
                    <option value="school">{{ __('opes.supplier_payment_screen.fee_bearer_school') }}</option>
                    <option value="supplier">{{ __('opes.supplier_payment_screen.fee_bearer_supplier') }}</option>
                </select>
            </label>
        </div>
    </section>

    <section class="rounded border border-border-primary bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold text-charcoal">{{ __('opes.supplier_payment_screen.open_invoices') }}</h2>

        @if ($openInvoices === [])
            <p class="text-sm text-charcoal/60">{{ __('opes.supplier_payment_screen.no_open_invoices') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-charcoal/70">
                            <th class="px-2 py-1 text-left">{{ __('opes.supplier_payment_screen.col_invoice') }}</th>
                            <th class="px-2 py-1 text-left">{{ __('opes.supplier_payment_screen.col_due_date') }}</th>
                            <th class="px-2 py-1 text-right">{{ __('opes.supplier_payment_screen.col_total') }}</th>
                            <th class="px-2 py-1 text-right">{{ __('opes.supplier_payment_screen.col_outstanding') }}</th>
                            <th class="px-2 py-1 text-right">{{ __('opes.supplier_payment_screen.col_withholding_preview') }}</th>
                            <th class="px-2 py-1 text-right">{{ __('opes.supplier_payment_screen.col_allocate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($openInvoices as $invoice)
                            <tr wire:key="open-invoice-{{ $invoice->id }}" class="border-t border-border-primary/60">
                                <td class="px-2 py-1 font-mono">{{ $invoice->internal_no }} <span class="text-charcoal/50">({{ $invoice->supplier_invoice_no }})</span></td>
                                <td class="px-2 py-1">{{ $invoice->due_date }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($invoice->total_ttc, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($invoice->outstanding, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($invoice->withholding_preview, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right">
                                    <input type="number" min="0" max="{{ $invoice->outstanding }}"
                                           wire:model="allocations.{{ $invoice->id }}"
                                           class="w-32 rounded border border-border-primary px-2 py-1 text-right font-mono"/>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex justify-end">
                <button type="button" wire:click="record" class="rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('opes.supplier_payment_screen.record') }}
                </button>
            </div>
        @endif
    </section>
</div>
