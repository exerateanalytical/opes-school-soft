@php
    use App\Support\Money\Money;

    $poTone = [
        'draft' => 'amber', 'pending_approval' => 'amber', 'approved' => 'ok', 'sent' => 'ok',
        'partially_received' => 'ok', 'received' => 'ok', 'partially_invoiced' => 'ok',
        'invoiced' => 'ok', 'closed' => 'amber', 'cancelled' => 'red',
    ];

    $invTone = [
        'draft' => 'amber', 'pending_match' => 'amber', 'match_exception' => 'red',
        'pending_approval' => 'amber', 'approved' => 'ok', 'posted' => 'ok',
        'partially_paid' => 'ok', 'paid' => 'ok', 'cancelled' => 'red', 'disputed' => 'red',
    ];

    $payTone = ['draft' => 'amber', 'approved' => 'ok', 'paid' => 'ok', 'voided' => 'red'];

    // §3.1 - the NIU's status is load-bearing: it changes the withholding
    // rate, so it is never rendered as a bare string.
    $niuTone = [
        'active' => 'ok', 'unknown' => 'amber', 'none_declared' => 'amber',
        'inactive' => 'red', 'not_found' => 'red',
    ];

    $card = 'rounded-xl border border-border-primary bg-white shadow-sm';
    $sectionTitle = 'text-sm font-semibold uppercase tracking-wide text-text-secondary';
    $dt = 'text-sm text-text-secondary';
    $dd = 'text-sm font-medium text-text-primary';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-text-muted';
@endphp

<div class="min-w-0 space-y-5">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="text-xs text-text-muted">
        <a href="{{ url('/procurement/suppliers') }}" class="hover:text-primary">{{ __('opes.procurement_screen.back_to_suppliers') }}</a>
    </nav>

    {{-- ── Header ────────────────────────────────────────────────────── --}}
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">{{ $supplier->name }}</h1>
            <p class="mt-1 font-mono text-sm text-text-secondary">
                {{ $supplier->code }}
                <span class="text-text-muted">·</span>
                {{ __('opes.procurement_screen.col_niu') }} {{ $supplier->niu ?? '—' }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-status-pill :status="$niuTone[$supplier->niu_status->value] ?? 'amber'"
                           :label="__('opes.procurement_detail.niu_status_'.$supplier->niu_status->value)"/>
            @if ($supplier->is_archived)
                <x-status-pill status="red" :label="__('opes.procurement_screen.state_archived')"/>
            @elseif (! $supplier->is_active)
                <x-status-pill status="amber" :label="__('opes.procurement_detail.state_inactive')"/>
            @else
                <x-status-pill status="ok" :label="__('opes.procurement_screen.state_active')"/>
            @endif
        </div>
    </header>

    @if ($supplier->blocked_reason)
        <div class="rounded-lg border border-danger/40 bg-danger-bg px-4 py-3 text-sm text-danger-text">
            <span class="font-semibold">{{ __('opes.procurement_detail.blocked') }}:</span> {{ $supplier->blocked_reason }}
        </div>
    @endif

    {{-- ── Account position (§4.9 supplier statement, at a glance) ────── --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="{{ $card }} p-4">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.kpi_invoiced') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of($invoicedTtc)->format(false) }}</p>
            <p class="mt-0.5 text-xs text-text-muted">{{ trans_choice('opes.procurement_detail.kpi_invoice_count', $invoiceCount, ['count' => $invoiceCount]) }}</p>
        </div>
        <div class="{{ $card }} p-4">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.kpi_paid') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of($paidTotal)->format(false) }}</p>
        </div>
        <div class="{{ $card }} p-4 {{ $outstanding > 0 ? 'border-l-4 border-l-heritage-yellow' : '' }}">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.kpi_outstanding') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of($outstanding)->format(false) }}</p>
            <p class="mt-0.5 text-xs text-text-muted">{{ __('opes.procurement_detail.kpi_withheld') }} {{ Money::of($withheldTotal)->format(false) }}</p>
        </div>
        <div class="{{ $card }} p-4">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.kpi_commitments') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of($openCommitments)->format(false) }}</p>
        </div>
    </div>

    {{-- ── Tabs ──────────────────────────────────────────────────────── --}}
    <div role="tablist" class="flex flex-wrap gap-1 border-b border-border-primary">
        @foreach (\App\Modules\Procurement\Livewire\Suppliers\Show::TABS as $tabKey)
            <button type="button" role="tab" wire:click="selectTab('{{ $tabKey }}')"
                    aria-selected="{{ $tab === $tabKey ? 'true' : 'false' }}"
                    class="rounded-t-lg px-4 py-2 text-sm {{ $tab === $tabKey ? 'border border-b-0 border-border-primary bg-white font-semibold text-primary' : 'text-text-secondary hover:text-text-primary' }}">
                @switch($tabKey)
                    @case('details') {{ __('opes.procurement_screen.tab_details') }} @break
                    @case('orders') {{ __('opes.procurement_screen.tab_orders') }} @break
                    @case('receipts') {{ __('opes.procurement_screen.tab_receipts') }} @break
                    @case('invoices') {{ __('opes.procurement_detail.tab_invoices') }} @break
                    @default {{ __('opes.procurement_detail.tab_payments') }}
                @endswitch
            </button>
        @endforeach
    </div>

    @if ($tab === 'details')
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

            {{-- Fiscal identity (§3.1) --}}
            <section class="{{ $card }} p-5">
                <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_fiscal_identity') }}</h2>
                <dl class="mt-3 space-y-2">
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_type') }}</dt><dd class="{{ $dd }}">{{ __('opes.procurement_detail.supplier_type_'.$supplier->supplier_type->value) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.legal_form') }}</dt><dd class="{{ $dd }}">{{ $supplier->legal_form ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_niu') }}</dt><dd class="{{ $dd }} font-mono">{{ $supplier->niu ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.niu_verified') }}</dt>
                        <dd class="{{ $dd }}">
                            @if ($supplier->is_niu_verified)
                                {{ $niuVerifiedByName ?? '—' }} @if($supplier->niu_verified_at)<span class="text-text-muted">({{ $supplier->niu_verified_at }})</span>@endif
                            @else
                                {{ __('opes.procurement_screen.no') }}
                            @endif
                        </dd>
                    </div>
                    @if ($supplier->niu_verification_evidence)
                        <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.niu_evidence') }}</dt><dd class="{{ $dd }}">{{ $supplier->niu_verification_evidence }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.rccm') }}</dt><dd class="{{ $dd }} font-mono">{{ $supplier->rccm_number ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_regime') }}</dt><dd class="{{ $dd }}">{{ $supplier->regime_fiscal?->value ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.tax_centre') }}</dt><dd class="{{ $dd }}">{{ $supplier->tax_centre_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.contributor_card') }}</dt><dd class="{{ $dd }}">{{ $supplier->has_contributor_card ? __('opes.procurement_screen.yes') : __('opes.procurement_screen.no') }}</dd></div>
                </dl>
            </section>

            {{-- Contact --}}
            <section class="{{ $card }} p-5">
                <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_contact') }}</h2>
                <dl class="mt-3 space-y-2">
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.contact_name') }}</dt><dd class="{{ $dd }}">{{ $supplier->contact_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_phone') }}</dt><dd class="{{ $dd }}">{{ $supplier->phone ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.phone_alt') }}</dt><dd class="{{ $dd }}">{{ $supplier->phone_alt ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.email') }}</dt><dd class="{{ $dd }}">{{ $supplier->email ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.website') }}</dt><dd class="{{ $dd }}">{{ $supplier->website ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4">
                        <dt class="{{ $dt }}">{{ __('opes.procurement_detail.address') }}</dt>
                        <dd class="{{ $dd }} text-right">
                            {{ collect([$supplier->address_line1, $supplier->address_line2, $supplier->city, $supplier->region, $supplier->country])->filter()->implode(', ') ?: '—' }}
                        </dd>
                    </div>
                </dl>
            </section>

            {{-- Accounting defaults (§3.3) --}}
            <section class="{{ $card }} p-5">
                <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_accounting') }}</h2>
                <dl class="mt-3 space-y-2">
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_payable_account') }}</dt><dd class="{{ $dd }} font-mono">{{ $payableAccount }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.default_expense_account') }}</dt><dd class="{{ $dd }} font-mono">{{ $expenseAccount ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.default_tax_code') }}</dt><dd class="{{ $dd }} font-mono">{{ $defaultTaxCode ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_category') }}</dt><dd class="{{ $dd }}">{{ $categoryName ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_payment_terms') }}</dt><dd class="{{ $dd }}">{{ $supplier->payment_terms_days }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.currency') }}</dt><dd class="{{ $dd }} font-mono">{{ $supplier->currency }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-text-muted">{{ __('opes.procurement_detail.payable_account_note') }}</p>
            </section>

            {{-- Withholding + banking --}}
            <section class="{{ $card }} p-5">
                <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_withholding_banking') }}</h2>
                <dl class="mt-3 space-y-2">
                    <div class="flex justify-between gap-4">
                        <dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_withholding_exempt') }}</dt>
                        <dd class="{{ $dd }} text-right">
                            {{ $supplier->is_withholding_exempt
                                ? ($supplier->withholding_exemption_ref.($supplier->withholding_exemption_expires_on !== null ? ' → '.$supplier->withholding_exemption_expires_on : ''))
                                : __('opes.procurement_screen.no') }}
                        </dd>
                    </div>
                    {{-- 00-core 9.5: encrypted identifiers are shown MASKED. --}}
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.bank_name') }}</dt><dd class="{{ $dd }}">{{ $supplier->bank_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_bank') }}</dt><dd class="{{ $dd }} font-mono">{{ $maskedRib ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.momo_operator') }}</dt><dd class="{{ $dd }}">{{ $supplier->mobile_money_operator ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_momo') }}</dt><dd class="{{ $dd }} font-mono">{{ $maskedMomo ?? '—' }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-text-muted">{{ __('opes.procurement_detail.masked_note') }}</p>
            </section>

            @if ($supplier->notes)
                <section class="{{ $card }} p-5 lg:col-span-2">
                    <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_notes') }}</h2>
                    <p class="mt-3 whitespace-pre-line text-sm text-text-primary">{{ $supplier->notes }}</p>
                </section>
            @endif

            {{-- Audit (00-core §14) --}}
            <section class="{{ $card }} p-5 lg:col-span-2">
                <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_audit') }}</h2>
                <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.created_by') }}</dt><dd class="{{ $dd }}">{{ $createdByName ?? '—' }} <span class="text-text-muted">({{ $supplier->created_at }})</span></dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.updated_by') }}</dt><dd class="{{ $dd }}">{{ $updatedByName ?? '—' }} <span class="text-text-muted">({{ $supplier->updated_at }})</span></dd></div>
                </dl>
            </section>
        </div>

    @elseif ($tab === 'orders')
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-border-primary">
                    <tr class="text-left">
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_po_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_order_date') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.procurement_screen.col_total_ttc') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr class="border-t border-border-secondary">
                            <td class="px-3 py-2 font-mono"><a href="{{ url('/procurement/orders/'.$order->id) }}" class="text-primary hover:underline">{{ $order->po_no }}</a></td>
                            <td class="px-3 py-2">{{ $order->order_date }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $order->total_ttc)->format(false) }}</td>
                            <td class="px-3 py-2"><x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $order->status)"/></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_screen.orders_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}

    @elseif ($tab === 'receipts')
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-border-primary">
                    <tr class="text-left">
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_receipt_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_received_on') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_po_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_discrepancy') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receipts as $receipt)
                        <tr class="border-t border-border-secondary">
                            <td class="px-3 py-2 font-mono">{{ $receipt->receipt_no }}</td>
                            <td class="px-3 py-2">{{ $receipt->received_on }}</td>
                            <td class="px-3 py-2 font-mono">{{ $receipt->po_no ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <x-status-pill :status="$receipt->has_discrepancy ? 'amber' : 'ok'"
                                               :label="$receipt->has_discrepancy ? __('opes.procurement_screen.yes') : __('opes.procurement_screen.no')"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_screen.receipts_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $receipts->links() }}

    @elseif ($tab === 'invoices')
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-border-primary">
                    <tr class="text-left">
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_internal_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_supplier_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_invoice_date') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_due_date') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.supplier_invoice_screen.col_total_ttc') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.supplier_invoice_screen.col_net_payable') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr class="border-t border-border-secondary">
                            <td class="px-3 py-2 font-mono"><a href="{{ url('/procurement/invoices/'.$invoice->id) }}" class="text-primary hover:underline">{{ $invoice->internal_no }}</a></td>
                            <td class="px-3 py-2 font-mono">{{ $invoice->supplier_invoice_no }}</td>
                            <td class="px-3 py-2">{{ $invoice->invoice_date }}</td>
                            <td class="px-3 py-2">{{ $invoice->due_date }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $invoice->total_ttc)->format(false) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $invoice->net_payable)->format(false) }}</td>
                            <td class="px-3 py-2"><x-status-pill :status="$invTone[$invoice->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $invoice->status)"/></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.invoices_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $invoices->links() }}

    @else
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-border-primary">
                    <tr class="text-left">
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_payment_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_date') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_method') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.reference') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.supplier_payment_screen.col_gross') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.supplier_payment_screen.col_withheld') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.supplier_payment_screen.col_net') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr class="border-t border-border-secondary">
                            <td class="px-3 py-2 font-mono"><a href="{{ url('/procurement/payments/'.$payment->id) }}" class="text-primary hover:underline">{{ $payment->payment_no }}</a></td>
                            <td class="px-3 py-2">{{ $payment->payment_date }}</td>
                            <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $payment->payment_method) }}</td>
                            <td class="px-3 py-2 font-mono">{{ $payment->reference ?? '—' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $payment->gross_amount)->format(false) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $payment->withholding_amount)->format(false) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $payment->net_amount)->format(false) }}</td>
                            <td class="px-3 py-2"><x-status-pill :status="$payTone[$payment->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $payment->status)"/></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.payments_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $payments->links() }}
    @endif
</div>
