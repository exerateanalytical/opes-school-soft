<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Accounting\Actions\AllocateLineAnalytics;
use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\ComponentType;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Models\EmployerProfile;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollItemSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\StatutoryRate;
use App\Modules\Payroll\Support\PayrollInputsHasher;
use App\Modules\Payroll\Support\RunScope;
use App\Support\Audit\Actor;
use App\Support\Money\Allocator;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/05-hr-payroll.md 8.7 - approval, the run's point of no
 * return, in strict order:
 *
 *  1. `inputs_hash` RE-VERIFIED - a changed input FAILS approval; it never
 *     silently recalculates, because a run someone approved is a run
 *     someone reviewed (8.3);
 *  2. segregation of duties - calculated_by <> approved_by, hard (8.7);
 *  3. snapshots written - the authoritative, self-contained record every
 *     re-render reads forever (10);
 *  4. every referenced statutory rate row LOCKED - append-only from here
 *     (4.4);
 *  5. ONE ledger entry through Accounting's PostFromEvent
 *     ('payroll.approved') - THE single posting path; no journal write
 *     exists anywhere else in this module;
 *  6. the section cost split applied to the entry's expense lines via
 *     AllocateLineAnalytics, whose amounts come out of the Money
 *     Allocator's largest-remainder guarantee (8.1, 3.7).
 */
final class ApprovePayrollRun
{
    public function __construct(
        private readonly PayrollInputsHasher $hasher,
        private readonly RunScope $scope,
        private readonly PostFromEvent $post,
        private readonly AllocateLineAnalytics $allocate,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $runId, Actor $actor): PayrollRun
    {
        Gate::authorize(PayrollPermission::APPROVE);

        return DB::transaction(function () use ($runId, $actor): PayrollRun {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($runId);

            if ($run->status !== RunStatus::Calculated) {
                throw new DomainException(sprintf(
                    'Only a calculated run can be approved; run %d is %s (8.7).',
                    $runId,
                    $run->status->value,
                ));
            }

            // Segregation of duties: hard, no override (8.7).
            if ($run->calculated_by === $actor->id) {
                throw new DomainException(
                    'The user who calculated a payroll run cannot approve it (05-hr-payroll 8.7 segregation of duties).'
                );
            }

            /** @var list<PayrollItem> $items */
            $items = PayrollItem::query()
                ->where('payroll_run_id', $run->getKey())
                ->orderBy('staff_member_id')
                ->get()
                ->all();

            if ($items === []) {
                throw new DomainException('A run with no payroll items cannot be approved.');
            }

            // 1 - the hash re-verification. A mismatch names the fact that
            // inputs moved; the approver recalculates and REVIEWS again.
            $monthStart = $run->payroll_month->copy()->startOfMonth();
            $periodEnd = $run->periodEnd();

            // The SAME scope derivation calculate hashed with - a changed
            // scope (a contract opened, closed or reclassified since) is
            // itself an input change and must fail the verification.
            $contractIds = [];

            foreach ($this->scope->includedStaff($monthStart, $periodEnd) as $member) {
                foreach ($member['contract_ids'] as $contractId) {
                    $contractIds[] = $contractId;
                }
            }

            $currentHash = $this->hasher->handle(
                (int) $run->employer_profile_id,
                $monthStart->toDateString(),
                $periodEnd->toDateString(),
                $contractIds,
            );

            if ($run->inputs_hash !== $currentHash) {
                throw new DomainException(
                    'Inputs changed since this run was calculated (inputs_hash mismatch): a compensation, contract, timesheet, rate or component moved. Recalculate and review again - approval never silently recomputes (8.3).'
                );
            }

            /** @var EmployerProfile $profile */
            $profile = EmployerProfile::query()->findOrFail($run->employer_profile_id);

            /** @var array<int, PayrollComponent> $components */
            $components = PayrollComponent::query()->get()->keyBy(
                static fn (PayrollComponent $c): int => (int) $c->getKey(),
            )->all();

            // 3 - snapshots, one per item, self-contained (10.2).
            foreach ($items as $item) {
                $this->writeSnapshot($run, $profile, $item, $components);
            }

            // 4 - lock every referenced rate row (4.4).
            $rateIds = DB::table('payroll_lines')
                ->join('payroll_items', 'payroll_items.id', '=', 'payroll_lines.payroll_item_id')
                ->where('payroll_items.payroll_run_id', $run->getKey())
                ->whereNotNull('payroll_lines.statutory_rate_id')
                ->pluck('payroll_lines.statutory_rate_id');

            StatutoryRate::query()
                ->whereIn('id', $rateIds->all())
                ->where('locked', false)
                ->update(['locked' => true]);

            // 5 - THE posting, through the one door (02-accounting 11.1).
            $reference = sprintf('PAY/%s/%s', $monthStart->format('Y-m'), strtoupper($run->run_type->value));
            $payload = $this->postingPayload($run, $items, $components, $reference);

            $entry = $this->post->handle(
                'payroll.approved',
                $payload,
                $periodEnd->toDateString(),
                $actor,
                $reference,
            );

            // 2+transition - conditional UPDATE with affected-rows check.
            $approved = PayrollRun::query()
                ->whereKey($run->getKey())
                ->where('status', RunStatus::Calculated->value)
                ->update([
                    'status' => RunStatus::Approved->value,
                    'approved_by' => $actor->id,
                    'approved_at' => Carbon::now(),
                    'journal_entry_id' => (int) $entry->getKey(),
                ]);

            if ($approved !== 1) {
                throw new DomainException('Payroll run left the calculated state during approval; aborting (00-core 10.4).');
            }

            // 6 - the analytic cost split on the expense (debit) lines.
            $this->allocateCosts($run, $items, $entry->getKey(), $actor);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Payroll',
                auditableType: PayrollRun::class,
                auditableId: (int) $run->getKey(),
                after: [
                    'status' => RunStatus::Approved->value,
                    'journal_entry_id' => (int) $entry->getKey(),
                    'inputs_hash' => $run->inputs_hash,
                ],
                actor: $actor,
            );

            return $run->refresh();
        });
    }

    /**
     * The 10.2 payload: denormalised, self-contained, rate VALUES copied -
     * readable in a decade even if the rate table is migrated.
     *
     * @param  array<int, PayrollComponent>  $components
     */
    private function writeSnapshot(PayrollRun $run, EmployerProfile $profile, PayrollItem $item, array $components): void
    {
        $staff = DB::table('staff_members')->where('id', $item->staff_member_id)->first();
        $contract = DB::table('staff_contracts')->where('id', $item->staff_contract_id)->first();

        $lines = [];
        $pvidEe = 0;
        $irpp = 0;

        foreach ($item->lines()->orderBy('id')->get() as $line) {
            $component = $components[$line->payroll_component_id];

            $rateCopy = null;

            if ($line->statutory_rate_id !== null) {
                $rate = StatutoryRate::query()->find($line->statutory_rate_id);

                if ($rate !== null) {
                    $rateCopy = [
                        'id' => (int) $rate->getKey(),
                        'code' => $rate->code,
                        'employee_rate_bp' => $rate->employee_rate_bp,
                        'employer_rate_bp' => $rate->employer_rate_bp,
                        'flat_amount' => $rate->flat_amount,
                        'ceiling_amount' => $rate->ceiling_amount,
                        'band_from' => $rate->band_from,
                        'band_to' => $rate->band_to,
                        'effective_from' => $rate->effective_from->toDateString(),
                        'source_citation' => $rate->source_citation,
                    ];
                }
            }

            if ($component->code === 'PVID_EE') {
                $pvidEe = $line->amount;
            }

            if ($component->code === 'IRPP') {
                $irpp = $line->amount;
            }

            $lines[] = [
                'component_code' => $component->code,
                'component_type' => $component->type->value,
                'base_amount' => $line->base_amount,
                'applied_rate_bp' => $line->applied_rate_bp,
                'applied_flat_amount' => $line->applied_flat_amount,
                'bracket_detail' => $line->bracket_detail,
                'statutory_rate_id' => $line->statutory_rate_id,
                'statutory_rate' => $rateCopy,
                'amount' => $line->amount,
                'arrears_for_month' => $line->arrears_for_month?->toDateString(),
            ];
        }

        $payload = [
            'employer' => [
                'cnps_employer_number' => $profile->cnps_employer_number,
                'niu' => $profile->niu,
                'dipe_number' => $profile->dipe_number,
                'cnps_regime' => (string) $profile->cnps_regime,
                'rp_risk_class' => $profile->rp_risk_class,
            ],
            'employee' => [
                'staff_no' => $staff->staff_no ?? null,
                'first_name' => $staff->first_name ?? null,
                'last_name' => $staff->last_name ?? null,
                'cnps_number_present' => ($staff->cnps_number ?? null) !== null,
                'category' => $contract->category ?? null,
                'echelon' => $contract->echelon ?? null,
                'contract_role' => $contract->contract_role ?? null,
            ],
            'period' => [
                'payroll_month' => $run->payroll_month->toDateString(),
                'run_type' => $run->run_type->value,
                'days_worked' => $item->days_worked,
                'days_in_period' => $item->days_in_period,
                'hours_validated' => $item->hours_validated,
                'proration_basis' => $profile->proration_basis,
                'irpp_mode' => (string) $profile->irpp_mode,
            ],
            'totals' => [
                'gross' => $item->gross,
                'sbt' => $item->sbt,
                'cnps_capped_base' => $item->cnps_capped_base,
                'cnps_uncapped_base' => $item->cnps_uncapped_base,
                'taxable_base' => $item->taxable_base,
                'pvid_ee' => $pvidEe,
                'irpp' => $irpp,
                'irpp_amount' => $item->irpp_amount,
                'total_employee_deductions' => $item->total_employee_deductions,
                'total_employer_charges' => $item->total_employer_charges,
                'net' => $item->net,
                'ytd_sbt' => $item->ytd_sbt,
                'ytd_irpp_withheld' => $item->ytd_irpp_withheld,
            ],
            'exception_flags' => $item->exception_flags,
            'lines' => $lines,
            'components' => array_values(array_map(static fn (PayrollComponent $c): array => [
                'code' => $c->code,
                'calculation_order' => $c->calculation_order,
                'formula_expression' => $c->formula_expression,
            ], array_filter($components, static fn (PayrollComponent $c): bool => $c->is_enabled))),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        PayrollItemSnapshot::query()->create([
            'payroll_item_id' => $item->getKey(),
            'snapshot_version' => 1,
            'payload' => $json,
            'payload_hash' => hash('sha256', $json),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * The 8.7 posting payload against PostingEvent::PayrollApproved's
     * schema: net per staff member (Cr 42x with partner), one remittance
     * per statutory/deduction component (Cr class 4), the expense side
     * carried by the rule's balancing debit line(s).
     *
     * @param  list<PayrollItem>  $items
     * @param  array<int, PayrollComponent>  $components
     * @return array<string, mixed>
     */
    private function postingPayload(PayrollRun $run, array $items, array $components, string $reference): array
    {
        $totalGross = 0;
        $totalCharges = 0;
        $totalNet = 0;
        $itemPayloads = [];

        foreach ($items as $item) {
            $totalGross += $item->gross;
            $totalCharges += $item->total_employer_charges;
            $totalNet += $item->net;

            $staffNo = DB::table('staff_members')->where('id', $item->staff_member_id)->value('staff_no');

            $itemPayloads[] = [
                'net' => $item->net,
                'staff_partner' => ['type' => 'staff', 'id' => $item->staff_member_id],
                'label' => (string) $staffNo,
            ];
        }

        // One remittance per component code across the whole run.
        $sums = DB::table('payroll_lines')
            ->join('payroll_items', 'payroll_items.id', '=', 'payroll_lines.payroll_item_id')
            ->where('payroll_items.payroll_run_id', $run->getKey())
            ->groupBy('payroll_lines.payroll_component_id')
            ->selectRaw('payroll_lines.payroll_component_id AS component_id, SUM(payroll_lines.amount) AS total')
            ->get();

        $remittances = [];

        foreach ($sums as $sum) {
            $component = $components[(int) $sum->component_id];

            if (! in_array($component->type, [ComponentType::EmployeeDeduction, ComponentType::EmployerCharge], true)) {
                continue;
            }

            $total = (int) $sum->total;

            if ($total === 0) {
                continue;
            }

            if ($component->liability_account_id === null) {
                throw new DomainException(sprintf(
                    'Component %s has no liability account configured; an accountant must map it before payroll can post (5.2).',
                    $component->code,
                ));
            }

            $remittances[] = [
                'amount' => $total,
                'liability_account_id' => (int) $component->liability_account_id,
                'label' => $component->code,
            ];
        }

        return [
            'run' => [
                'total_gross' => $totalGross,
                'total_employer_charges' => $totalCharges,
                'total_net' => $totalNet,
                'reference' => $reference,
                'items' => $itemPayloads,
                'remittances' => $remittances,
            ],
        ];
    }

    /**
     * 8.1/3.7: cost is allocated AFTER statutory computation, on the
     * ledger, using the Money Allocator. Each expense (debit) line of the
     * entry is split across the section axis by the staff-cost weights
     * aggregated from every item's StaffCostAllocation.
     *
     * Skipped - with an exception flag left on the run's items - when any
     * included contract carries no allocation: a partial split would
     * misstate every section's P&L.
     *
     * @param  list<PayrollItem>  $items
     */
    private function allocateCosts(PayrollRun $run, array $items, int|string $entryId, Actor $actor): void
    {
        $periodEnd = $run->periodEnd()->toDateString();

        /** @var array<int, Money> $amountByValueId */
        $amountByValueId = [];

        foreach ($items as $item) {
            $allocations = DB::table('staff_cost_allocations')
                ->where('staff_contract_id', $item->staff_contract_id)
                ->where('effective_from', '<=', $periodEnd)
                ->where(function ($q) use ($periodEnd): void {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd);
                })
                ->orderBy('id')
                ->get();

            if ($allocations->isEmpty()) {
                // No complete split - no allocation at all (see docblock).
                return;
            }

            $cost = Money::of($item->gross + $item->total_employer_charges);
            $shares = [];
            $valueIds = [];

            foreach ($allocations as $allocation) {
                $shares[] = (int) $allocation->percentage_bp;
                $valueIds[] = (int) $allocation->analytic_value_id;
            }

            $parts = Allocator::allocate($cost, $shares);

            foreach ($parts as $index => $part) {
                $valueId = $valueIds[$index];
                $amountByValueId[$valueId] = ($amountByValueId[$valueId] ?? Money::zero())->plus($part);
            }
        }

        if ($amountByValueId === []) {
            return;
        }

        // Resolve value codes and the (single) axis they belong to.
        $values = DB::table('analytic_values')
            ->whereIn('id', array_keys($amountByValueId))
            ->join('analytic_axes', 'analytic_axes.id', '=', 'analytic_values.analytic_axis_id')
            ->get([
                'analytic_values.id as value_id',
                'analytic_values.code as value_code',
                'analytic_axes.code as axis_code',
            ]);

        $axisCodes = $values->pluck('axis_code')->unique();

        if ($axisCodes->count() !== 1) {
            throw new DomainException('Staff cost allocations span more than one analytic axis; configure one section axis (3.7).');
        }

        $axisCode = (string) $axisCodes->first();

        // Convert the exact per-value amounts into shares of the WHOLE
        // (AllocateLineAnalytics' contract), letting the Allocator hand
        // out the basis points so they sum to exactly 100%.
        $orderedValueIds = array_keys($amountByValueId);
        $magnitudes = array_map(
            static fn (int $valueId): int => $amountByValueId[$valueId]->amount(),
            $orderedValueIds,
        );

        $shareParts = Allocator::allocate(Money::of(AllocateLineAnalytics::FULL_SHARE_BP), $magnitudes);

        $splits = [];
        $codesById = [];

        foreach ($values as $value) {
            $codesById[(int) $value->value_id] = (string) $value->value_code;
        }

        foreach ($orderedValueIds as $index => $valueId) {
            $shareBp = $shareParts[$index]->amount();

            if ($shareBp === 0) {
                continue;
            }

            $splits[] = ['valueCode' => $codesById[$valueId], 'shareBp' => $shareBp];
        }

        if ($splits === []) {
            return;
        }

        // Every expense-side (debit) line of the entry follows the split.
        $debitLineIds = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->where('debit', '>', 0)
            ->pluck('id');

        foreach ($debitLineIds as $lineId) {
            $this->allocate->handle((int) $lineId, $axisCode, $splits, $actor);
        }
    }
}
