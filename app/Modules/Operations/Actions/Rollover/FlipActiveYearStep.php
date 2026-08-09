<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Academics\Actions\SetAcademicYearStatus;
use App\Modules\Academics\Actions\SetCurrentAcademicYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Wizard step 10 (docs/specs/08-operations.md §6.2): flip the active year.
 *
 * The is_current move is DELEGATED to
 * Academics\Actions\SetCurrentAcademicYear - "two is_current years is
 * impossible by constraint" is that Action's (and the schema's) guarantee,
 * not re-implemented here. The new year is activated, and the outgoing year
 * moves to `closed` ONLY when its last reporting period is closed and every
 * student still owing has an explicit step-7 outcome recorded
 * (rollover_balance_carries) - otherwise it stays as it is and the blockers
 * are recorded in step_states for the wizard to display.
 *
 * The pre-flip state (previous current year, both statuses) is recorded in
 * step_states so UndoRollover can restore it exactly.
 */
final class FlipActiveYearStep
{
    public function __construct(
        private readonly SetCurrentAcademicYear $setCurrent,
        private readonly SetAcademicYearStatus $setStatus,
        private readonly WriteAuditEntry $audit,
    ) {
    }

    public function handle(RolloverRun $run, Actor $actor): RolloverRun
    {
        Gate::authorize(StartRolloverRun::PERMISSION);
        RolloverStepMechanics::assertRunnable($run, RolloverStep::FlipActiveYear);

        $toYearId = RolloverStepMechanics::targetYearId($run);
        $from = RolloverStepMechanics::yearRow($run->academic_year_from_id);
        $to = RolloverStepMechanics::yearRow($toYearId);

        $blockers = $this->closeBlockers($run);

        $previousCurrentId = DB::table('academic_years')->where('is_current', true)->value('id');

        DB::transaction(function () use ($run, $toYearId, $from, $to, $blockers, $previousCurrentId, $actor): void {
            $this->setCurrent->handle($toYearId, $actor);

            if ((string) $to->status === 'planned') {
                $this->setStatus->handle($toYearId, 'active', $actor);
            }

            $closedOutgoing = false;

            if ($blockers === [] && (string) $from->status !== 'closed') {
                $this->setStatus->handle($run->academic_year_from_id, 'closed', $actor);
                $closedOutgoing = true;
            }

            RolloverStepMechanics::completeStep($run, RolloverStep::FlipActiveYear, [
                'previous_current_year_id' => $previousCurrentId === null ? null : (int) $previousCurrentId,
                'from_status_before' => (string) $from->status,
                'to_status_before' => (string) $to->status,
                'outgoing_closed' => $closedOutgoing,
                'close_blockers' => $blockers,
            ]);

            $run->forceFill(['status' => RolloverRunStatus::Completed->value])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Operations',
                auditableType: RolloverRun::class,
                auditableId: (int) $run->getKey(),
                before: ['current_year' => (string) $from->code],
                after: [
                    'current_year' => (string) $to->code,
                    'outgoing_closed' => $closedOutgoing,
                    'status' => RolloverRunStatus::Completed->value,
                ],
                actor: $actor,
            );
        });

        return $run->refresh();
    }

    /**
     * §6.2 step 10 guard: the outgoing year closes only when its last period
     * is published (closed) and its fees are settled or explicitly carried.
     * Returns the blockers in plain language; empty means eligible.
     *
     * @return list<string>
     */
    private function closeBlockers(RolloverRun $run): array
    {
        $blockers = [];

        $openPeriods = DB::table('assessment_periods')
            ->where('academic_year_id', $run->academic_year_from_id)
            ->where('is_reporting_period', true)
            ->where('status', '!=', 'closed')
            ->orderBy('order_index')
            ->pluck('code');

        if ($openPeriods->isNotEmpty()) {
            $blockers[] = sprintf('reporting periods not closed: %s', $openPeriods->implode(', '));
        }

        foreach ($this->studentsOwingWithoutOutcome($run) as $studentId) {
            $blockers[] = sprintf('student %d still owes with no carry/write-off/block decision', $studentId);
        }

        return $blockers;
    }

    /**
     * Students with a positive outstanding balance on the outgoing year's
     * ISSUED invoices (Σ line amounts + tax − Σ unreversed allocations) who
     * have no step-7 outcome row in this run. Per-student, never netted
     * across students (04-fees C9).
     *
     * @return list<int>
     */
    private function studentsOwingWithoutOutcome(RolloverRun $run): array
    {
        $invoiced = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoices.academic_year_id', $run->academic_year_from_id)
            ->where('invoices.status', 'issued')
            ->groupBy('invoices.student_id')
            ->selectRaw('invoices.student_id AS student_id, SUM(invoice_lines.amount + invoice_lines.tax_amount) AS billed')
            ->pluck('billed', 'student_id');

        if ($invoiced->isEmpty()) {
            return [];
        }

        $allocated = DB::table('payment_allocations')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->where('invoices.academic_year_id', $run->academic_year_from_id)
            ->where('invoices.status', 'issued')
            ->whereNull('payment_allocations.reversed_at')
            ->groupBy('invoices.student_id')
            ->selectRaw('invoices.student_id AS student_id, SUM(payment_allocations.amount) AS paid')
            ->pluck('paid', 'student_id');

        $covered = DB::table('rollover_balance_carries')
            ->where('rollover_run_id', (int) $run->getKey())
            ->pluck('student_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $owing = [];

        foreach ($invoiced as $studentId => $billed) {
            $outstanding = (int) $billed - (int) ($allocated[$studentId] ?? 0);

            if ($outstanding > 0 && ! in_array((int) $studentId, $covered, true)) {
                $owing[] = (int) $studentId;
            }
        }

        sort($owing);

        return $owing;
    }
}
