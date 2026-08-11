<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use App\Modules\Guardians\Models\Guardian;
use App\Support\Clock\BusinessDate;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The guardian portal's fee reading (docs/plans/phase-12-13.md 12.2, rows
 * 13-17 of 07-students.md 7.5): invoices, the running statement and
 * receipts, for one enrollment.
 *
 * This is a portal-scoped TWIN of Fees\Actions\StudentStatement, not a call
 * to it, and the reason is authorization, not arithmetic: StudentStatement
 * gates on `Permission::FeeView`, a STAFF permission the `guardian` role must
 * never hold (Identity\Domain\Role's Phase 12 comment: "portal.access only
 * opens the /portal shell... GuardianScopeMatrix remains the single source
 * of truth"). Granting a guardian `fee.view` to reuse that Action would
 * bypass the matrix for every OTHER fee screen a stray permission check
 * might gate. The portal's own authorization already happened in
 * GuardianPortalPolicy before this class is ever called; every query below
 * is read-only and keyed strictly to the one enrollment it is given.
 *
 * Same source documents, same signed-row arithmetic as StudentStatement
 * (invoices, adjustments, credit notes, payments/voids/bounces) so the
 * numbers a parent sees agree with the cashier's by construction, not by
 * coincidence.
 */
final readonly class ChildFeeStatement
{
    /**
     * @return Collection<int, object{date: string, reference: string, description: string, debit: int, credit: int, balance: int}&\stdClass>
     */
    public function statement(int $enrollmentId, ?string $asOf = null): Collection
    {
        $asOf ??= BusinessDate::today();

        /** @var list<array{date: string, source: int, id: int, reference: string, description: string, debit: int, credit: int}> $rows */
        $rows = [];

        $invoices = DB::table('invoices as i')
            ->join('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->where('i.enrollment_id', $enrollmentId)
            ->where('i.status', 'issued')
            ->whereDate('i.issue_date', '<=', $asOf)
            ->groupBy('i.id', 'i.invoice_no', 'i.issue_date')
            ->select([
                'i.id', 'i.invoice_no', 'i.issue_date',
                DB::raw('CAST(SUM(l.amount + l.tax_amount) AS SIGNED) as total'),
            ])
            ->get();

        foreach ($invoices as $invoice) {
            $rows[] = [
                'date' => (string) $invoice->issue_date,
                'source' => 1,
                'id' => (int) $invoice->id,
                'reference' => (string) ($invoice->invoice_no ?? ''),
                'description' => 'Invoice',
                'debit' => (int) $invoice->total,
                'credit' => 0,
            ];
        }

        if (Schema::hasTable('fee_adjustments')) {
            $adjustments = DB::table('fee_adjustments')
                ->where('enrollment_id', $enrollmentId)
                ->where('status', 'approved')
                ->whereDate('effective_date', '<=', $asOf)
                ->get(['id', 'reference_no', 'effective_date', 'amount', 'reason_type']);

            foreach ($adjustments as $adjustment) {
                $amount = (int) $adjustment->amount;

                $rows[] = [
                    'date' => (string) $adjustment->effective_date,
                    'source' => 2,
                    'id' => (int) $adjustment->id,
                    'reference' => (string) $adjustment->reference_no,
                    'description' => 'Adjustment - '.(string) $adjustment->reason_type,
                    'debit' => $amount < 0 ? -$amount : 0,
                    'credit' => $amount > 0 ? $amount : 0,
                ];
            }
        }

        if (Schema::hasTable('credit_notes')) {
            $creditNotes = DB::table('credit_notes as cn')
                ->join('credit_note_lines as cnl', 'cnl.credit_note_id', '=', 'cn.id')
                ->where('cn.enrollment_id', $enrollmentId)
                ->where('cn.status', 'issued')
                ->whereDate('cn.issue_date', '<=', $asOf)
                ->groupBy('cn.id', 'cn.credit_note_no', 'cn.issue_date')
                ->select([
                    'cn.id', 'cn.credit_note_no', 'cn.issue_date',
                    DB::raw('CAST(SUM(cnl.amount + cnl.tax_amount) AS SIGNED) as total'),
                ])
                ->get();

            foreach ($creditNotes as $creditNote) {
                $rows[] = [
                    'date' => (string) $creditNote->issue_date,
                    'source' => 3,
                    'id' => (int) $creditNote->id,
                    'reference' => (string) ($creditNote->credit_note_no ?? ''),
                    'description' => 'Credit note',
                    'debit' => 0,
                    'credit' => (int) $creditNote->total,
                ];
            }
        }

        foreach ($this->paymentRows($enrollmentId, $asOf) as $row) {
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['source'], $a['id']] <=> [$b['date'], $b['source'], $b['id']]);

        $balance = Money::zero();

        return collect($rows)->map(
            /** @return object{date: string, reference: string, description: string, debit: int, credit: int, balance: int} */
            function (array $row) use (&$balance): object {
                /** @var Money $balance */
                $balance = $balance->plus(Money::of($row['debit']))->minus(Money::of($row['credit']));

                return (object) [
                    'date' => $row['date'],
                    'reference' => $row['reference'],
                    'description' => $row['description'],
                    'debit' => $row['debit'],
                    'credit' => $row['credit'],
                    'balance' => $balance->amount(),
                ];
            }
        )->values();
    }

    /**
     * The enrollment a portal screen or an API read means when it says "this
     * child's fees": the most recent one. Extracted from Livewire\Portal\Fees
     * so the mobile API cannot pick a DIFFERENT enrollment and quietly show a
     * parent a different balance from the one the web portal shows them.
     */
    public function latestEnrollmentId(int $studentId): ?int
    {
        $id = DB::table('enrollments')
            ->where('student_id', $studentId)
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The currency the child is billed in. Read off the enrollment's invoices
     * rather than assumed: 02-accounting keeps `currency` on the invoice, and
     * a payload that hard-coded XAF would be a lie the moment a school is
     * onboarded in another zone.
     */
    public function currency(int $enrollmentId): string
    {
        $currency = DB::table('invoices')
            ->where('enrollment_id', $enrollmentId)
            ->orderByDesc('issue_date')
            ->value('currency');

        return is_string($currency) && $currency !== '' ? $currency : 'XAF';
    }

    /**
     * Row 14's headline numbers: what was billed, what has been paid, what is
     * still owed, and when the next money is due.
     *
     * Derived from statement() rather than re-summed, so a parent's totals and
     * a parent's line list can never disagree - the closing balance IS the
     * outstanding amount by construction.
     *
     * @return array{billed: int, paid: int, outstanding: int, next_due_on: string|null}
     */
    public function totals(int $enrollmentId, ?string $asOf = null): array
    {
        $asOf ??= BusinessDate::today();

        $billed = 0;
        $paid = 0;
        $outstanding = 0;

        foreach ($this->statement($enrollmentId, $asOf) as $row) {
            $billed += $row->debit;
            $paid += $row->credit;
            $outstanding = $row->balance;
        }

        return [
            'billed' => $billed,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'next_due_on' => $this->nextDueOn($enrollmentId, $asOf),
        ];
    }

    /**
     * The fee structure as BILLED - the issued invoice lines, grouped by item.
     * Not the school's price list: a parent is entitled to see what THEIR
     * child was charged, and a fee_structures read would show them a schedule
     * that may have been superseded, waived or adjusted for this enrollment.
     *
     * @return Collection<int, \stdClass>
     */
    public function structure(int $enrollmentId): Collection
    {
        return DB::table('invoice_lines as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.enrollment_id', $enrollmentId)
            ->where('i.status', 'issued')
            ->groupBy('l.description', 'l.description_fr', 'l.fee_category_code')
            ->orderBy('l.description')
            ->select([
                'l.description', 'l.description_fr', 'l.fee_category_code',
                DB::raw('CAST(SUM(l.amount + l.tax_amount) AS SIGNED) as amount'),
            ])
            ->get();
    }

    /**
     * The installment plan rows for this enrollment's issued invoices, with a
     * derived status the app renders as a chip. Cancelled rows are excluded -
     * a cancelled installment is not a schedule item a parent should plan
     * around.
     *
     * @return Collection<int, object{id: int, invoice_id: int, sequence_no: int, label: string, label_fr: string|null, amount: int, due_on: string, status: 'due_soon'|'overdue'|'scheduled'}&\stdClass>
     */
    public function installments(int $enrollmentId, ?string $asOf = null): Collection
    {
        if (! Schema::hasTable('invoice_installments')) {
            return collect();
        }

        $asOf ??= BusinessDate::today();
        // Carbon rather than strtotime(): strtotime returns false on a date it
        // cannot parse, and `date('Y-m-d', false)` silently means 1 Jan 1970 -
        // which would mark every future installment `overdue`.
        $soon = CarbonImmutable::parse($asOf)->addDays(14)->toDateString();

        return DB::table('invoice_installments as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('i.enrollment_id', $enrollmentId)
            ->where('i.status', 'issued')
            ->where('ii.is_cancelled', false)
            ->orderBy('ii.due_date')
            ->orderBy('ii.sequence_no')
            ->get(['ii.id', 'ii.invoice_id', 'ii.sequence_no', 'ii.label', 'ii.label_fr', 'ii.amount', 'ii.due_date'])
            ->map(static function (object $row) use ($asOf, $soon): object {
                $due = (string) $row->due_date;

                return (object) [
                    'id' => (int) $row->id,
                    'invoice_id' => (int) $row->invoice_id,
                    'sequence_no' => (int) $row->sequence_no,
                    'label' => (string) $row->label,
                    'label_fr' => $row->label_fr === null ? null : (string) $row->label_fr,
                    'amount' => (int) $row->amount,
                    'due_on' => $due,
                    // A schedule state, not a payment state: allocations live
                    // on the invoice, and telling a parent an installment is
                    // "paid" would require an allocation read this class does
                    // not own. `overdue`/`due_soon`/`scheduled` is what the
                    // due date alone can honestly say.
                    'status' => $due < $asOf ? 'overdue' : ($due <= $soon ? 'due_soon' : 'scheduled'),
                ];
            })
            ->values();
    }

    /**
     * One issued invoice with its lines, keyed to the enrollment so an id
     * belonging to another child cannot be fetched by guessing.
     */
    public function invoice(int $enrollmentId, int $invoiceId): ?\stdClass
    {
        $invoice = DB::table('invoices')
            ->where('id', $invoiceId)
            ->where('enrollment_id', $enrollmentId)
            ->where('status', 'issued')
            ->first(['id', 'invoice_no', 'issue_date', 'due_date', 'currency', 'type', 'status']);

        if ($invoice === null) {
            return null;
        }

        $lines = DB::table('invoice_lines')
            ->where('invoice_id', $invoiceId)
            ->orderBy('line_no')
            ->get(['line_no', 'description', 'description_fr', 'quantity', 'unit_amount', 'amount', 'tax_amount']);

        $total = 0;

        foreach ($lines as $line) {
            $total += (int) $line->amount + (int) $line->tax_amount;
        }

        return (object) [
            'id' => (int) $invoice->id,
            'invoice_no' => $invoice->invoice_no === null ? null : (string) $invoice->invoice_no,
            'issue_date' => (string) $invoice->issue_date,
            'due_date' => (string) $invoice->due_date,
            'currency' => (string) $invoice->currency,
            'type' => (string) $invoice->type,
            'status' => (string) $invoice->status,
            'total' => $total,
            'lines' => $lines->map(static fn (object $line): array => [
                'line_no' => (int) $line->line_no,
                'description' => (string) $line->description,
                'description_fr' => $line->description_fr === null ? null : (string) $line->description_fr,
                'quantity' => (int) $line->quantity,
                'unit_amount' => (int) $line->unit_amount,
                'amount' => (int) $line->amount,
                'tax_amount' => (int) $line->tax_amount,
            ])->values()->all(),
        ];
    }

    /**
     * One payment row for this enrollment - the receipt endpoint's lookup.
     * Keyed to the enrollment for the same reason as invoice().
     */
    public function receipt(int $enrollmentId, int $paymentId): ?\stdClass
    {
        $row = DB::table('payments')
            ->where('id', $paymentId)
            ->where('enrollment_id', $enrollmentId)
            ->first([
                'id', 'receipt_no', 'value_date', 'amount', 'payment_method',
                'clearing_state', 'payer_name', 'payer_phone', 'reference',
            ]);

        return $row === null ? null : (object) [
            'id' => (int) $row->id,
            'receipt_no' => (string) $row->receipt_no,
            'value_date' => (string) $row->value_date,
            'amount' => (int) $row->amount,
            'payment_method' => (string) $row->payment_method,
            'clearing_state' => (string) $row->clearing_state,
            'payer_name' => (string) $row->payer_name,
            'payer_phone' => $row->payer_phone === null ? null : (string) $row->payer_phone,
            'reference' => $row->reference === null ? null : (string) $row->reference,
        ];
    }

    /**
     * The earliest unpaid-looking obligation after $asOf: the next installment
     * due, falling back to the next invoice due date when no plan exists.
     */
    private function nextDueOn(int $enrollmentId, string $asOf): ?string
    {
        if (Schema::hasTable('invoice_installments')) {
            $due = DB::table('invoice_installments as ii')
                ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                ->where('i.enrollment_id', $enrollmentId)
                ->where('i.status', 'issued')
                ->where('ii.is_cancelled', false)
                ->whereDate('ii.due_date', '>=', $asOf)
                ->orderBy('ii.due_date')
                ->value('ii.due_date');

            if ($due !== null) {
                return (string) $due;
            }
        }

        $due = DB::table('invoices')
            ->where('enrollment_id', $enrollmentId)
            ->where('status', 'issued')
            ->whereDate('due_date', '>=', $asOf)
            ->orderBy('due_date')
            ->value('due_date');

        return $due === null ? null : (string) $due;
    }

    /**
     * Invoice list and detail (7.5 row 13).
     *
     * @return Collection<int, \stdClass>
     */
    public function invoices(int $enrollmentId): Collection
    {
        return DB::table('invoices as i')
            ->leftJoin('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->where('i.enrollment_id', $enrollmentId)
            ->where('i.status', 'issued')
            ->groupBy('i.id', 'i.invoice_no', 'i.issue_date', 'i.due_date')
            ->orderByDesc('i.issue_date')
            ->select([
                'i.id', 'i.invoice_no', 'i.issue_date', 'i.due_date',
                DB::raw('CAST(COALESCE(SUM(l.amount + l.tax_amount), 0) AS SIGNED) as total'),
            ])
            ->get();
    }

    /**
     * Receipts (rows 15-17): the raw payment rows, not the statement's
     * signed debit/credit projection.
     *
     * KNOWN GAP: `payments` (04-fees §11.1, migration 240009) carries only
     * free-text `payer_name`/`payer_phone` - there is no `payer_guardian_id`
     * FK, though GuardianScopeMatrix's own row-16 comment assumes one
     * exists ("the payment query, which filters by `payer_guardian_id`").
     * Without it row 16 ("payments made BY THIS GUARDIAN") and row 17
     * ("made by ANOTHER guardian") cannot be told apart with certainty, so
     * `isOwn` below is a best-effort phone-match label for display only -
     * it is NEVER used to grant access. Authorization is the coarser,
     * SAFE default: the wide grant (`receives_invoices OR is_fee_payer`,
     * rows 13/14/15/17) sees every receipt; a guardian who holds only the
     * row-16 floor sees just the rows that best-effort-match their own
     * phone number, never the unfiltered list. See remaining_issues in the
     * P12-P2 build report - a follow-up migration should add the FK so
     * row 16/17 stop sharing an approximation.
     *
     * @return Collection<int, object{id: int, receipt_no: string, value_date: string, amount: int, payment_method: string, clearing_state: string, is_own: bool}&\stdClass>
     */
    public function receipts(int $enrollmentId, string $guardianPhone): Collection
    {
        $normalised = Guardian::normalisePhone($guardianPhone);

        return DB::table('payments')
            ->where('enrollment_id', $enrollmentId)
            ->orderByDesc('value_date')
            ->orderByDesc('id')
            ->get(['id', 'receipt_no', 'value_date', 'amount', 'payment_method', 'clearing_state', 'payer_phone'])
            ->map(static function (object $row) use ($normalised): object {
                $rowPhone = Guardian::normalisePhone(is_string($row->payer_phone ?? null) ? $row->payer_phone : null);

                return (object) [
                    'id' => (int) $row->id,
                    'receipt_no' => (string) $row->receipt_no,
                    'value_date' => (string) $row->value_date,
                    'amount' => (int) $row->amount,
                    'payment_method' => (string) $row->payment_method,
                    'clearing_state' => (string) $row->clearing_state,
                    'is_own' => $normalised !== null && $rowPhone !== null && $rowPhone === $normalised,
                ];
            })
            ->values();
    }

    /**
     * @return list<array{date: string, source: int, id: int, reference: string, description: string, debit: int, credit: int}>
     */
    private function paymentRows(int $enrollmentId, string $asOf): array
    {
        $payments = DB::table('payments')
            ->where('enrollment_id', $enrollmentId)
            ->whereDate('value_date', '<=', $asOf)
            ->get(['id', 'receipt_no', 'value_date', 'amount', 'payment_method', 'clearing_state', 'bounced_on']);

        $paymentIds = $payments->pluck('id')->all();

        /** @var array<int, object{payment_id: int, voided_at: string}> $confirmedVoids */
        $confirmedVoids = $paymentIds === [] || ! Schema::hasTable('payment_voids') ? [] : DB::table('payment_voids')
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'confirmed')
            ->get(['id', 'payment_id', 'voided_at'])
            ->keyBy('payment_id')
            ->all();

        $rows = [];

        foreach ($payments as $payment) {
            $rows[] = [
                'date' => (string) $payment->value_date,
                'source' => 4,
                'id' => (int) $payment->id,
                'reference' => (string) $payment->receipt_no,
                'description' => 'Payment - '.(string) $payment->payment_method,
                'debit' => 0,
                'credit' => (int) $payment->amount,
            ];

            $void = $confirmedVoids[(int) $payment->id] ?? null;

            if ($void !== null) {
                $voidedOn = substr((string) $void->voided_at, 0, 10);

                if ($voidedOn <= $asOf) {
                    $rows[] = [
                        'date' => $voidedOn,
                        'source' => 5,
                        'id' => (int) $payment->id,
                        'reference' => (string) $payment->receipt_no,
                        'description' => 'Payment voided',
                        'debit' => (int) $payment->amount,
                        'credit' => 0,
                    ];
                }
            }

            if ((string) $payment->clearing_state === 'bounced'
                && $payment->bounced_on !== null
                && (string) $payment->bounced_on <= $asOf) {
                $rows[] = [
                    'date' => (string) $payment->bounced_on,
                    'source' => 6,
                    'id' => (int) $payment->id,
                    'reference' => (string) $payment->receipt_no,
                    'description' => 'Cheque bounced',
                    'debit' => (int) $payment->amount,
                    'credit' => 0,
                ];
            }
        }

        return $rows;
    }
}
