<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\ProvisionAccountsUnconfigured;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The accrued-leave provision (docs/specs/05-hr-payroll.md 12.5): a
 * liability the balance sheet must carry, posted Dr 66x / Cr 428x monthly.
 *
 * The account codes are NEEDS VERIFICATION. Their confirmation point is the
 * ALLOCATION_CONGE component's expense/liability account mapping (seeded
 * NULL): until BOTH are mapped, this Action CALCULATES AND REPORTS but does
 * not post - it raises ProvisionAccountsUnconfigured carrying the report.
 * Leave accrual and the allocation payment are unaffected; only the
 * accounting entry is withheld.
 *
 * The reported amount, per contract with a positive ledger balance:
 *   trailing_12_month_remuneration / 16 × (balance_days / annual_entitlement)
 * (12.4's allocation formula applied to the untaken balance). Contracts
 * whose annual entitlement is still NULL - or with no snapshot history -
 * are listed as `unquantified` rather than valued from a guess.
 */
final class PostLeaveProvision
{
    public function __construct(private readonly PostFromEvent $post) {}

    /**
     * @return array{month: string, provision_total: int, lines: list<array<string, mixed>>, unquantified: list<array<string, mixed>>, journal_entry_id: int|null}
     */
    public function handle(string $payrollMonth, Actor $actor): array
    {
        Gate::authorize(PayrollPermission::RUN);

        $month = Carbon::parse($payrollMonth)->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth()->startOfDay();

        $report = $this->computeReport($month, $monthEnd);

        /** @var PayrollComponent|null $allocation */
        $allocation = PayrollComponent::query()->where('code', 'ALLOCATION_CONGE')->first();

        if ($allocation === null
            || $allocation->expense_account_id === null
            || $allocation->liability_account_id === null) {
            throw new ProvisionAccountsUnconfigured(
                $report,
                'The leave provision computes but does not post: the 66x/428x accounts are NEEDS '
                .'VERIFICATION (05-hr-payroll 12.5). Map the ALLOCATION_CONGE component to its '
                .'expense and liability accounts to enable posting; the computed amounts are on the report.'
            );
        }

        if ($report['provision_total'] > 0) {
            $entry = $this->post->handle(
                PostingEvent::PayrollLeaveProvision->value,
                [
                    'provision' => [
                        'amount' => $report['provision_total'],
                        'month' => $month->toDateString(),
                        'expense_account_id' => $allocation->expense_account_id,
                        'liability_account_id' => $allocation->liability_account_id,
                        'reference' => 'LEAVE-PROV-'.$month->format('Y-m'),
                    ],
                ],
                $monthEnd->toDateString(),
                $actor,
                'LEAVE-PROV-'.$month->format('Y-m'),
            );

            $report['journal_entry_id'] = (int) $entry->getKey();
        }

        return $report;
    }

    /**
     * @return array{month: string, provision_total: int, lines: list<array<string, mixed>>, unquantified: list<array<string, mixed>>, journal_entry_id: int|null}
     */
    private function computeReport(Carbon $month, Carbon $monthEnd): array
    {
        $annual = DB::table('leave_types')->where('code', 'conge_annuel')->first(['id', 'statutory_days']);

        $lines = [];
        $unquantified = [];
        $total = 0;

        if ($annual !== null) {
            $balances = DB::table('leave_accruals')
                ->where('leave_type_id', $annual->id)
                ->where('effective_on', '<=', $monthEnd->toDateString())
                ->groupBy('staff_contract_id')
                ->select('staff_contract_id', DB::raw('SUM(delta_days) as balance_days'))
                ->havingRaw('SUM(delta_days) > 0')
                ->get();

            $trailingFrom = $month->copy()->subMonthsNoOverflow(11)->startOfMonth()->toDateString();

            foreach ($balances as $row) {
                $contractId = (int) $row->staff_contract_id;
                $balanceDays = (float) $row->balance_days;

                if ($annual->statutory_days === null) {
                    $unquantified[] = [
                        'staff_contract_id' => $contractId,
                        'balance_days' => $balanceDays,
                        'why' => 'annual_entitlement_unconfigured',
                    ];

                    continue;
                }

                $staffMemberId = (int) DB::table('staff_contracts')->where('id', $contractId)->value('staff_member_id');

                // Trailing 12-month remuneration from approved run items
                // (snapshot-backed rows; 12.4 reads the same history).
                $trailing = (int) DB::table('payroll_items')
                    ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
                    ->where('payroll_items.staff_member_id', $staffMemberId)
                    ->where('payroll_items.is_cancelled', false)
                    ->whereIn('payroll_runs.status', ['approved', 'paid', 'closed'])
                    ->whereBetween('payroll_runs.payroll_month', [$trailingFrom, $month->toDateString()])
                    ->sum('payroll_items.gross');

                if ($trailing <= 0) {
                    $unquantified[] = [
                        'staff_contract_id' => $contractId,
                        'balance_days' => $balanceDays,
                        'why' => 'no_remuneration_history',
                    ];

                    continue;
                }

                // 12.4: allocation ≥ trailing/16, prorated to the untaken share
                // of the annual entitlement.
                $amount = (int) round($trailing / 16 * ($balanceDays / (int) $annual->statutory_days));

                $lines[] = [
                    'staff_contract_id' => $contractId,
                    'balance_days' => $balanceDays,
                    'trailing_12m_remuneration' => $trailing,
                    'amount' => $amount,
                ];
                $total += $amount;
            }
        }

        return [
            'month' => $month->toDateString(),
            'provision_total' => $total,
            'lines' => $lines,
            'unquantified' => $unquantified,
            'journal_entry_id' => null,
        ];
    }
}
