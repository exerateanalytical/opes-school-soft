<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.9 - the supplier statement and the
 * auxiliary/collective reconciliation, BOTH ledger-sourced from the same
 * account set so they cannot disagree with each other.
 *
 * Statement: per supplier, per date range - opening balance, every
 * movement on the supplier collective accounts (invoices, credit notes,
 * payments, withholdings, retention) with a running balance, closing
 * balance. Balances are CREDIT-normal (a positive number is owed TO the
 * supplier), signed, never clamped.
 *
 * Reconciliation (02-accounting C2, run at every period close): Σ
 * per-supplier balances must equal the balance of 401 + 481 + 4817 + 4818
 * - i.e. every line on those accounts carries a supplier partner (L8) and
 * nothing bypassed the auxiliary.
 */
final class SupplierStatement
{
    /**
     * @return array{
     *     supplier_id: int,
     *     from: string,
     *     to: string,
     *     opening_balance: int,
     *     closing_balance: int,
     *     movements: list<object{date: string, piece_no: string|null, label: string, reference: string|null, debit: int, credit: int, balance: int}&\stdClass>,
     * }
     */
    public function handle(int $supplierId, string $from, string $to): array
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $accountIds = $this->payableAccountIds();

        $opening = 0;

        if ($accountIds !== []) {
            $row = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->whereIn('l.account_id', $accountIds)
                ->where('l.partner_type', 'supplier')
                ->where('l.partner_id', $supplierId)
                ->whereIn('e.status', ['posted', 'reversed'])
                ->whereDate('e.date', '<', $from)
                ->selectRaw('CAST(SUM(l.credit) AS SIGNED) as credits, CAST(SUM(l.debit) AS SIGNED) as debits')
                ->first();

            $opening = (int) ($row->credits ?? 0) - (int) ($row->debits ?? 0);
        }

        $movements = [];
        $balance = $opening;

        if ($accountIds !== []) {
            $lines = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->whereIn('l.account_id', $accountIds)
                ->where('l.partner_type', 'supplier')
                ->where('l.partner_id', $supplierId)
                ->whereIn('e.status', ['posted', 'reversed'])
                ->whereDate('e.date', '>=', $from)
                ->whereDate('e.date', '<=', $to)
                ->orderBy('e.date')
                ->orderBy('e.id')
                ->orderBy('l.id')
                ->get(['e.date', 'e.piece_no', 'e.reference', 'l.label', 'l.debit', 'l.credit']);

            foreach ($lines as $line) {
                $balance += (int) $line->credit - (int) $line->debit;

                $movements[] = (object) [
                    'date' => (string) $line->date,
                    'piece_no' => $line->piece_no === null ? null : (string) $line->piece_no,
                    'label' => (string) $line->label,
                    'reference' => $line->reference === null ? null : (string) $line->reference,
                    'debit' => (int) $line->debit,
                    'credit' => (int) $line->credit,
                    'balance' => $balance,
                ];
            }
        }

        return [
            'supplier_id' => $supplierId,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'closing_balance' => $balance,
            'movements' => $movements,
        ];
    }

    /**
     * §4.9 / 02-accounting C2: Σ per-supplier balances vs the balance of
     * the collective accounts themselves, as of a date.
     *
     * @return array{as_of: string, per_supplier_total: int, account_total: int, balanced: bool, by_supplier: array<int, int>}
     */
    public function reconciliation(?string $asOf = null): array
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $asOf ??= BusinessDate::today();
        $accountIds = $this->payableAccountIds();

        /** @var array<int, int> $bySupplier */
        $bySupplier = [];
        $accountTotal = 0;

        if ($accountIds !== []) {
            $partnerRows = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->whereIn('l.account_id', $accountIds)
                ->where('l.partner_type', 'supplier')
                ->whereIn('e.status', ['posted', 'reversed'])
                ->whereDate('e.date', '<=', $asOf)
                ->groupBy('l.partner_id')
                ->selectRaw('l.partner_id, CAST(SUM(l.credit) - SUM(l.debit) AS SIGNED) as balance')
                ->get();

            foreach ($partnerRows as $row) {
                $bySupplier[(int) $row->partner_id] = (int) $row->balance;
            }

            $totalRow = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->whereIn('l.account_id', $accountIds)
                ->whereIn('e.status', ['posted', 'reversed'])
                ->whereDate('e.date', '<=', $asOf)
                ->selectRaw('CAST(SUM(l.credit) AS SIGNED) as credits, CAST(SUM(l.debit) AS SIGNED) as debits')
                ->first();

            $accountTotal = (int) ($totalRow->credits ?? 0) - (int) ($totalRow->debits ?? 0);
        }

        $perSupplierTotal = array_sum($bySupplier);

        return [
            'as_of' => $asOf,
            'per_supplier_total' => $perSupplierTotal,
            'account_total' => $accountTotal,
            'balanced' => $perSupplierTotal === $accountTotal,
            'by_supplier' => $bySupplier,
        ];
    }

    /**
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
}
