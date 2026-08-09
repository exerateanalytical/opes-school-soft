<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Fees\Actions\CarryForwardStudentCredit;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverBalanceCarry;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/08-operations.md §6.2 step 7 - "Carry balances forward".
 *
 * Per student, never netted across students (04-fees C9/A5):
 *
 *  - CREDIT balances carry forward automatically - one call to
 *    Fees\Actions\CarryForwardStudentCredit PER STUDENT, which is the single
 *    Fees door to Accounting\Actions\PostFromEvent. This step never touches a
 *    journal table and never sums two students' money into one figure; the
 *    per-student journal entry id lands on the per-student
 *    RolloverBalanceCarry row.
 *
 *  - DEBIT balances REMAIN on the old year's invoices. Each student still
 *    owing needs an explicit per-student choice from the operator:
 *    `debt_carry` (the debt stays visible and collectable, recorded as
 *    explicitly carried) or `block` (enrolment gate; recorded here, enforced
 *    by Admissions). Missing choices are a REFUSAL that lists the students -
 *    the "students still owing" list of the spec.
 *
 *  - `write_off` is recognised but REFUSED in v1: a write-off is a
 *    segregation-of-duties workflow (04-fees §8 - granted by one person,
 *    approved by another, posted through its own event) and the Fees module
 *    does not ship it yet. Routing it through a wizard as a one-click choice
 *    would create exactly the unsupervised posting path the architecture
 *    forbids.
 *
 * Idempotent on resume: a (run, student, kind) that already has its
 * RolloverBalanceCarry row - unique by constraint - is skipped, so a killed
 * and restarted run posts nothing twice.
 */
final class CarryBalancesStep
{
    /** See PromoteStudentsStep::PERMISSION. */
    public const PERMISSION = 'rollover.run';

    public const CHOICE_DEBT_CARRY = RolloverBalanceCarry::KIND_DEBT_CARRY;

    public const CHOICE_BLOCK = RolloverBalanceCarry::KIND_BLOCK;

    public function __construct(private readonly CarryForwardStudentCredit $carryCredit) {}

    /**
     * @param  array<int, string>  $debtorChoices  student_id => debt_carry|block
     * @return array{credits_carried: int, credit_total: int, debts_carried: int, blocked: int, skipped: int}
     */
    public function handle(int $runId, array $debtorChoices, Actor $actor, ?string $postingDate = null): array
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($runId, $debtorChoices, $actor, $postingDate): array {
            $run = $this->lockRunAt($runId, RolloverStep::CarryBalances);

            $fromYearId = (int) $run->academic_year_from_id;
            $toYearId = (int) $run->academic_year_to_id;

            $toYearStartsOn = DB::table('academic_years')->where('id', $toYearId)->value('starts_on');

            if (! is_string($toYearStartsOn)) {
                throw new DomainException("Rollover run {$runId}: the new academic year does not exist; run step 1 first.");
            }

            $postingDate ??= Carbon::parse($toYearStartsOn)->toDateString();

            $credits = $this->creditByStudent($fromYearId);
            $debts = $this->outstandingByStudent($fromYearId);

            $this->assertEveryDebtorChosen($debts, $debtorChoices);

            $counts = ['credits_carried' => 0, 'credit_total' => 0, 'debts_carried' => 0, 'blocked' => 0, 'skipped' => 0];

            foreach ($credits as $studentId => $credit) {
                if ($this->carryExists($run, $studentId, RolloverBalanceCarry::KIND_CREDIT_CARRY)) {
                    $counts['skipped']++;

                    continue;
                }

                // ONE student, ONE posting - the Fees door re-verifies the
                // amount under its own lock and refuses a zero.
                $posted = $this->carryCredit->handle(
                    studentId: $studentId,
                    fromAcademicYearId: $fromYearId,
                    toAcademicYearId: $toYearId,
                    postingDate: $postingDate,
                    actor: $actor,
                );

                $carry = RolloverBalanceCarry::query()->create([
                    'rollover_run_id' => (int) $run->getKey(),
                    'student_id' => $studentId,
                    'kind' => RolloverBalanceCarry::KIND_CREDIT_CARRY,
                    'amount' => $posted['amount'],
                    'journal_entry_id' => $posted['journal_entry_id'],
                ]);

                $this->recordArtifact($run, 'rollover_balance_carries', (int) $carry->getKey());

                $counts['credits_carried']++;
                $counts['credit_total'] += $posted['amount'];

                // The credit stays the student's own figure end to end; it is
                // deliberately never accumulated with another student's into
                // a posted amount (C9). credit_total above is REPORTING only.
            }

            foreach ($debts as $studentId => $outstanding) {
                $kind = $debtorChoices[$studentId];

                if ($this->carryExists($run, $studentId, $kind)) {
                    $counts['skipped']++;

                    continue;
                }

                // debt_carry and block post NOTHING: the debt remains on the
                // old year's invoices (§6.2 step 7, verbatim).
                $carry = RolloverBalanceCarry::query()->create([
                    'rollover_run_id' => (int) $run->getKey(),
                    'student_id' => $studentId,
                    'kind' => $kind,
                    'amount' => $outstanding,
                    'journal_entry_id' => null,
                ]);

                $this->recordArtifact($run, 'rollover_balance_carries', (int) $carry->getKey());

                $counts[$kind === self::CHOICE_BLOCK ? 'blocked' : 'debts_carried']++;
            }

            $this->advance($run, RolloverStep::CarryBalances, $counts);

            return $counts;
        });
    }

    /**
     * Unallocated credit per student in the outgoing year - the §12.3 cache,
     * summed per student. Bounced and confirmed-voided payments are excluded,
     * exactly as AgedBalances excludes them. Authoritative re-verification
     * happens per student, under lock, inside CarryForwardStudentCredit.
     *
     * @return array<int, int>
     */
    private function creditByStudent(int $fromYearId): array
    {
        $rows = DB::table('payments as p')
            ->where('p.academic_year_id', $fromYearId)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->groupBy('p.student_id')
            ->select(['p.student_id', DB::raw('CAST(SUM(p.unallocated_amount) AS SIGNED) as credit')])
            ->get();

        $credits = [];

        foreach ($rows as $row) {
            if ((int) $row->credit > 0) {
                $credits[(int) $row->student_id] = (int) $row->credit;
            }
        }

        ksort($credits);

        return $credits;
    }

    /**
     * Outstanding per student on the outgoing year's ISSUED invoices - the §5
     * formula's terms, aggregated: invoiced gross minus live allocations,
     * approved adjustments, issued credit notes and (once the table exists)
     * approved write-offs.
     *
     * @return array<int, int>
     */
    private function outstandingByStudent(int $fromYearId): array
    {
        $outstanding = [];

        $invoiced = DB::table('invoices as i')
            ->join('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->where('i.academic_year_id', $fromYearId)
            ->where('i.status', 'issued')
            ->groupBy('i.student_id')
            ->select(['i.student_id', DB::raw('CAST(SUM(l.amount + l.tax_amount) AS SIGNED) as total')])
            ->get();

        foreach ($invoiced as $row) {
            $outstanding[(int) $row->student_id] = (int) $row->total;
        }

        $allocated = DB::table('payment_allocations as pa')
            ->join('invoices as i', 'i.id', '=', 'pa.invoice_id')
            ->whereNull('pa.reversed_at')
            ->where('i.academic_year_id', $fromYearId)
            ->where('i.status', 'issued')
            ->groupBy('i.student_id')
            ->select(['i.student_id', DB::raw('CAST(SUM(pa.amount) AS SIGNED) as total')])
            ->get();

        foreach ($allocated as $row) {
            $outstanding[(int) $row->student_id] = ($outstanding[(int) $row->student_id] ?? 0) - (int) $row->total;
        }

        if (Schema::hasTable('fee_adjustments')) {
            $adjusted = DB::table('fee_adjustments as fa')
                ->where('fa.status', 'approved')
                ->where('fa.academic_year_id', $fromYearId)
                ->groupBy('fa.student_id')
                ->select(['fa.student_id', DB::raw('CAST(SUM(fa.amount) AS SIGNED) as total')])
                ->get();

            foreach ($adjusted as $row) {
                $outstanding[(int) $row->student_id] = ($outstanding[(int) $row->student_id] ?? 0) - (int) $row->total;
            }
        }

        if (Schema::hasTable('credit_notes') && Schema::hasTable('credit_note_lines')) {
            $credited = DB::table('credit_notes as cn')
                ->join('credit_note_lines as cnl', 'cnl.credit_note_id', '=', 'cn.id')
                ->where('cn.status', 'issued')
                ->where('cn.academic_year_id', $fromYearId)
                ->groupBy('cn.student_id')
                ->select(['cn.student_id', DB::raw('CAST(SUM(cnl.amount + cnl.tax_amount) AS SIGNED) as total')])
                ->get();

            foreach ($credited as $row) {
                $outstanding[(int) $row->student_id] = ($outstanding[(int) $row->student_id] ?? 0) - (int) $row->total;
            }
        }

        if (Schema::hasTable('write_offs') && Schema::hasTable('write_off_lines')) {
            $written = DB::table('write_off_lines as wl')
                ->join('write_offs as w', 'w.id', '=', 'wl.write_off_id')
                ->join('invoice_lines as il', 'il.id', '=', 'wl.invoice_line_id')
                ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
                ->where('w.status', 'approved')
                ->where('i.academic_year_id', $fromYearId)
                ->groupBy('i.student_id')
                ->select(['i.student_id', DB::raw('CAST(SUM(wl.amount) AS SIGNED) as total')])
                ->get();

            foreach ($written as $row) {
                $outstanding[(int) $row->student_id] = ($outstanding[(int) $row->student_id] ?? 0) - (int) $row->total;
            }
        }

        $debts = array_filter($outstanding, static fn (int $net): bool => $net > 0);
        ksort($debts);

        return $debts;
    }

    /**
     * @param  array<int, int>  $debts
     * @param  array<int, string>  $choices
     */
    private function assertEveryDebtorChosen(array $debts, array $choices): void
    {
        $missing = [];
        $refusedWriteOffs = [];
        $invalid = [];

        foreach ($debts as $studentId => $outstanding) {
            $choice = $choices[$studentId] ?? null;

            if ($choice === null) {
                $missing[] = "student {$studentId} ({$outstanding})";

                continue;
            }

            if ($choice === RolloverBalanceCarry::KIND_WRITE_OFF) {
                $refusedWriteOffs[] = (string) $studentId;

                continue;
            }

            if (! in_array($choice, [self::CHOICE_DEBT_CARRY, self::CHOICE_BLOCK], true)) {
                $invalid[] = "student {$studentId}: '{$choice}'";
            }
        }

        if ($refusedWriteOffs !== []) {
            throw new DomainException(
                'A write-off cannot be granted from the rollover wizard: it is a maker-checker workflow (04-fees §8) that must be granted and approved in the Fees module first. Students: '
                .implode(', ', $refusedWriteOffs).'.'
            );
        }

        if ($invalid !== []) {
            throw new DomainException(
                "Unknown balance choice for: ".implode('; ', $invalid).". Expected 'debt_carry' or 'block'."
            );
        }

        if ($missing !== []) {
            throw new DomainException(
                'Students still owing on the outgoing year need a per-student choice (carry as opening debt / block enrolment) before balances can be carried: '
                .implode(', ', $missing).'.'
            );
        }
    }

    private function carryExists(RolloverRun $run, int $studentId, string $kind): bool
    {
        return RolloverBalanceCarry::query()
            ->where('rollover_run_id', (int) $run->getKey())
            ->where('student_id', $studentId)
            ->where('kind', $kind)
            ->exists();
    }

    private function lockRunAt(int $runId, RolloverStep $step): RolloverRun
    {
        /** @var RolloverRun $run */
        $run = RolloverRun::query()->lockForUpdate()->findOrFail($runId);

        if (! $run->status()->isResumable()) {
            throw new DomainException("Rollover run {$runId} is {$run->status} and cannot execute steps.");
        }

        if (! $step->isRunnableAt($run->currentStep())) {
            throw new DomainException(sprintf(
                'Rollover run %d stands at step %d (%s); step %d (%s) is not runnable now - steps execute strictly in order.',
                $runId,
                $run->current_step,
                $run->currentStep()->name,
                $step->value,
                $step->name,
            ));
        }

        if ($run->academic_year_to_id === null) {
            throw new DomainException("Rollover run {$runId} has no destination year yet; run step 1 first.");
        }

        return $run;
    }

    private function recordArtifact(RolloverRun $run, string $entityType, int $entityId): void
    {
        RolloverArtifact::query()->firstOrCreate([
            'rollover_run_id' => (int) $run->getKey(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ], [
            'step' => RolloverStep::CarryBalances->value,
        ]);
    }

    /**
     * @param  array<string, int>  $state
     */
    private function advance(RolloverRun $run, RolloverStep $step, array $state): void
    {
        $states = $run->step_states ?? [];
        $states[(string) $step->value] = $state + ['completed_at' => Carbon::now()->toIso8601String()];

        // Steps 6-9 always have a successor (the flip is step 10); the
        // coalesce is the defensive arm for a hypothetical last step.
        $next = $step->next() ?? $step;

        $run->forceFill([
            'step_states' => $states,
            'current_step' => $next->value,
            'status' => RolloverRunStatus::Running->value,
        ])->save();
    }
}
