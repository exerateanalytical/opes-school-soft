<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §19 - the Student Fee Statement: a running account
 * in the classic ledger shape (Date | Reference | Description | Debit |
 * Credit | Balance).
 *
 * AUTHORITY: built from the FEES documents (invoices, adjustments, credit
 * notes, payments, voids, bounces), NEVER from journal_entries. §1 says the
 * module owns the student receivable and emits events the ledger consumes;
 * the auxiliary view must agree with this statement BY CONSTRUCTION (same
 * events, same amounts), not by this statement re-reading the ledger - a
 * posting-rule misconfiguration must show up as a reconciliation break, not
 * silently reshape what the parent is told they owe.
 *
 * Voided payments appear as a credit AND a matching debit (§5.2's named
 * test): a parent who sees a payment vanish will assume theft. Bounced
 * cheques likewise. Running balance is computed in PHP through Money, in
 * strict (date, source, id) order.
 *
 * Read-only: no audit row, gated on `fee.view` only.
 */
final readonly class StudentStatement
{
    public const PERMISSION = Permission::FeeView->value;

    /**
     * @return Collection<int, object{date: string, reference: string, description: string, debit: int, credit: int, balance: int}&\stdClass>
     */
    public function handle(int $enrollmentId, ?string $asOf = null): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        /** @var list<array{date: string, source: int, id: int, reference: string, description: string, debit: int, credit: int}> $rows */
        $rows = [];

        // 1. Issued invoices - debit the gross total (amount + tax). Draft
        //    and cancelled invoices contribute nothing (§3.1).
        $invoices = DB::table('invoices as i')
            ->join('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->where('i.enrollment_id', $enrollmentId)
            ->where('i.status', 'issued')
            ->whereDate('i.issue_date', '<=', $asOf)
            ->groupBy('i.id', 'i.invoice_no', 'i.issue_date')
            ->select([
                'i.id',
                'i.invoice_no',
                'i.issue_date',
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

        // 2. Approved adjustments - SIGNED: positive relieves the student
        //    (credit); a negative surcharge increases what is owed (debit).
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

        // 3. Issued credit notes - credit the gross total.
        $creditNotes = DB::table('credit_notes as cn')
            ->join('credit_note_lines as cnl', 'cnl.credit_note_id', '=', 'cn.id')
            ->where('cn.enrollment_id', $enrollmentId)
            ->where('cn.status', 'issued')
            ->whereDate('cn.issue_date', '<=', $asOf)
            ->groupBy('cn.id', 'cn.credit_note_no', 'cn.issue_date')
            ->select([
                'cn.id',
                'cn.credit_note_no',
                'cn.issue_date',
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

        // 4. Payments - the ORIGINAL credit always appears, voided or not;
        //    the reversal is a separate debit row (below), so the pair nets
        //    to zero instead of a payment silently vanishing (§5.2, §19).
        $payments = DB::table('payments')
            ->where('enrollment_id', $enrollmentId)
            ->whereDate('value_date', '<=', $asOf)
            ->get(['id', 'receipt_no', 'value_date', 'amount', 'payment_method', 'clearing_state', 'bounced_on']);

        $paymentIds = $payments->pluck('id')->all();

        /** @var array<int, object{payment_id: int, voided_at: string}> $confirmedVoids */
        $confirmedVoids = $paymentIds === [] ? [] : DB::table('payment_voids')
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'confirmed')
            ->get(['id', 'payment_id', 'voided_at'])
            ->keyBy('payment_id')
            ->all();

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

        // 5/6. Refunds and write-offs (§19). Their tables belong to a later
        //      work package in this phase; the statement includes them the
        //      moment the tables exist.
        if (Schema::hasTable('refunds')) {
            $refunds = DB::table('refunds')
                ->where('enrollment_id', $enrollmentId)
                ->where('status', 'paid')
                ->whereDate('paid_on', '<=', $asOf)
                ->get(['id', 'refund_receipt_no', 'paid_on', 'amount']);

            foreach ($refunds as $refund) {
                $rows[] = [
                    'date' => (string) $refund->paid_on,
                    'source' => 7,
                    'id' => (int) $refund->id,
                    'reference' => (string) $refund->refund_receipt_no,
                    'description' => 'Refund',
                    'debit' => (int) $refund->amount,
                    'credit' => 0,
                ];
            }
        }

        if (Schema::hasTable('write_offs')) {
            $writeOffs = DB::table('write_offs as w')
                ->join('write_off_lines as wl', 'wl.write_off_id', '=', 'w.id')
                ->where('w.enrollment_id', $enrollmentId)
                ->where('w.status', 'approved')
                ->whereRaw('DATE(w.approved_at) <= ?', [$asOf])
                ->groupBy('w.id', 'w.reference_no', 'w.approved_at')
                ->select([
                    'w.id',
                    'w.reference_no',
                    'w.approved_at',
                    DB::raw('CAST(SUM(wl.amount) AS SIGNED) as total'),
                ])
                ->get();

            foreach ($writeOffs as $writeOff) {
                $rows[] = [
                    'date' => substr((string) $writeOff->approved_at, 0, 10),
                    'source' => 8,
                    'id' => (int) $writeOff->id,
                    'reference' => (string) $writeOff->reference_no,
                    'description' => 'Write-off',
                    'debit' => 0,
                    'credit' => (int) $writeOff->total,
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['date'], $a['source'], $a['id']] <=> [$b['date'], $b['source'], $b['id']];
        });

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
}
