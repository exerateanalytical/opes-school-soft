@php
    use App\Support\Money\Money;

    $invTone = [
        'draft' => 'amber', 'pending_match' => 'amber', 'match_exception' => 'red',
        'pending_approval' => 'amber', 'approved' => 'ok', 'posted' => 'ok',
        'partially_paid' => 'ok', 'paid' => 'ok', 'cancelled' => 'red', 'disputed' => 'red',
    ];

    $matchTone = ['not_required' => 'amber', 'matched' => 'ok', 'exception' => 'red', 'overridden' => 'amber'];

    $card = 'rounded-xl border border-border-primary bg-white shadow-sm';
    $sectionTitle = 'text-sm font-semibold uppercase tracking-wide text-text-secondary';
    $dt = 'text-sm text-text-secondary';
    $dd = 'text-sm font-medium text-text-primary';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-text-muted';

    // §4.5 lifecycle - milestones the row actually timestamps, nothing inferred.
    $timeline = array_values(array_filter([
        ['key' => 'captured', 'at' => $invoice->created_at, 'who' => $createdByName],
        ['key' => 'received', 'at' => $invoice->received_date, 'who' => null],
        ['key' => 'match_overridden', 'at' => $invoice->match_override_at, 'who' => $matchOverrideByName],
        ['key' => 'approved', 'at' => $invoice->approved_at, 'who' => $approvedByName],
        ['key' => 'posted', 'at' => $invoice->posted_at, 'who' => null],
        ['key' => 'cancelled', 'at' => $invoice->cancelled_at, 'who' => $cancelledByName],
    ], static fn (array $s): bool => $s['at'] !== null));
@endphp

<div class="min-w-0 space-y-5">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="text-xs text-text-muted print:hidden">
        <a href="{{ url('/procurement/invoices') }}" class="hover:text-primary">{{ __('opes.supplier_invoice_screen.back') }}</a>
    </nav>

    <header class="flex flex-wrap items-start justify-between gap-3 print:hidden">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">{{ $invoice->internal_no }}</h1>
            <p class="mt-1 text-sm text-text-secondary">
                <a href="{{ url('/procurement/suppliers/'.$invoice->supplier_id) }}" class="text-primary hover:underline">{{ $invoice->supplier_name }}</a>
                <span class="font-mono text-text-muted">({{ $invoice->supplier_code }})</span>
                <span class="text-text-muted">·</span>
                {{ __('opes.supplier_invoice_screen.col_supplier_no') }} <span class="font-mono">{{ $invoice->supplier_invoice_no }}</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-status-pill :status="$invTone[$invoice->status] ?? 'amber'" :label="str_replace('_', ' ', $invoice->status)"/>
            <x-status-pill :status="$matchTone[$invoice->match_status] ?? 'amber'" :label="str_replace('_', ' ', $invoice->match_status)"/>
            <button type="button" onclick="window.print()" class="rounded-lg border border-border-primary px-3 py-1.5 text-sm font-medium text-text-primary hover:border-primary/50 hover:text-primary">{{ __('opes.procurement_detail.print_preview') }}</button>
            <button type="button" wire:click="exportPdf" class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ __('opes.procurement_detail.export_pdf') }}</button>
        </div>
    </header>

    {{-- ── Control exceptions, first, because they block approval ─────── --}}
    <div class="space-y-2 print:hidden">
        @if ($invoice->withholding_unresolved)
            <div class="rounded-lg border border-danger/40 bg-danger-bg px-4 py-3 text-sm text-danger">{{ __('opes.supplier_invoice_screen.withholding_unresolved') }}</div>
        @endif
        @if ($invoice->match_override_reason)
            <div class="rounded-lg border border-warning/40 bg-warning-bg px-4 py-3 text-sm text-text-primary">
                <span class="font-semibold">{{ __('opes.supplier_invoice_screen.override_reason') }}:</span> {{ $invoice->match_override_reason }}
                <span class="text-text-muted">— {{ $matchOverrideByName ?? '—' }} ({{ $invoice->match_override_at }})</span>
            </div>
        @endif
        @if ($invoice->unmatched_reason)
            <div class="rounded-lg border border-warning/40 bg-warning-bg px-4 py-3 text-sm text-text-primary">
                <span class="font-semibold">{{ __('opes.supplier_invoice_screen.unmatched_reason') }}:</span> {{ $invoice->unmatched_reason }}
            </div>
        @endif
        @if ($invoice->withholding_waived_reason)
            <div class="rounded-lg border border-warning/40 bg-warning-bg px-4 py-3 text-sm text-text-primary">
                <span class="font-semibold">{{ __('opes.supplier_invoice_screen.waive_reason') }}:</span> {{ $invoice->withholding_waived_reason }}
                <span class="text-text-muted">— {{ $withholdingWaivedByName ?? '—' }} ({{ $invoice->withholding_waived_at }})</span>
            </div>
        @endif
        @if ($invoice->cancellation_reason)
            <div class="rounded-lg border border-danger/40 bg-danger-bg px-4 py-3 text-sm text-danger">
                <span class="font-semibold">{{ __('opes.procurement_detail.cancelled') }}:</span> {{ $invoice->cancellation_reason }}
                <span class="opacity-70">— {{ $cancelledByName ?? '—' }} ({{ $invoice->cancelled_at }})</span>
            </div>
        @endif
        @if ($invoice->is_migration)
            <div class="rounded-lg border border-border-primary bg-surface-secondary px-4 py-3 text-sm text-text-secondary">{{ __('opes.procurement_detail.is_migration') }}</div>
        @endif
    </div>

    {{-- ── Settlement position ────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
        <div class="{{ $card }} p-4">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.col_total_ttc') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of((int) $invoice->total_ttc)->format(false) }}</p>
        </div>
        <div class="{{ $card }} p-4">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.net_payable') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of((int) $invoice->net_payable)->format(false) }}</p>
        </div>
        <div class="{{ $card }} p-4">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.kpi_paid') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of($paidTotal)->format(false) }}</p>
        </div>
        <div class="{{ $card }} p-4 {{ $outstanding > 0 ? 'border-l-4 border-l-heritage-yellow' : '' }}">
            <p class="text-xs uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.kpi_outstanding') }}</p>
            <p class="mt-1 font-mono text-lg font-semibold text-text-primary">{{ Money::of($outstanding)->format(false) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3 print:hidden">
        <section class="{{ $card }} p-5 lg:col-span-2">
            <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_invoice') }}</h2>
            <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_invoice_screen.invoice_date') }}</dt><dd class="{{ $dd }}">{{ $invoice->invoice_date }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.received_date') }}</dt><dd class="{{ $dd }}">{{ $invoice->received_date }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.value_date') }}</dt><dd class="{{ $dd }}">{{ $invoice->value_date }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_invoice_screen.due_date') }}</dt><dd class="{{ $dd }}">{{ $invoice->due_date }}</dd></div>
                <div class="flex justify-between gap-4">
                    <dt class="{{ $dt }}">{{ __('opes.supplier_invoice_screen.purchase_order') }}</dt>
                    <dd class="{{ $dd }}">
                        @if ($invoice->purchase_order_id)
                            <a href="{{ url('/procurement/orders/'.$invoice->purchase_order_id) }}" class="font-mono text-primary hover:underline">{{ $invoice->po_no }}</a>
                        @else — @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.currency') }}</dt><dd class="{{ $dd }} font-mono">{{ $invoice->currency }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.detail_payable_account') }}</dt><dd class="{{ $dd }} font-mono">{{ $invoice->payable_account_code ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.fiscal_year') }}</dt><dd class="{{ $dd }}">{{ $invoice->fiscal_year_code ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.accounting_period') }}</dt><dd class="{{ $dd }}">{{ $invoice->period_month ?? '—' }} @if($invoice->period_status)<span class="text-text-muted">({{ $invoice->period_status }})</span>@endif</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_niu') }}</dt><dd class="{{ $dd }} font-mono">{{ $invoice->supplier_niu ?? '—' }}</dd></div>
            </dl>

            {{-- §5.4 prorata split, invoice level --}}
            <h3 class="mt-5 {{ $sectionTitle }}">{{ __('opes.procurement_detail.section_tva') }}</h3>
            <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_invoice_screen.tax_total') }}</dt><dd class="{{ $dd }} font-mono">{{ Money::of((int) $invoice->tax_total)->format(false) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_invoice_screen.deductible') }}</dt><dd class="{{ $dd }} font-mono">{{ Money::of($deductibleTotal)->format(false) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_invoice_screen.non_deductible') }}</dt><dd class="{{ $dd }} font-mono">{{ Money::of($nonDeductibleTotal)->format(false) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_invoice_screen.withholding_total') }}</dt><dd class="{{ $dd }} font-mono">{{ Money::of((int) $invoice->withholding_total)->format(false) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-text-muted">{{ __('opes.procurement_detail.prorata_note') }}</p>
        </section>

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
                <p class="mt-4 text-xs text-text-muted">{{ __('opes.procurement_detail.version') }}: {{ $invoice->version }}</p>
            </section>

            @if ($retention !== null)
                <section class="{{ $card }} p-5">
                    <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_retention') }}</h2>
                    <dl class="mt-3 space-y-2">
                        <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.retention_amount') }}</dt><dd class="{{ $dd }} font-mono">{{ Money::of((int) $retention->amount)->format(false) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.retention_account') }}</dt><dd class="{{ $dd }} font-mono">{{ $retention->account_code ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_status') }}</dt><dd class="{{ $dd }}">{{ str_replace('_', ' ', (string) $retention->status) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.retention_due') }}</dt><dd class="{{ $dd }}">{{ $retention->release_due_on ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.retention_released_at') }}</dt><dd class="{{ $dd }}">{{ $retention->released_at ?? '—' }}</dd></div>
                    </dl>
                </section>
            @endif
        </div>
    </div>

    {{-- ── Ledger postings (§4.6) ─────────────────────────────────────── --}}
    <section class="{{ $card }} print:hidden">
        <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_posting') }}</h2>
        @if ($entries->isEmpty())
            <p class="px-5 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.not_posted') }}</p>
        @else
            @foreach ($entries as $entry)
                <div class="border-b border-border-secondary last:border-b-0">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 pt-4">
                        <p class="text-sm font-semibold text-text-primary">
                            <span class="font-mono">{{ $entry->journal_code ?? '' }} {{ $entry->piece_no }}</span>
                            <span class="ml-2 font-normal text-text-secondary">{{ $entry->label }}</span>
                        </p>
                        <p class="text-xs text-text-muted">{{ $entry->date }} · {{ $entry->status }}</p>
                    </div>
                    <div class="overflow-x-auto px-2 pb-3">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left">
                                    <th class="{{ $th }}">{{ __('opes.procurement_detail.col_account') }}</th>
                                    <th class="{{ $th }}">{{ __('opes.procurement_detail.col_label') }}</th>
                                    <th class="{{ $th }} text-right">{{ __('opes.procurement_detail.col_debit') }}</th>
                                    <th class="{{ $th }} text-right">{{ __('opes.procurement_detail.col_credit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (($journalLines[$entry->id] ?? collect()) as $jl)
                                    <tr class="border-t border-border-secondary">
                                        <td class="px-3 py-2 font-mono">{{ $jl->account_code }} <span class="text-text-muted">{{ $jl->account_name }}</span></td>
                                        <td class="px-3 py-2">{{ $jl->label }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ (int) $jl->debit === 0 ? '—' : Money::of((int) $jl->debit)->format(false) }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ (int) $jl->credit === 0 ? '—' : Money::of((int) $jl->credit)->format(false) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </section>

    {{-- ── Goods receipts matched (§4.3/4.4) ──────────────────────────── --}}
    @if ($receipts->isNotEmpty())
        <section class="{{ $card }}">
            <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_receipts') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-border-secondary">
                        <tr class="text-left">
                            <th class="{{ $th }}">{{ __('opes.procurement_screen.col_receipt_no') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_screen.col_received_on') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_screen.col_status') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_screen.col_discrepancy') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $receipt)
                            <tr class="border-t border-border-secondary">
                                <td class="px-3 py-2 font-mono">{{ $receipt->receipt_no }}</td>
                                <td class="px-3 py-2">{{ $receipt->received_on }}</td>
                                <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $receipt->status) }}</td>
                                <td class="px-3 py-2"><x-status-pill :status="$receipt->has_discrepancy ? 'amber' : 'ok'" :label="$receipt->has_discrepancy ? __('opes.procurement_screen.yes') : __('opes.procurement_screen.no')"/></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Payments applied ───────────────────────────────────────────── --}}
    <section class="{{ $card }} print:hidden">
        <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_payments') }}</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-border-secondary">
                    <tr class="text-left">
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_payment_no') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_date') }}</th>
                        <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_status') }}</th>
                        <th class="{{ $th }} text-right">{{ __('opes.procurement_detail.col_allocated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr class="border-t border-border-secondary">
                            <td class="px-3 py-2"><a href="{{ url('/procurement/payments/'.$payment->id) }}" class="font-mono text-primary hover:underline">{{ $payment->payment_no }}</a></td>
                            <td class="px-3 py-2">{{ $payment->payment_date }}</td>
                            <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $payment->payment_status) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $payment->amount)->format(false) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.no_payments_for_invoice') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Credit notes (§4.8) ────────────────────────────────────────── --}}
    @if ($creditNotes->isNotEmpty())
        <section class="{{ $card }} print:hidden">
            <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_credit_notes') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-border-secondary">
                        <tr class="text-left">
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.col_credit_note') }}</th>
                            <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_date') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.col_reason_type') }}</th>
                            <th class="{{ $th }} text-right">{{ __('opes.supplier_invoice_screen.col_total_ttc') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_screen.col_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($creditNotes as $note)
                            <tr class="border-t border-border-secondary">
                                <td class="px-3 py-2 font-mono">{{ $note->credit_note_no }}</td>
                                <td class="px-3 py-2">{{ $note->credit_note_date }}</td>
                                <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $note->reason_type) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $note->total_ttc)->format(false) }}</td>
                                <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $note->status) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Withholding attestations (§6.6) ────────────────────────────── --}}
    @if ($attestations->isNotEmpty())
        <section class="{{ $card }} print:hidden">
            <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_attestations') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-border-secondary">
                        <tr class="text-left">
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.col_attestation') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.col_period') }}</th>
                            <th class="{{ $th }} text-right">{{ __('opes.procurement_detail.col_base') }}</th>
                            <th class="{{ $th }} text-right">{{ __('opes.procurement_detail.col_rate') }}</th>
                            <th class="{{ $th }} text-right">{{ __('opes.supplier_payment_screen.col_withheld') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_screen.col_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attestations as $attestation)
                            <tr class="border-t border-border-secondary">
                                <td class="px-3 py-2 font-mono">{{ $attestation->attestation_no }}</td>
                                <td class="px-3 py-2">{{ str_pad((string) $attestation->period_month, 2, '0', STR_PAD_LEFT) }}/{{ $attestation->period_year }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $attestation->base_amount)->format(false) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($attestation->rate_bp_applied / 100, 2) }}%</td>
                                <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $attestation->withheld_amount)->format(false) }}</td>
                                <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $attestation->status) }}</td>
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
                <h2 class="text-lg font-bold text-text-primary">{{ __('opes.procurement_detail.doc_supplier_invoice') }}</h2>
                <p class="font-mono text-sm text-text-secondary">{{ $invoice->internal_no }}</p>
            </div>
            <div class="text-right text-sm">
                <p class="font-medium text-text-primary">{{ $invoice->invoice_date }}</p>
                <p class="text-text-secondary">{{ __('opes.supplier_invoice_screen.due_date') }} {{ $invoice->due_date }}</p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-8 text-sm">
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_screen.col_supplier') }}</p>
                <p class="font-medium text-text-primary">{{ $invoice->supplier_name }}</p>
                <p class="text-text-secondary">{{ $invoice->supplier_code }}</p>
                <p class="text-text-secondary">{{ __('opes.procurement_screen.col_niu') }} {{ $invoice->supplier_niu ?? '—' }}</p>
                <p class="text-text-secondary">{{ __('opes.supplier_invoice_screen.col_supplier_no') }} {{ $invoice->supplier_invoice_no }}</p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.purchase_order') }}</p>
                <p class="text-text-primary">{{ $invoice->po_no ?? '—' }}</p>
                <p class="text-text-secondary">{{ __('opes.procurement_screen.detail_payable_account') }}: {{ $invoice->payable_account_code ?? '—' }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-border-primary text-left">
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">#</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.line_description') }}</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.col_account') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.line_quantity') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.line_unit_price') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.tax') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.withheld') }}</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.col_match') }}</th>
                        <th class="py-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_payment_screen.col_total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr class="border-b border-border-secondary align-top">
                            <td class="py-2 pr-2">{{ $line->line_no }}</td>
                            <td class="py-2 pr-2">
                                {{ $line->description }}
                                @if ($line->is_capitalised)
                                    <span class="ml-1 rounded bg-gold-100 px-1.5 py-0.5 text-xs font-semibold text-gold-700">{{ __('opes.procurement_detail.capitalised') }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-2 font-mono text-xs text-text-secondary">{{ $line->expense_account_code ?? '—' }}@if($line->tax_code) · {{ $line->tax_code }}@endif</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ $line->quantity }}{{ $line->unit_of_measure ? ' '.$line->unit_of_measure : '' }}</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $line->unit_price_ht)->format(false) }}</td>
                            <td class="py-2 pr-2 text-right font-mono">
                                {{ Money::of((int) $line->tax_amount)->format(false) }}
                                <span class="block text-xs text-text-muted">{{ number_format($line->tax_rate_bp_applied / 100, 2) }}%</span>
                                <span class="block text-xs text-text-muted">{{ __('opes.supplier_invoice_screen.deductible') }} {{ Money::of((int) $line->deductible_tax_amount)->format(false) }}</span>
                                <span class="block text-xs text-text-muted">{{ __('opes.supplier_invoice_screen.non_deductible') }} {{ Money::of((int) $line->non_deductible_tax_amount)->format(false) }}</span>
                            </td>
                            <td class="py-2 pr-2 text-right font-mono">
                                {{ Money::of((int) $line->withholding_amount)->format(false) }}
                                @if ($line->withholding_rate_bp_applied)
                                    <span class="block text-xs text-text-muted">{{ number_format($line->withholding_rate_bp_applied / 100, 2) }}%</span>
                                @endif
                                @if ($line->withholding_reason)
                                    <span class="block text-xs text-text-muted">{{ $line->withholding_reason }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-2 text-xs">
                                {{ str_replace('_', ' ', (string) $line->match_status) }}
                                @if ($line->match_exception_reason)
                                    <span class="block text-danger">{{ str_replace('_', ' ', (string) $line->match_exception_reason) }}</span>
                                @endif
                                @if ((int) $line->price_variance !== 0)
                                    <span class="block text-text-muted">{{ __('opes.supplier_invoice_screen.price_variance') }} {{ Money::of((int) $line->price_variance)->format(false) }}</span>
                                @endif
                                @if ((float) $line->quantity_variance != 0.0)
                                    <span class="block text-text-muted">{{ __('opes.supplier_invoice_screen.quantity_variance') }} {{ $line->quantity_variance }}</span>
                                @endif
                            </td>
                            <td class="py-2 text-right font-mono">{{ Money::of((int) $line->amount_ht + (int) $line->tax_amount)->format(false) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <dl class="w-72 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_invoice_screen.subtotal_ht') }}</dt><dd class="font-mono">{{ Money::of((int) $invoice->subtotal_ht)->format(false) }}</dd></div>
                @if ($invoice->discount_total > 0)
                    <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_invoice_screen.line_discount') }}</dt><dd class="font-mono">-{{ Money::of((int) $invoice->discount_total)->format(false) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_invoice_screen.tax_total') }}</dt><dd class="font-mono">{{ Money::of((int) $invoice->tax_total)->format(false) }}</dd></div>
                <div class="flex justify-between border-t border-border-primary pt-1 font-semibold"><dt>{{ __('opes.supplier_invoice_screen.col_total_ttc') }}</dt><dd class="font-mono">{{ Money::of((int) $invoice->total_ttc)->format(false) }}</dd></div>
                <div class="flex justify-between text-text-secondary"><dt>{{ __('opes.supplier_invoice_screen.withholding_total') }}</dt><dd class="font-mono">-{{ Money::of((int) $invoice->withholding_total)->format(false) }}</dd></div>
                <div class="flex justify-between border-t border-border-primary pt-1 font-semibold"><dt>{{ __('opes.supplier_invoice_screen.net_payable') }}</dt><dd class="font-mono">{{ Money::of((int) $invoice->net_payable)->format(false) }}</dd></div>
                @if ($invoice->retention_amount > 0)
                    <div class="flex justify-between text-text-secondary"><dt>{{ __('opes.procurement_detail.retention_amount') }}</dt><dd class="font-mono">{{ Money::of((int) $invoice->retention_amount)->format(false) }}</dd></div>
                @endif
            </dl>
        </div>
    </div>
</div>
