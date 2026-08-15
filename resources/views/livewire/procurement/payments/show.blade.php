@php
    use App\Support\Money\Money;

    $payTone = ['draft' => 'amber', 'approved' => 'ok', 'paid' => 'ok', 'voided' => 'red'];
    $clearingTone = ['not_applicable' => 'ok', 'pending' => 'amber', 'cleared' => 'ok', 'bounced' => 'red'];

    $card = 'rounded-xl border border-border-primary bg-white shadow-sm';
    $sectionTitle = 'text-sm font-semibold uppercase tracking-wide text-text-secondary';
    $dt = 'text-sm text-text-secondary';
    $dd = 'text-sm font-medium text-text-primary';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-text-muted';

    // §4.7 lifecycle - recorded / approved / paid / voided are three distinct
    // hands by design (segregation of duties), so each is named separately.
    $timeline = array_values(array_filter([
        ['key' => 'recorded', 'at' => $payment->created_at, 'who' => $recordedByName],
        ['key' => 'approved', 'at' => $payment->approved_at, 'who' => $approvedByName],
        ['key' => 'paid', 'at' => $payment->paid_at, 'who' => $paidByName],
        ['key' => 'voided', 'at' => $void->voided_at ?? null, 'who' => $void->voided_by_name ?? null],
    ], static fn (array $s): bool => $s['at'] !== null));
@endphp

<div class="min-w-0 space-y-5">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="text-xs text-text-muted print:hidden">
        <a href="{{ url('/procurement/payments') }}" class="hover:text-primary">{{ __('opes.procurement_detail.back_to_payments') }}</a>
    </nav>

    <header class="flex flex-wrap items-start justify-between gap-3 print:hidden">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">{{ $payment->payment_no }}</h1>
            <p class="mt-1 text-sm text-text-secondary">
                <a href="{{ url('/procurement/suppliers/'.$payment->supplier_id) }}" class="text-primary hover:underline">{{ $payment->supplier_name }}</a>
                <span class="font-mono text-text-muted">({{ $payment->supplier_code }})</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-status-pill :status="$payTone[$payment->status] ?? 'amber'" :label="$payment->status"/>
            <x-status-pill :status="$clearingTone[$payment->clearing_state] ?? 'amber'" :label="str_replace('_', ' ', $payment->clearing_state)"/>
            <button type="button" onclick="window.print()" class="rounded-lg border border-border-primary px-3 py-1.5 text-sm font-medium text-text-primary hover:border-primary/50 hover:text-primary">{{ __('opes.procurement_detail.print_preview') }}</button>
            <a href="{{ url('/procurement/payments/'.$payment->id.'/voucher') }}" target="_blank" rel="noopener" class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ __('opes.procurement_detail.export_voucher') }}</a>
        </div>
    </header>

    @if ($void !== null)
        <div class="rounded-lg border border-danger/40 bg-danger-bg px-4 py-3 text-sm text-danger-text print:hidden">
            <span class="font-semibold">{{ __('opes.procurement_detail.payment_voided') }}:</span> {{ $void->reason }}
            <span class="opacity-70">— {{ $void->voided_by_name ?? '—' }} ({{ $void->voided_at }})@if($void->reversal_piece_no) · {{ __('opes.procurement_detail.reversal_entry') }} {{ $void->reversal_piece_no }}@endif</span>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3 print:hidden">
        <section class="{{ $card }} p-5 lg:col-span-2">
            <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_payment') }}</h2>
            <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_payment_screen.payment_date') }}</dt><dd class="{{ $dd }}">{{ $payment->payment_date }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_payment_screen.method') }}</dt><dd class="{{ $dd }}">{{ str_replace('_', ' ', $payment->payment_method) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_payment_screen.treasury_account') }}</dt><dd class="{{ $dd }}"><span class="font-mono">{{ $payment->treasury_account_code }}</span> {{ $payment->treasury_account_name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_payment_screen.reference') }}</dt><dd class="{{ $dd }} font-mono">{{ $payment->reference ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.supplier_payment_screen.fee_bearer') }}</dt><dd class="{{ $dd }}">{{ $payment->fee_bearer === 'school' ? __('opes.supplier_payment_screen.fee_bearer_school') : __('opes.supplier_payment_screen.fee_bearer_supplier') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.fee_account') }}</dt><dd class="{{ $dd }} font-mono">{{ $payment->fee_account_code ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.fiscal_year') }}</dt><dd class="{{ $dd }}">{{ $payment->fiscal_year_code ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.accounting_period') }}</dt><dd class="{{ $dd }}">{{ $payment->period_month ?? '—' }} @if($payment->period_status)<span class="text-text-muted">({{ $payment->period_status }})</span>@endif</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.batch') }}</dt><dd class="{{ $dd }} font-mono">{{ $payment->batch_no ?? '—' }}@if($payment->export_format) <span class="text-text-muted">({{ $payment->export_format }})</span>@endif</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.batch_exported_at') }}</dt><dd class="{{ $dd }}">{{ $payment->exported_at ?? '—' }}</dd></div>
                @if ($payment->notes)
                    <div class="flex justify-between gap-4 sm:col-span-2"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.notes') }}</dt><dd class="{{ $dd }} text-right">{{ $payment->notes }}</dd></div>
                @endif
            </dl>

            <h3 class="mt-5 {{ $sectionTitle }}">{{ __('opes.procurement_detail.section_supplier') }}</h3>
            <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_niu') }}</dt><dd class="{{ $dd }} font-mono">{{ $payment->supplier_niu ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_screen.col_phone') }}</dt><dd class="{{ $dd }}">{{ $payment->supplier_phone ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="{{ $dt }}">{{ __('opes.procurement_detail.email') }}</dt><dd class="{{ $dd }}">{{ $payment->supplier_email ?? '—' }}</dd></div>
            </dl>
        </section>

        <section class="{{ $card }} p-5">
            <h2 class="{{ $sectionTitle }}">{{ __('opes.procurement_detail.section_timeline') }}</h2>
            <ol class="mt-3 space-y-3">
                @forelse ($timeline as $step)
                    <li class="flex gap-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $step['key'] === 'voided' ? 'bg-danger' : 'bg-heritage-yellow' }}" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-text-primary">{{ __('opes.procurement_detail.timeline_'.$step['key']) }}</p>
                            <p class="text-xs text-text-muted">{{ $step['at'] }}@if($step['who']) · {{ $step['who'] }}@endif</p>
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-text-muted">{{ __('opes.procurement_detail.timeline_empty') }}</li>
                @endforelse
            </ol>
            <p class="mt-4 text-xs text-text-muted">{{ __('opes.procurement_detail.version') }}: {{ $payment->version }}</p>
        </section>
    </div>

    {{-- ── Ledger posting (§4.6) ──────────────────────────────────────── --}}
    <section class="{{ $card }} print:hidden">
        <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_posting') }}</h2>
        @if ($entry === null)
            <p class="px-5 py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.not_posted') }}</p>
        @else
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
                        @foreach ($journalLines as $jl)
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
        @endif
    </section>

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
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.col_delivered') }}</th>
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
                                <td class="px-3 py-2">{{ $attestation->delivered_at ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Retentions (§3.3, account 4817) ────────────────────────────── --}}
    @if ($retentions->isNotEmpty())
        <section class="{{ $card }} print:hidden">
            <h2 class="{{ $sectionTitle }} border-b border-border-primary px-5 py-3">{{ __('opes.procurement_detail.section_retention') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-border-secondary">
                        <tr class="text-left">
                            <th class="{{ $th }}">{{ __('opes.supplier_payment_screen.col_invoice') }}</th>
                            <th class="{{ $th }} text-right">{{ __('opes.procurement_detail.retention_amount') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_screen.col_status') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.retention_due') }}</th>
                            <th class="{{ $th }}">{{ __('opes.procurement_detail.retention_released_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($retentions as $retention)
                            <tr class="border-t border-border-secondary">
                                <td class="px-3 py-2 font-mono">{{ $retention->internal_no ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $retention->amount)->format(false) }}</td>
                                <td class="px-3 py-2">{{ str_replace('_', ' ', (string) $retention->status) }}</td>
                                <td class="px-3 py-2">{{ $retention->release_due_on ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $retention->released_at ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Print-preview voucher, mirrors PrintPaymentVoucher ─────────── --}}
    <div id="print-area" class="{{ $card }} p-8 print:border-0 print:shadow-none">
        <div class="mb-6 flex items-start justify-between border-b border-border-primary pb-4">
            <div>
                <h2 class="text-lg font-bold text-text-primary">{{ __('opes.procurement_detail.doc_payment_voucher') }}</h2>
                <p class="font-mono text-sm text-text-secondary">{{ $payment->payment_no }}</p>
            </div>
            <div class="text-right text-sm">
                <p class="font-medium text-text-primary">{{ $payment->payment_date }}</p>
                <p class="text-text-secondary">{{ str_replace('_', ' ', $payment->payment_method) }}</p>
            </div>
        </div>

        <div class="mb-6 text-sm">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.paid_to') }}</p>
            <p class="font-medium text-text-primary">{{ $payment->supplier_name }}</p>
            <p class="text-text-secondary">{{ $payment->supplier_code }}</p>
            <p class="text-text-secondary">{{ __('opes.procurement_screen.col_niu') }} {{ $payment->supplier_niu ?? '—' }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-border-primary text-left">
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_payment_screen.col_invoice') }}</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.col_supplier_no') }}</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_invoice_screen.col_invoice_date') }}</th>
                        <th class="py-2 pr-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_payment_screen.col_due_date') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_payment_screen.col_total') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.col_allocated') }}</th>
                        <th class="py-2 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.supplier_payment_screen.col_withheld') }}</th>
                        <th class="py-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ __('opes.procurement_detail.col_letter') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($allocations as $allocation)
                        <tr class="border-b border-border-secondary">
                            <td class="py-2 pr-2 font-mono">{{ $allocation->internal_no }}</td>
                            <td class="py-2 pr-2 font-mono">{{ $allocation->supplier_invoice_no }}</td>
                            <td class="py-2 pr-2">{{ $allocation->invoice_date }}</td>
                            <td class="py-2 pr-2">{{ $allocation->due_date }}</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $allocation->total_ttc)->format(false) }}</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $allocation->amount)->format(false) }}</td>
                            <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $allocation->withholding_amount)->format(false) }}</td>
                            <td class="py-2 font-mono text-xs text-text-muted">{{ $allocation->letter_code ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-6 text-center text-sm text-text-muted">{{ __('opes.procurement_detail.no_allocations') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <dl class="w-72 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_payment_screen.col_gross') }}</dt><dd class="font-mono">{{ Money::of((int) $payment->gross_amount)->format(false) }}</dd></div>
                <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_payment_screen.col_withheld') }}</dt><dd class="font-mono">-{{ Money::of((int) $payment->withholding_amount)->format(false) }}</dd></div>
                @if ($payment->fee_amount > 0)
                    <div class="flex justify-between"><dt class="text-text-secondary">{{ __('opes.supplier_payment_screen.fee_amount') }}</dt><dd class="font-mono">{{ Money::of((int) $payment->fee_amount)->format(false) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-border-primary pt-1 font-semibold"><dt>{{ __('opes.supplier_payment_screen.col_net') }}</dt><dd class="font-mono">{{ Money::of((int) $payment->net_amount)->format(false) }}</dd></div>
            </dl>
        </div>
    </div>
</div>
