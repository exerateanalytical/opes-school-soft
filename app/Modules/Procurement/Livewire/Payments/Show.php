<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Payments;

use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §4.7 - one supplier payment's full
 * detail: header, its invoice allocations, and a print-preview of the
 * payment voucher. The voucher itself is NOT reinvented here - it is the
 * existing PrintPaymentVoucher Action / PrintPaymentVoucherController
 * (GET /procurement/payments/{payment}/voucher), which this screen's
 * "Export PDF" button simply links to; the on-screen preview below mirrors
 * that same voucher layout so the user can check it before printing.
 *
 * Read-only - approve / pay / void all stay on the list screen
 * (Payments\Index). Gated on the SAME permission as that list,
 * `procurement.payment_record`.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $paymentId;

    public function mount(int $payment): void
    {
        Gate::authorize(SupplierPaymentPermission::RECORD);

        $this->paymentId = $payment;

        $exists = DB::table('supplier_payments')->where('id', $payment)->exists();

        if (! $exists) {
            abort(404);
        }
    }

    private function payment(): object
    {
        /** @var object $payment */
        $payment = DB::table('supplier_payments as p')
            ->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'p.treasury_account_id')
            ->leftJoin('chart_of_accounts as fa', 'fa.id', '=', 'p.fee_expense_account_id')
            ->leftJoin('supplier_payment_batches as b', 'b.id', '=', 'p.batch_id')
            ->leftJoin('fiscal_years as fy', 'fy.id', '=', 'p.fiscal_year_id')
            ->leftJoin('accounting_periods as ap', 'ap.id', '=', 'p.accounting_period_id')
            ->where('p.id', $this->paymentId)
            ->firstOrFail([
                'p.id', 'p.payment_no', 'p.supplier_id', 's.name as supplier_name', 's.code as supplier_code',
                's.niu as supplier_niu', 's.phone as supplier_phone', 's.email as supplier_email',
                'p.payment_date', 'p.payment_method', 'p.treasury_account_id',
                'a.code as treasury_account_code', 'a.name as treasury_account_name',
                'p.reference', 'p.gross_amount', 'p.withholding_amount', 'p.fee_amount', 'p.fee_bearer',
                'fa.code as fee_account_code',
                'p.net_amount', 'p.status', 'p.clearing_state', 'p.recorded_by', 'p.approved_by', 'p.approved_at',
                'p.paid_by', 'p.paid_at', 'p.notes', 'p.journal_entry_id',
                'p.batch_id', 'b.batch_no', 'b.export_format', 'b.exported_at',
                'fy.code as fiscal_year_code', 'ap.period_month', 'ap.status as period_status',
                'p.version', 'p.created_at',
            ]);

        return $payment;
    }

    /**
     * @return Collection<int, object>
     */
    private function allocations(): Collection
    {
        return DB::table('supplier_payment_allocations as spa')
            ->join('supplier_invoices as i', 'i.id', '=', 'spa.supplier_invoice_id')
            ->where('spa.supplier_payment_id', $this->paymentId)
            ->whereNull('spa.reversed_at')
            ->orderBy('spa.id')
            ->get([
                'i.id', 'i.internal_no', 'i.supplier_invoice_no', 'i.invoice_date', 'i.due_date',
                'i.total_ttc', 'i.net_payable', 'i.status as invoice_status',
                'spa.amount', 'spa.withholding_amount', 'spa.letter_code',
            ]);
    }

    /**
     * §4.7 - the void record, if this payment was reversed. `is_voided` is
     * derived from the presence of this row, never stored twice.
     */
    private function void(): ?object
    {
        /** @var object|null $row */
        $row = DB::table('supplier_payment_voids as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.voided_by')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'v.reversal_journal_entry_id')
            ->where('v.supplier_payment_id', $this->paymentId)
            ->first(['v.reason', 'v.voided_at', 'u.name as voided_by_name', 'je.piece_no as reversal_piece_no']);

        return $row;
    }

    /**
     * §4.6 / 02-accounting - the treasury posting this payment produced.
     *
     * @return Collection<int, object>
     */
    private function journalLines(?int $entryId): Collection
    {
        if ($entryId === null) {
            return collect();
        }

        return DB::table('journal_entry_lines as jl')
            ->join('chart_of_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.journal_entry_id', $entryId)
            ->orderBy('jl.sequence')
            ->get(['a.code as account_code', 'a.name as account_name', 'jl.label', 'jl.debit', 'jl.credit']);
    }

    private function journalEntry(?int $entryId): ?object
    {
        if ($entryId === null) {
            return null;
        }

        /** @var object|null $row */
        $row = DB::table('journal_entries as je')
            ->leftJoin('journals as j', 'j.id', '=', 'je.journal_id')
            ->where('je.id', $entryId)
            ->first(['je.id', 'je.piece_no', 'je.date', 'je.label', 'je.status', 'j.code as journal_code']);

        return $row;
    }

    /**
     * §6.6 - attestations de retenue à la source issued for this payment.
     *
     * @return Collection<int, object>
     */
    private function attestations(): Collection
    {
        return DB::table('withholding_attestations')
            ->where('supplier_payment_id', $this->paymentId)
            ->orderBy('id')
            ->get(['id', 'attestation_no', 'period_month', 'period_year', 'base_amount', 'rate_bp_applied', 'withheld_amount', 'status', 'issued_at', 'delivered_at']);
    }

    /**
     * §3.3 - retentions (4817) withheld on, or released by, this payment.
     *
     * @return Collection<int, object>
     */
    private function retentions(): Collection
    {
        return DB::table('supplier_retentions as sr')
            ->leftJoin('supplier_invoices as i', 'i.id', '=', 'sr.supplier_invoice_id')
            ->where('sr.supplier_payment_id', $this->paymentId)
            ->orderBy('sr.id')
            ->get(['sr.id', 'i.internal_no', 'sr.amount', 'sr.status', 'sr.release_due_on', 'sr.released_at']);
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $name = DB::table('users')->where('id', $userId)->value('name');

        return $name === null ? null : (string) $name;
    }

    public function render(): mixed
    {
        $payment = $this->payment();
        $entryId = $payment->journal_entry_id === null ? null : (int) $payment->journal_entry_id;

        return view('livewire.procurement.payments.show', [
            'payment' => $payment,
            'allocations' => $this->allocations(),
            'void' => $this->void(),
            'entry' => $this->journalEntry($entryId),
            'journalLines' => $this->journalLines($entryId),
            'attestations' => $this->attestations(),
            'retentions' => $this->retentions(),
            'recordedByName' => $this->userName($payment->recorded_by),
            'approvedByName' => $this->userName($payment->approved_by),
            'paidByName' => $this->userName($payment->paid_by),
        ]);
    }
}
