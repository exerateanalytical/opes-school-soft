<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.9 / §6.6 invariant 3 - the
 * withholding register: every withholding by supplier for a tax period,
 * reconciling to the 447-family movement in the ledger. Source is the
 * issued (non-cancelled, non-replaced) attestations - the same rows the
 * period's declaration must equal, so the register, the declaration and
 * account 447 cannot tell three different stories.
 */
final class WithholdingRegister
{
    /**
     * @return array{
     *     period_year: int,
     *     period_month: int,
     *     total_withheld: int,
     *     ledger_447_movement: int,
     *     reconciled: bool,
     *     rows: list<object{supplier_id: int, supplier_name: string, attestation_no: string, withholding_rule_id: int, rule_code: string, base_amount: int, rate_bp_applied: int, withheld_amount: int, source: string}&\stdClass>,
     * }
     */
    public function handle(int $periodYear, int $periodMonth): array
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $attestations = DB::table('withholding_attestations as a')
            ->join('suppliers as s', 's.id', '=', 'a.supplier_id')
            ->join('withholding_rules as r', 'r.id', '=', 'a.withholding_rule_id')
            ->where('a.period_year', $periodYear)
            ->where('a.period_month', $periodMonth)
            ->where('a.status', 'issued')
            ->orderBy('a.id')
            ->get([
                'a.supplier_id', 's.name as supplier_name', 'a.attestation_no',
                'a.withholding_rule_id', 'r.code as rule_code',
                'a.base_amount', 'a.rate_bp_applied', 'a.withheld_amount',
                'a.supplier_payment_id', 'a.supplier_invoice_id',
            ]);

        $rows = [];
        $total = 0;

        foreach ($attestations as $attestation) {
            $rows[] = (object) [
                'supplier_id' => (int) $attestation->supplier_id,
                'supplier_name' => (string) $attestation->supplier_name,
                'attestation_no' => (string) $attestation->attestation_no,
                'withholding_rule_id' => (int) $attestation->withholding_rule_id,
                'rule_code' => (string) $attestation->rule_code,
                'base_amount' => (int) $attestation->base_amount,
                'rate_bp_applied' => (int) $attestation->rate_bp_applied,
                'withheld_amount' => (int) $attestation->withheld_amount,
                'source' => $attestation->supplier_payment_id !== null ? 'payment' : 'invoice',
            ];
            $total += (int) $attestation->withheld_amount;
        }

        $ledgerMovement = $this->ledger447Movement($periodYear, $periodMonth);

        return [
            'period_year' => $periodYear,
            'period_month' => $periodMonth,
            'total_withheld' => $total,
            'ledger_447_movement' => $ledgerMovement,
            'reconciled' => $total === $ledgerMovement,
            'rows' => $rows,
        ];
    }

    /**
     * Net credit movement (credits − debits of NON-reversal activity would
     * over-count; the signed net is the honest figure) on every account any
     * withholding rule uses as its 447 liability, over the period's month.
     */
    private function ledger447Movement(int $periodYear, int $periodMonth): int
    {
        /** @var list<int> $accountIds */
        $accountIds = DB::table('withholding_rules')
            ->whereNotNull('liability_account_id')
            ->distinct()
            ->pluck('liability_account_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($accountIds === []) {
            return 0;
        }

        $from = sprintf('%04d-%02d-01', $periodYear, $periodMonth);
        $to = date('Y-m-t', (int) strtotime($from));

        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $accountIds)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->whereDate('e.date', '>=', $from)
            ->whereDate('e.date', '<=', $to)
            ->selectRaw('CAST(SUM(l.credit) AS SIGNED) as credits, CAST(SUM(l.debit) AS SIGNED) as debits')
            ->first();

        return (int) ($row->credits ?? 0) - (int) ($row->debits ?? 0);
    }
}
