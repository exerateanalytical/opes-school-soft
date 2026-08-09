<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §11.3 / §21 - aged receivables per student.
 *
 * AXIS: instalment due date, never invoice date - a September invoice with
 * a March tranche must not sit in the 180+ bucket in March. An invoice with
 * three tranches can appear in up to three buckets at once. Buckets:
 * current (not yet due), 1-30, 31-60, 61-90, 91-180, 180+.
 *
 * Only ISSUED invoices count (§5: draft and cancelled contribute 0).
 * Instalment satisfaction is derived by ordering (§3.3): cumulative
 * settlement (allocations + adjustments + credit notes + write-offs) fills
 * tranches oldest-sequence-first.
 *
 * A student in credit shows a NEGATIVE net, never clamped to zero (C9/A5:
 * Money is signed; the credit is a 4191 liability, and hiding it under a
 * zero is exactly the netting OHADA forbids).
 *
 * `as_of` defaults to business_date() (00-core §7.5), never UTC now().
 * Read-only, gated on `fee.view`.
 */
final readonly class AgedBalances
{
    public const PERMISSION = Permission::FeeView->value;

    /** @var list<array{key: string, min: int, max: int|null}> */
    private const BUCKETS = [
        ['key' => 'current', 'min' => PHP_INT_MIN, 'max' => 0],
        ['key' => 'days_1_30', 'min' => 1, 'max' => 30],
        ['key' => 'days_31_60', 'min' => 31, 'max' => 60],
        ['key' => 'days_61_90', 'min' => 61, 'max' => 90],
        ['key' => 'days_91_180', 'min' => 91, 'max' => 180],
        ['key' => 'days_180_plus', 'min' => 181, 'max' => null],
    ];

    /**
     * @return Collection<int, object{student_id: int, current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_91_180: int, days_180_plus: int, outstanding: int, unallocated_credit: int, net: int}&\stdClass>
     */
    public function handle(?string $asOf = null, ?int $academicYearId = null): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        // Issued invoices in scope, with their gross line totals.
        $invoices = DB::table('invoices as i')
            ->join('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->where('i.status', 'issued')
            ->whereDate('i.issue_date', '<=', $asOf)
            ->when($academicYearId !== null, fn ($query) => $query->where('i.academic_year_id', $academicYearId))
            ->groupBy('i.id', 'i.student_id', 'i.due_date')
            ->select([
                'i.id',
                'i.student_id',
                'i.due_date',
                DB::raw('CAST(SUM(l.amount + l.tax_amount) AS SIGNED) as invoiced'),
            ])
            ->get();

        $invoiceIds = array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $invoices->pluck('id')->all(),
        ));

        // §5's four settlement terms, per invoice, all as-of-filtered.
        $settledByInvoice = $this->settledByInvoice($invoiceIds, $asOf);

        /** @var array<int, array<int, array{due_date: string, amount: int}>> $installmentsByInvoice */
        $installmentsByInvoice = [];

        if ($invoiceIds !== []) {
            $installmentRows = DB::table('invoice_installments')
                ->whereIn('invoice_id', $invoiceIds)
                ->where('is_cancelled', false)
                ->orderBy('invoice_id')
                ->orderBy('sequence_no')
                ->get(['invoice_id', 'due_date', 'amount']);

            foreach ($installmentRows as $installment) {
                $installmentsByInvoice[(int) $installment->invoice_id][] = [
                    'due_date' => (string) $installment->due_date,
                    'amount' => (int) $installment->amount,
                ];
            }
        }

        /** @var array<int, array<string, int>> $perStudent */
        $perStudent = [];

        foreach ($invoices as $invoice) {
            $invoiceId = (int) $invoice->id;
            $studentId = (int) $invoice->student_id;

            // §3.3: an invoice without first-class instalments falls back to
            // one implicit tranche at the header due_date.
            $installments = $installmentsByInvoice[$invoiceId]
                ?? [['due_date' => (string) $invoice->due_date, 'amount' => (int) $invoice->invoiced]];

            // Cumulative satisfaction, oldest tranche first (§3.3).
            $settled = $settledByInvoice[$invoiceId] ?? 0;

            foreach ($installments as $installment) {
                $consumed = min($settled, $installment['amount']);
                $settled -= $consumed;
                $open = $installment['amount'] - $consumed;

                if ($open <= 0) {
                    continue;
                }

                $ageDays = (int) floor(
                    (strtotime($asOf) - strtotime($installment['due_date'])) / 86_400
                );

                $bucket = $this->bucketFor($ageDays);

                $perStudent[$studentId] ??= $this->emptyRow();
                $perStudent[$studentId][$bucket] += $open;
                $perStudent[$studentId]['outstanding'] += $open;
            }

            // Over-settlement beyond the invoice total (e.g. credit note on a
            // fully-paid line) surfaces as student credit, not negative aging.
        }

        foreach ($this->unallocatedCreditByStudent($asOf, $academicYearId) as $studentId => $credit) {
            if ($credit <= 0) {
                continue;
            }

            $perStudent[$studentId] ??= $this->emptyRow();
            $perStudent[$studentId]['unallocated_credit'] = $credit;
        }

        return collect($perStudent)
            ->map(
                /** @return object{student_id: int, current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_91_180: int, days_180_plus: int, outstanding: int, unallocated_credit: int, net: int} */
                function (array $row, int $studentId): object {
                    // SIGNED: a student whose credit exceeds their arrears
                    // nets NEGATIVE - never clamped (C9).
                    return (object) [
                        'student_id' => $studentId,
                        'current' => (int) $row['current'],
                        'days_1_30' => (int) $row['days_1_30'],
                        'days_31_60' => (int) $row['days_31_60'],
                        'days_61_90' => (int) $row['days_61_90'],
                        'days_91_180' => (int) $row['days_91_180'],
                        'days_180_plus' => (int) $row['days_180_plus'],
                        'outstanding' => (int) $row['outstanding'],
                        'unallocated_credit' => (int) $row['unallocated_credit'],
                        'net' => (int) $row['outstanding'] - (int) $row['unallocated_credit'],
                    ];
                }
            )
            ->sortBy('student_id')
            ->values();
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return array<int, int>
     */
    private function settledByInvoice(array $invoiceIds, string $asOf): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        /** @var array<int, int> $settled */
        $settled = [];

        // allocated(ℓ, d) with §5.2's two exclusions: voided payments and
        // bounced cheques do not settle anything.
        $allocations = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('pa.invoice_id', $invoiceIds)
            ->whereNull('pa.reversed_at')
            ->whereDate('p.value_date', '<=', $asOf)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->groupBy('pa.invoice_id')
            ->select(['pa.invoice_id', DB::raw('CAST(SUM(pa.amount) AS SIGNED) as total')])
            ->get();

        foreach ($allocations as $row) {
            $settled[(int) $row->invoice_id] = ($settled[(int) $row->invoice_id] ?? 0) + (int) $row->total;
        }

        // adjustments(ℓ, d) - SIGNED: a surcharge (negative) INCREASES the
        // outstanding, which the addition of a negative delivers.
        $adjustments = DB::table('fee_adjustments as a')
            ->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
            ->whereIn('l.invoice_id', $invoiceIds)
            ->where('a.status', 'approved')
            ->whereDate('a.effective_date', '<=', $asOf)
            ->groupBy('l.invoice_id')
            ->select(['l.invoice_id', DB::raw('CAST(SUM(a.amount) AS SIGNED) as total')])
            ->get();

        foreach ($adjustments as $row) {
            $settled[(int) $row->invoice_id] = ($settled[(int) $row->invoice_id] ?? 0) + (int) $row->total;
        }

        $creditNotes = DB::table('credit_note_lines as cnl')
            ->join('credit_notes as cn', 'cn.id', '=', 'cnl.credit_note_id')
            ->join('invoice_lines as l', 'l.id', '=', 'cnl.invoice_line_id')
            ->whereIn('l.invoice_id', $invoiceIds)
            ->where('cn.status', 'issued')
            ->whereDate('cn.issue_date', '<=', $asOf)
            ->groupBy('l.invoice_id')
            ->select(['l.invoice_id', DB::raw('CAST(SUM(cnl.amount + cnl.tax_amount) AS SIGNED) as total')])
            ->get();

        foreach ($creditNotes as $row) {
            $settled[(int) $row->invoice_id] = ($settled[(int) $row->invoice_id] ?? 0) + (int) $row->total;
        }

        if (Schema::hasTable('write_offs')) {
            $writeOffs = DB::table('write_off_lines as wl')
                ->join('write_offs as w', 'w.id', '=', 'wl.write_off_id')
                ->join('invoice_lines as l', 'l.id', '=', 'wl.invoice_line_id')
                ->whereIn('l.invoice_id', $invoiceIds)
                ->where('w.status', 'approved')
                ->whereRaw('DATE(w.approved_at) <= ?', [$asOf])
                ->groupBy('l.invoice_id')
                ->select(['l.invoice_id', DB::raw('CAST(SUM(wl.amount) AS SIGNED) as total')])
                ->get();

            foreach ($writeOffs as $row) {
                $settled[(int) $row->invoice_id] = ($settled[(int) $row->invoice_id] ?? 0) + (int) $row->total;
            }
        }

        return $settled;
    }

    /**
     * §12.3's authoritative formula (never the cached column):
     * Σ payments (not voided, not bounced) − Σ live allocations − Σ refunds paid.
     *
     * @return array<int, int>
     */
    private function unallocatedCreditByStudent(string $asOf, ?int $academicYearId): array
    {
        /** @var array<int, int> $credit */
        $credit = [];

        $payments = DB::table('payments as p')
            ->whereDate('p.value_date', '<=', $asOf)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->when($academicYearId !== null, fn ($query) => $query->where('p.academic_year_id', $academicYearId))
            ->groupBy('p.student_id')
            ->select(['p.student_id', DB::raw('CAST(SUM(p.amount) AS SIGNED) as total')])
            ->get();

        foreach ($payments as $row) {
            $credit[(int) $row->student_id] = (int) $row->total;
        }

        $allocations = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereNull('pa.reversed_at')
            ->whereDate('p.value_date', '<=', $asOf)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->when($academicYearId !== null, fn ($query) => $query->where('p.academic_year_id', $academicYearId))
            ->groupBy('p.student_id')
            ->select(['p.student_id', DB::raw('CAST(SUM(pa.amount) AS SIGNED) as total')])
            ->get();

        foreach ($allocations as $row) {
            $credit[(int) $row->student_id] = ($credit[(int) $row->student_id] ?? 0) - (int) $row->total;
        }

        if (Schema::hasTable('refunds')) {
            $refunds = DB::table('refunds')
                ->where('status', 'paid')
                ->whereDate('paid_on', '<=', $asOf)
                ->groupBy('student_id')
                ->select(['student_id', DB::raw('CAST(SUM(amount) AS SIGNED) as total')])
                ->get();

            foreach ($refunds as $row) {
                $credit[(int) $row->student_id] = ($credit[(int) $row->student_id] ?? 0) - (int) $row->total;
            }
        }

        return $credit;
    }

    private function bucketFor(int $ageDays): string
    {
        foreach (self::BUCKETS as $bucket) {
            if ($ageDays >= $bucket['min'] && ($bucket['max'] === null || $ageDays <= $bucket['max'])) {
                return $bucket['key'];
            }
        }

        return 'days_180_plus';
    }

    /**
     * @return array<string, int>
     */
    private function emptyRow(): array
    {
        return [
            'current' => 0,
            'days_1_30' => 0,
            'days_31_60' => 0,
            'days_61_90' => 0,
            'days_91_180' => 0,
            'days_180_plus' => 0,
            'outstanding' => 0,
            'unallocated_credit' => 0,
        ];
    }
}
