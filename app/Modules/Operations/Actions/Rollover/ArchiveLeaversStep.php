<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use App\Modules\Students\Actions\CompleteEnrollment;
use App\Modules\Students\Actions\WithdrawStudent;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/08-operations.md §6.2 step 8 - "Graduates & leavers".
 *
 * Consumes the `graduated` and `withdrawn` promotion decisions:
 *
 *  - graduated -> Students\Actions\CompleteEnrollment (`active -> completed`;
 *    DeriveStudentStatus then reads the exit-level class as `graduated`);
 *  - withdrawn -> Students\Actions\WithdrawStudent.
 *
 * Both are the Students module's own doors - enrolments are ARCHIVED by
 * terminal status, never deleted, and never written directly from here.
 *
 * "A graduate with an unsettled balance is listed, not silently archived":
 * the enrollment still completes (a diploma is a fact, not a payment
 * receipt), but every leaver whose outgoing-year account is not settled is
 * returned in the summary's `unsettled` list - invoices still outstanding or
 * credit not yet carried - so the operator sees exactly who leaves owing or
 * owed. Step 7 ran first (strict order), so a still-visible balance here is
 * one the operator explicitly chose to carry or block on.
 *
 * Idempotent on resume: an enrollment already terminal is skipped and
 * counted, not re-transitioned - re-withdrawing would trip the 3.3 state
 * machine anyway.
 */
final class ArchiveLeaversStep
{
    /** See PromoteStudentsStep::PERMISSION. */
    public const PERMISSION = 'rollover.run';

    public function __construct(
        private readonly CompleteEnrollment $complete,
        private readonly WithdrawStudent $withdraw,
    ) {}

    /**
     * @return array{graduated: int, withdrawn: int, skipped: int, unsettled: list<array{student_id: int, decision: string, outstanding: int, uncarried_credit: int}>}
     */
    public function handle(int $runId, Actor $actor): array
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($runId): array {
            $run = $this->lockRunAt($runId, RolloverStep::ArchiveLeavers);

            $fromYearId = (int) $run->academic_year_from_id;

            $fromYearEndsOn = DB::table('academic_years')->where('id', $fromYearId)->value('ends_on');

            if (! is_string($fromYearEndsOn)) {
                throw new DomainException("Rollover run {$runId}: the outgoing academic year does not exist.");
            }

            $leaveOn = Carbon::parse($fromYearEndsOn)->toDateString();

            /** @var list<object{enrollment_id: int|string, student_id: int|string, status: string, decision: string}> $leavers */
            $leavers = DB::table('enrollments as e')
                ->join('promotion_decisions as pd', 'pd.enrollment_id', '=', 'e.id')
                ->where('e.academic_year_id', $fromYearId)
                ->whereIn('pd.decision', ['graduated', 'withdrawn'])
                ->orderBy('e.id')
                ->get(['e.id as enrollment_id', 'e.student_id', 'e.status', 'pd.decision'])
                ->all();

            $counts = ['graduated' => 0, 'withdrawn' => 0, 'skipped' => 0, 'unsettled' => []];

            foreach ($leavers as $leaver) {
                $enrollmentId = (int) $leaver->enrollment_id;
                $studentId = (int) $leaver->student_id;

                if (in_array($leaver->status, ['withdrawn', 'transferred_out', 'completed', 'cancelled'], true)) {
                    // Already terminal - a resumed run, or the registrar got
                    // there first. Archived means archived; nothing to redo.
                    $counts['skipped']++;
                } elseif ($leaver->decision === 'graduated') {
                    $this->complete->handle($enrollmentId, $leaveOn, $this->actorFor());
                    $counts['graduated']++;
                } else {
                    $this->withdraw->handle(
                        $enrollmentId,
                        $leaveOn,
                        'Year rollover: recorded as a leaver by promotion decision (08-operations §6.2 step 8).',
                    );
                    $counts['withdrawn']++;
                }

                $outstanding = $this->outstandingFor($studentId, $fromYearId);
                $uncarried = $this->uncarriedCreditFor($run, $studentId, $fromYearId);

                if ($outstanding > 0 || $uncarried > 0) {
                    $counts['unsettled'][] = [
                        'student_id' => $studentId,
                        'decision' => $leaver->decision,
                        'outstanding' => $outstanding,
                        'uncarried_credit' => $uncarried,
                    ];
                }
            }

            $this->advance($run, RolloverStep::ArchiveLeavers, [
                'graduated' => $counts['graduated'],
                'withdrawn' => $counts['withdrawn'],
                'skipped' => $counts['skipped'],
                'unsettled_count' => count($counts['unsettled']),
            ]);

            return $counts;
        });
    }

    /**
     * WithdrawStudent and CompleteEnrollment resolve their own audit actor
     * from the authenticated user; CompleteEnrollment takes it explicitly.
     */
    private function actorFor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }

    /**
     * Gross invoiced minus live allocations on the outgoing year's issued
     * invoices - enough to answer "does this leaver still owe?".
     */
    private function outstandingFor(int $studentId, int $fromYearId): int
    {
        $invoiced = (int) DB::table('invoices as i')
            ->join('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->where('i.student_id', $studentId)
            ->where('i.academic_year_id', $fromYearId)
            ->where('i.status', 'issued')
            ->sum(DB::raw('l.amount + l.tax_amount'));

        $allocated = (int) DB::table('payment_allocations as pa')
            ->join('invoices as i', 'i.id', '=', 'pa.invoice_id')
            ->whereNull('pa.reversed_at')
            ->where('i.student_id', $studentId)
            ->where('i.academic_year_id', $fromYearId)
            ->where('i.status', 'issued')
            ->sum('pa.amount');

        return max(0, $invoiced - $allocated);
    }

    /**
     * Unallocated credit the step-7 run did NOT carry for this student - a
     * leaver's uncarried credit is money the school owes a family that is
     * leaving, which is exactly what must never be archived silently.
     */
    private function uncarriedCreditFor(RolloverRun $run, int $studentId, int $fromYearId): int
    {
        $credit = (int) DB::table('payments as p')
            ->where('p.student_id', $studentId)
            ->where('p.academic_year_id', $fromYearId)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->sum('p.unallocated_amount');

        if ($credit <= 0) {
            return 0;
        }

        $carried = DB::table('rollover_balance_carries')
            ->where('rollover_run_id', (int) $run->getKey())
            ->where('student_id', $studentId)
            ->where('kind', 'credit_carry')
            ->exists();

        return $carried ? 0 : $credit;
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
