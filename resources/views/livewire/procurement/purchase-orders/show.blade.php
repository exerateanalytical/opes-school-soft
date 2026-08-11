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

    $card = 'rounded-xl border border-border-primary bg-white shadow-sm';
    $sectionTitle = 'text-sm font-semibold uppercase tracking-wide text-text-secondary';
    $dt = 'text-sm text-text-secondary';
    $dd = 'text-sm font-medium text-text-primary';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-text-muted';

    $pct = static fn (float $done, float $total): int => $total <= 0.0 ? 0 : (int) round(min(100.0, $done / $total * 100));

    // §4.2 lifecycle - only the milestones the row actually carries a
    // timestamp for are rendered; nothing is inferred from the status string.
    $timeline = array_values(array_filter([
        ['key' => 'created', 'at' => $order->created_at, 'who' => $createdByName],
        ['key' => 'approved', 'at' => $order->approved_at, 'who' => $approvedByName],
        ['key' => 'sent', 'at' => $order->sent_at, 'who' => null],
    ], static fn (array $s): bool => $s['at'] !== null));
@endphp

<div class="min-w-0 space-y-5">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="text-xs text-text-muted print:hidden">
        <a href="{{ url('/procurement/orders') }}" class="hover:text-primary">{{ __('opes.procurement_detail.back_to_orders') }}</a>
    </nav>

    <header class="flex flex-wrap items-start justify-between gap-3 print:hidden">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">{{ $order->po_no }}</h1>
            <p class="mt-1 text-sm text-text-secondary">
                <a href="{{ url('/procurement/suppliers/'.$order->supplier_id) }}" class="text-primary hover:underline">{{ $order->supplier_name }}</a>
                <span class="font-mono text-text-muted">({{ $order->supplier_code }})</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', $order->status)"/>
            <button type="button" onclick="window.print()" class="rounded-lg border border-border-primary px-3 py-1.5 text-sm font-medium text-text-primary hover:border-primary/50 hover:text-primary">{{ __('opes.procurement_detail.print_preview') }}</button>
            <button type="button" wire:click="exportPdf" class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ __('opes.procurement_detail.export_pdf') }}</button>
        </div>
    </header>

    {{-- §4.2 invariant 6: a PO is a commitment document, never a posting. --}}
    <p class="rounded-lg border border-border-primary bg-surface-green px-4 py-2 text-sm text-text-secondary print:hidden">
        {{ __('opes.procurement_detail.po_no_posting_note') }}
    </p>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3 print:hidden">
        {{-- Order details --}}
        <section class="{{ $card }} p-5 lg:col-span-2">
            <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_order') }}</h2>
            <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_order_date') }}</dt><dd class="{{ $dd }}">{{ $order->order_date }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_expected') }}</dt><dd class="{{ $dd }}">{{ $order->expected_delivery_date ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.delivery_address') }}</dt><dd class="{{ $dd }} text-right">{{ $order->delivery_address ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.currency') }}</dt><dd class="{{ $dd }} font-mono">{{ $order->currency }}</dd></div>
                <div class="flex justify-between gap-4">
                    <dt class="{{ $dt }}">{{ __('opes.procurement_detail.requisition') }}</dt>
                    <dd class="{{ $dd }} font-mono">{{ $order->requisition_no ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_payable_account') }}</dt><dd class="{{ $dd }} font-mono">{{ $order->payable_account_code ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.fiscal_year') }}</dt><dd class="{{ $dd }}">{{ $order->fiscal_year_code ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.academic_year') }}</dt><dd class="{{ $dd }}">{{ $order->academic_year_name ?? '—' }}</dd></div>
                @if ($order->retention_rate_bp > 0)
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.retention_rate') }}</dt><dd class="{{ $dd }} font-mono">{{ number_format($order->retention_rate_bp / 100, 2) }}%</dd></div>
                    <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.retention_due') }}</dt><dd class="{{ $dd }}">{{ $order->retention_release_due_on ?? '—' }}</dd></div>
                @endif
                @if ($order->closed_reason)
                    <div class="flex justify-between gap-4 sm:col-span-2"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.closed_reason') }}</dt><dd class="{{ $dd }} text-right">{{ $order->closed_reason }}</dd></div>
                @endif
            </dl>

            <h3 class="mt-5 {{ $sectionTitle }}">{{ __('opes.procurement_detail.section_supplier') }}</h3>
            <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_niu') }}</dt><dd class="{{ $dd }} font-mono">{{ $order->supplier_niu ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_phone') }}</dt><dd class="{{ $dd }}">{{ $order->supplier_phone ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.email') }}</dt><dd class="{{ $dd }}">{{ $order->supplier_email ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_payment_terms') }}</dt><dd class="{{ $dd }}">{{ $order->supplier_payment_terms_days }}</dd></div>
            </dl>
        </section>

        {{-- Lifecycle + fulfilment --}}
        <div class="space-y-5">
            <section class="{{ $card }} p-5">
                <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_timeline') }}</h2>
                <ol class="mt-3 space-y-3">
                    @forelse ($timeline as $step)
                        <li class="flex gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-text-primary">{{ __('opes.procurement_detail.timeline_'.$step['key']) }}</p>
                                <p class="text-xs text-text-muted">{{ $step['at'] }}@if($step['who']) · {{ $step['who'] }}@endif</p>
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-text-muted">{{ __('opes.procurement_detail.timeline_empty') }}</li>
                    @endforelse
                </ol>
                <p class="mt-4 text-xs text-text-muted">{{ __('opes.procurement_detail.version') }}: {{ $order->version }}</p>
            </section>

            <section class="{{ $card }} p-5">
                <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_fulfilment') }}</h2>
                <dl class="mt-3 space-y-3">
                    <div>
                        <div class="flex justify-between"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.qty_received') }}</dt><dd class="text-sm font-mono text-text-primary">{{ rtrim(rtrim(number_format($qtyReceived, 3, '.', ''), '0'), '.') }} / {{ rtrim(rtrim(number_format($qtyOrdered, 3, '.', ''), '0'), '.') }}</dd></div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-surface-secondary"><div class="h-1.5 rounded-full bg-primary" style="width: {{ $pct($qtyReceived, $qtyOrdered) }}%"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.qty_invoiced') }}</dt><dd class="text-sm font-mono text-text-primary">{{ rtrim(rtrim(number_format($qtyInvoiced, 3, '.', ''), '0'), '.') }} / {{ rtrim(rtrim(number_format($qtyOrdered, 3, '.', ''), '0'), '.') }}</dd></div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-surface-secondary"><div class="h-1.5 rounded-full bg-primary" style="width: {{ $pct($qtyInvoiced, $qtyOrdered) }}%"></div></div>
                    </div>
                    <div class="flex justify-between border-t border-border-secondary pt-2"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.invoiced_value') }}</dt><dd class="text-sm font-mono font-semibold text-text-primary">{{ Money::of($invoicedTotal)->format(false) }}</dd></div>
                    <div class="flex justify-between"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.uninvoiced_value') }}</dt><dd class="text-sm font-mono text-text-primary">{{ Money::of((int) $order->total_ttc - $invoicedTotal)->format(false) }}</dd></div>
                </dl>
            </section>
        </div>
    </div>

    {{-- ── Goods receipts against this order (§4.3) ───────────────────── --}}
    <section class="{{ $card }} print:hidden">
        <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_receipts') }}</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-border-secondary">
                    <tr class="text-left">
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_receipt_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_received_on') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_delivery_note') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_detail.received_by') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_status') }}</th>
                        <th class="{{ $th }}">{{ __('opes.procurement_screen.col_discrepancy') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receipts as $receipt)
                        <tr class="border-t border-border-secondary">
                            <td class="px-3 py-2 font-mono">{{ $receipt->receipt_no }}</td>
                            <td class="px-3 py-2">{{ $receipt->received_on }}</td>
                            <td class="px-3 py-2">{{ $receipt->delivery_note_ref ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $receipt->received_by_name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $receipt->status) }}</td>
                            <td class="px-3 py-2"><x-status-pill :status="$receipt->has_discrepancy ? 'amber' : 'ok'" :label="$receipt->has_discrepancy ? __('opes.procurement_screen.yes') : __('opes.procurement_screen.no')"/></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.no_receipts_for_order') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Invoices raised against this order (§4.5) ──────────────────── --}}
    <section class="{{ $card }} print:hidden">
        <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_invoices') }}</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-border-secondary">
                    <tr class="text-left">
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_internal_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_supplier_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_invoice_date') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.supplier_invoice_screen.col_total_ttc') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_match') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_invoice_screen.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr class="border-t border-border-secondary">
                            <td class="px-3 py-2 font-mono"><a href="{{ url('/procurement/invoices/'.$invoice->id) }}" class="text-primary hover:underline">{{ $invoice->internal_no }}</a></td>
                            <td class="px-3 py-2 font-mono">{{ $invoice->supplier_invoice_no }}</td>
                            <td class="px-3 py-2">{{ $invoice->invoice_date }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $invoice->total_ttc)->format(false) }}</td>
                            <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $invoice->match_status) }}</td>
                            <td class="px-3 py-2"><x-status-pill :status="$invTone[$invoice->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $invoice->status)"/></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.no_invoices_for_order') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Amendments (§4.2 invariant 5) ──────────────────────────────── --}}
    @if ($amendments->isNotEmpty())
        <section class="{{ $card }} print:hidden">
            <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_amendments') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-border-secondary">
                        <tr class="text-left">
                            <th class="{{ $th }}">#</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.amendment_reason') }}</th>
                            <th class="{{ $th }} text-right">{{ __('opes.procurement_detail.previous_total') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.amended_by') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.amended_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($amendments as $amendment)
                            <tr class="border-t border-border-secondary">
                                <td class="px-3 py-2 font-mono">{{ $amendment->amendment_no }}</td>
                                <td class="px-3 py-2">{{ $amendment->reason }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $amendment->previous_total_ttc)->format(false) }}</td>
                                <td class="px-3 py-2">{{ $amendment->amended_by_name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $amendment->amended_at }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Print-preview document ─────────────────────────────────────── --}}
    <div id="print-area" class="{{ $card }} p-8 print:border-0 print:shadow-none">
        <div class="mb-6 flex items-start justify-between border-b border-border-primary pb-4">
            <div>
                <h2 class="text-lg font-bold text-text-primary">{{ __('opes.procurement_detail.doc_purchase_order') }}</h2>
                <p class="font-mono text-sm text-text-secondary">{{ $order->po_no }}</p>
            </div>
            <div class="text-right text-sm">
                <p class="font-medium text-text-primary">{{ $order->order_date }}</p>
                <p class="text-text-secondary">{{ __('opes.procurement_screen.col_status') }}: {{ str_replace('_', ' ', $order->status) }}</p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-8 text-sm">
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_screen.col_supplier') }}</p>
                <p class="font-medium text-text-primary">{{ $order->supplier_name }}</p>
                <p class="text-text-secondary">{{ $order->supplier_code }}</p>
                <p class="text-text-secondary">{{ __('opes.procurement_screen.col_niu') }} {{ $order->supplier_niu ?? '—' }}</p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.delivery') }}</p>
                <p class="text-text-primary">{{ $order->delivery_address ?? '—' }}</p>
                <p class="text-text-secondary">{{ __('opes.procurement_screen.col_expected') }}: {{ $order->expected_delivery_date ?? '—' }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-border-primary text-left">
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">#</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_screen.po_line_description') }}</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.col_account') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_screen.po_line_quantity') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.col_received') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.col_invoiced') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_screen.po_line_unit_price') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.tax') }}</th>
                        <th class="py-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_payment_screen.col_total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr class="border-b border-border-secondary">
                            <td class="py-2 pr-2">{{ $line->line_no }}</td>
                            <td class="py-2 pr-2">
                                {{ $line->description }}
                                @if ($line->is_capitalised)
                                    <span class="ml-1 rounded bg-gold-100 px-1.5 py-0.5 text-xs font-semibold text-gold-700">{{ __('opes.procurement_detail.capitalised') }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-2 font-mono text-xs text-text-secondary">{{ $line->expense_account_code ?? '—' }}@if($line->tax_code) · {{ $line->tax_code }}@endif</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ $line->quantity }}{{ $line->unit_of_measure ? ' '.$line->unit_of_measure : '' }}</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ $line->qty_received }}</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ $line->qty_invoiced }}</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $line->unit_price_ht)->format(false) }}@if($line->discount_rate_bp > 0)<span class="block text-xs text-text-muted">-{{ number_format($line->discount_rate_bp / 100, 2) }}%</span>@endif</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $line->tax_amount)->format(false) }}</td>
                            <td class="py-2 text-right font-mono">{{ Money::of((int) $line->amount_ttc)->format(false) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <dl class="w-72 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_invoice_screen.subtotal_ht') }}</dt><dd class="font-mono">{{ Money::of((int) $order->subtotal_ht)->format(false) }}</dd></div>
                <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_invoice_screen.tax_total') }}</dt><dd class="font-mono">{{ Money::of((int) $order->tax_total)->format(false) }}</dd></div>
                <div class="flex justify-between border-t border-border-primary pt-1 font-semibold"><dt>{{ __('opes.supplier_payment_screen.col_total') }}</dt><dd class="font-mono">{{ Money::of((int) $order->total_ttc)->format(false) }}</dd></div>
                @if ($order->retention_rate_bp > 0)
                    <div class="flex justify-between text-text-secondary"><dt>{{ __('opes.procurement_detail.retention_rate') }}</dt><dd class="font-mono">{{ number_format($order->retention_rate_bp / 100, 2) }}%</dd></div>
                @endif
            </dl>
        </div>
    </div>
</div>
