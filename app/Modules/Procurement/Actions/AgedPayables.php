<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.9 - aged payables.
 *
 * SOURCE: unlettered lines on the supplier collective accounts (401 / 481
 * family), per 02-accounting C10 - THE only definition; a parallel query
 * over `SupplierInvoice` would drift from the ledger. A fully-settled,
 * lettered invoice contributes nothing by construction.
 *
 * AXIS: `due_date`, never invoice date - resolved per LINE by walking the
 * entry back to its source document (invoice postings and their
 * recognition/retention companions age on the invoice's due date; payment
 * debits and anything else age on the entry date). The axis and `as_of`
 * are returned WITH the data so the report can print them (§4.9).
 *
 * Buckets: current (not yet due) / 1-30 / 31-60 / 61-90 / >90. Amounts
 * are SIGNED (credit − debit): an unlettered payment debit (a prepayment
 * or partial settlement) nets against the supplier's position, never
 * clamped.
 */
final class AgedPayables
{
    /** @var list<array{key: string, min: int, max: int|null}> */
    private const BUCKETS = [
        ['key' => 'current', 'min' => PHP_INT_MIN, 'max' => 0],
        ['key' => 'days_1_30', 'min' => 1, 'max' => 30],
        ['key' => 'days_31_60', 'min' => 31, 'max' => 60],
        ['key' => 'days_61_90', 'min' => 61, 'max' => 90],
        ['key' => 'days_90_plus', 'min' => 91, 'max' => null],
    ];

    /**
     * @return array{as_of: string, axis: string, rows: list<object{supplier_id: int, supplier_name: string, current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_90_plus: int, total: int}&\stdClass>}
     */
    public function handle(?string $asOf = null): array
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $asOf ??= BusinessDate::today();

        $accountIds = $this->payableAccountIds();

        if ($accountIds === []) {
            return ['as_of' => $asOf, 'axis' => 'due_date', 'rows' => []];
        }

        $lines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $accountIds)
            ->where('l.partner_type', 'supplier')
            ->whereNull('l.lettering_id')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->whereDate('e.date', '<=', $asOf)
            ->get(['l.id', 'l.partner_id', 'l.debit', 'l.credit', 'l.journal_entry_id', 'e.date']);

        $dueDates = $this->dueDatesByEntry();

        /** @var array<int, array<string, int>> $perSupplier */
        $perSupplier = [];

        foreach ($lines as $line) {
            $supplierId = (int) $line->partner_id;
            $amount = (int) $line->credit - (int) $line->debit;

            if ($amount === 0) {
                continue;
            }

            $dueDate = $dueDates[(int) $line->journal_entry_id] ?? (string) $line->date;
            $ageDays = (int) floor((strtotime($asOf) - strtotime($dueDate)) / 86_400);
            $bucket = $this->bucketFor($ageDays);

            $perSupplier[$supplierId] ??= [
                'current' => 0, 'days_1_30' => 0, 'days_31_60' => 0,
                'days_61_90' => 0, 'days_90_plus' => 0, 'total' => 0,
            ];
            $perSupplier[$supplierId][$bucket] += $amount;
            $perSupplier[$supplierId]['total'] += $amount;
        }

        /** @var array<int, string> $names */
        $names = [];

        if ($perSupplier !== []) {
            foreach (DB::table('suppliers')->whereIn('id', array_keys($perSupplier))->get(['id', 'name']) as $supplier) {
                $names[(int) $supplier->id] = (string) $supplier->name;
            }
        }

        $rows = [];

        foreach ($perSupplier as $supplierId => $buckets) {
            $rows[] = (object) [
                'supplier_id' => $supplierId,
                'supplier_name' => $names[$supplierId] ?? ('#'.$supplierId),
                'current' => $buckets['current'],
                'days_1_30' => $buckets['days_1_30'],
                'days_31_60' => $buckets['days_31_60'],
                'days_61_90' => $buckets['days_61_90'],
                'days_90_plus' => $buckets['days_90_plus'],
                'total' => $buckets['total'],
            ];
        }

        usort(
            $rows,
            static fn (\stdClass $a, \stdClass $b): int => $a->supplier_id <=> $b->supplier_id,
        );

        return ['as_of' => $asOf, 'axis' => 'due_date', 'rows' => $rows];
    }

    /**
     * The supplier collective accounts: postable 40x / 48x rows whose
     * partner vocabulary includes suppliers.
     *
     * @return list<int>
     */
    private function payableAccountIds(): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            DB::table('chart_of_accounts')
                ->where('is_collective', true)
                ->where(function ($query): void {
                    $query->where('code', 'like', '40%')->orWhere('code', 'like', '48%');
                })
                ->whereJsonContains('allowed_partner_types', 'supplier')
                ->pluck('id')
                ->all(),
        ));
    }

    /**
     * entry id → the source invoice's due date, for every entry an invoice
     * produced (posting, secondary family, withholding recognition) and for
     * the retention entries hanging off it.
     *
     * @return array<int, string>
     */
    private function dueDatesByEntry(): array
    {
        /** @var array<int, string> $map */
        $map = [];

        $invoices = DB::table('supplier_invoices')
            ->whereNotNull('journal_entry_id')
            ->get(['due_date', 'journal_entry_id', 'secondary_journal_entry_id', 'withholding_journal_entry_id']);

        foreach ($invoices as $invoice) {
            foreach ([$invoice->journal_entry_id, $invoice->secondary_journal_entry_id, $invoice->withholding_journal_entry_id] as $entryId) {
                if ($entryId !== null) {
                    $map[(int) $entryId] = (string) $invoice->due_date;
                }
            }
        }

        $retentions = DB::table('supplier_retentions as r')
            ->join('supplier_invoices as i', 'i.id', '=', 'r.supplier_invoice_id')
            ->get(['i.due_date', 'r.withheld_journal_entry_id', 'r.release_journal_entry_id']);

        foreach ($retentions as $retention) {
            foreach ([$retention->withheld_journal_entry_id, $retention->release_journal_entry_id] as $entryId) {
                if ($entryId !== null) {
                    $map[(int) $entryId] = (string) $retention->due_date;
                }
            }
        }

        return $map;
    }

    private function bucketFor(int $ageDays): string
    {
        foreach (self::BUCKETS as $bucket) {
            if ($ageDays >= $bucket['min'] && ($bucket['max'] === null || $ageDays <= $bucket['max'])) {
                return $bucket['key'];
            }
        }

        return 'days_90_plus';
    }
}
