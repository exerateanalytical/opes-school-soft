<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Assessment\Actions\GetAnnualAveragesForEnrollments;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\EnrollmentType;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Domain\SegmentReason;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\PromotionRun;
use App\Modules\Students\Support\PromotionInputsHasher;
use App\Support\Audit\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md §10.6, steps 1–8 verbatim — one transaction,
 * `FOR UPDATE` on the run.
 *
 *  1. Re-validate `inputs_hash`. REFUSE on drift, naming the enrollments
 *     whose inputs changed — never silently re-evaluate: the principal
 *     signed off on a list, and applying a different list is the defect.
 *  2. Conditional `UPDATE … WHERE status` allows apply; 0 rows ⇒ abort.
 *  3. Refuse while any decision is undecided (indeterminate under 'block',
 *     or manual_review awaiting its override).
 *  4. Close the outgoing segment (ends_on = year.ends_on), complete the
 *     enrollment (left_on = year.ends_on), transition row via
 *     DeriveStudentStatus.
 *  5. Create the next-year Enrollment (+ initial segment when a target group
 *     is chosen; `pending` with NO segment when the group is deferred to the
 *     rollover wizard). `is_repeat` = outcome repeat; type `returning`.
 *  6. `graduate` creates no enrollment; §3.2's derivation drives
 *     Student.status to graduated.
 *  7. The Enrollment UNIQUE (§4.3) is the backstop: a replayed apply hits the
 *     constraint and rolls back rather than double-enrolling.
 *  8. `student.promoted` / `student.repeated` after commit.
 *
 * Cancelling an applied run is NOT supported (§10.6 closing paragraph).
 */
final class ApplyPromotionRun
{
    public const PERMISSION = Permission::PromotionApply->value;

    public function __construct(
        private readonly GetAnnualAveragesForEnrollments $annualAverages,
        private readonly PromotionInputsHasher $hasher,
        private readonly DeriveStudentStatus $deriveStatus,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $promotionRunId, ?Actor $actor = null): PromotionRun
    {
        Gate::authorize(self::PERMISSION);

        $writer = $actor ?? $this->currentActor();

        try {
            $run = DB::transaction(function () use ($promotionRunId, $writer): PromotionRun {
                /** @var PromotionRun $run */
                $run = PromotionRun::query()->lockForUpdate()->findOrFail($promotionRunId);

                /** @var list<PromotionDecision> $decisions */
                $decisions = PromotionDecision::query()
                    ->where('promotion_run_id', $promotionRunId)
                    ->lockForUpdate()
                    ->orderBy('enrollment_id')
                    ->get()
                    ->all();

                if ($decisions === []) {
                    throw ValidationException::withMessages([
                        'promotion_run_id' => 'The run has no decisions to apply; evaluate it first.',
                    ]);
                }

                // ---- Step 1: hash re-validation ---------------------------
                $this->assertInputsUnchanged($run, $decisions);

                // ---- Step 2: conditional status flip ----------------------
                $flipped = DB::table('promotion_runs')
                    ->where('id', (int) $run->getKey())
                    ->whereIn('status', [
                        PromotionRunStatus::Evaluated->value,
                        PromotionRunStatus::UnderReview->value,
                    ])
                    ->update([
                        'status' => PromotionRunStatus::Applying->value,
                        'updated_at' => now(),
                    ]);

                if ($flipped === 0) {
                    throw ValidationException::withMessages([
                        'promotion_run_id' => "A {$run->status->value} run cannot be applied.",
                    ]);
                }

                // ---- Step 3: undecided rows block -------------------------
                $this->assertAllDecided($run, $decisions);

                $year = $this->year($run->academic_year_id);
                $targetYear = $this->year($run->target_academic_year_id);

                $applied = ['promoted' => [], 'repeated' => [], 'graduated' => [], 'excluded' => []];

                foreach ($decisions as $decision) {
                    $this->applyOne($decision, $run, $year, $targetYear, $writer, $applied);
                }

                $run->forceFill([
                    'status' => PromotionRunStatus::Applied,
                    'applied_at' => Carbon::now(),
                    'applied_by' => $writer->id,
                ])->save();

                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Students',
                    auditableType: PromotionRun::class,
                    auditableId: (int) $run->getKey(),
                    after: [
                        'status' => PromotionRunStatus::Applied->value,
                        'promoted' => count($applied['promoted']),
                        'repeated' => count($applied['repeated']),
                        'graduated' => count($applied['graduated']),
                        'excluded' => count($applied['excluded']),
                    ],
                    actor: $writer,
                );

                // ---- Step 8: events AFTER COMMIT --------------------------
                DB::afterCommit(function () use ($applied, $run): void {
                    foreach ($applied['promoted'] as $studentId) {
                        Event::dispatch('student.promoted', [[
                            'student_id' => $studentId,
                            'promotion_run_id' => (int) $run->getKey(),
                        ]]);
                    }

                    foreach ($applied['repeated'] as $studentId) {
                        Event::dispatch('student.repeated', [[
                            'student_id' => $studentId,
                            'promotion_run_id' => (int) $run->getKey(),
                        ]]);
                    }
                });

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            // ---- Step 7: the §4.3 backstop -------------------------------
            throw ValidationException::withMessages([
                'promotion_run_id' => 'A student in this run already holds a live enrollment in the '
                    .'target year; the replayed apply hit the enrollment uniqueness backstop and '
                    .'rolled back instead of double-enrolling.',
            ]);
        }

        return $run->refresh();
    }

    /**
     * §10.3's closing rule: recompute, compare, and on mismatch NAME the
     * drifted enrollments (their stored per-enrollment fingerprints are in
     * criteria_results) and offer re-evaluation.
     *
     * @param  list<PromotionDecision>  $decisions
     */
    private function assertInputsUnchanged(PromotionRun $run, array $decisions): void
    {
        $enrollmentIds = array_map(
            static fn (PromotionDecision $decision): int => $decision->enrollment_id,
            $decisions,
        );

        $set = $run->criteriaSet;

        if ($set === null) {
            throw ValidationException::withMessages([
                'promotion_run_id' => 'The run has lost its criteria set; re-evaluate.',
            ]);
        }

        $annual = $this->annualAverages->handle($run->academic_year_id, $enrollmentIds);

        $recomputed = $this->hasher->handle(
            $run->academic_year_id,
            $run->class_group_id,
            (int) $set->getKey(),
            $set->version,
            $enrollmentIds,
            $annual,
        );

        if ($run->inputs_hash === $recomputed['hash']) {
            return;
        }

        $drifted = [];

        foreach ($decisions as $decision) {
            $results = $decision->criteria_results ?? [];
            $stored = $results['inputs_fingerprint'] ?? null;
            $current = $recomputed['fingerprints'][$decision->enrollment_id] ?? null;

            if (! is_string($stored) || $stored !== $current) {
                $drifted[] = $decision->enrollment_id;
            }
        }

        $names = $drifted === [] ? 'the run-level inputs (publications or criteria set)' : 'enrollment(s) '.implode(', ', $drifted);

        throw ValidationException::withMessages([
            'inputs_hash' => "The inputs of this evaluation have changed since the principal reviewed it: {$names}. "
                .'Applying a different list than the one signed off is the defect this hash exists to stop — '
                .'re-evaluate the run and review it again.',
        ]);
    }

    /**
     * @param  list<PromotionDecision>  $decisions
     */
    private function assertAllDecided(PromotionRun $run, array $decisions): void
    {
        $undecided = [];

        foreach ($decisions as $decision) {
            if ($decision->outcome === null || $decision->outcome->isUndecided()) {
                $undecided[] = $decision->enrollment_id;
            }
        }

        if ($undecided === []) {
            return;
        }

        $list = implode(', ', $undecided);

        if ($run->on_indeterminate === PromotionRun::ON_INDETERMINATE_BLOCK) {
            throw ValidationException::withMessages([
                'promotion_run_id' => "Apply refused: enrollment(s) {$list} are indeterminate and this run's "
                    ."on_indeterminate policy is 'block' (§10.6 step 3). Fix the missing inputs and re-evaluate, "
                    .'or override each decision on the record.',
            ]);
        }

        throw ValidationException::withMessages([
            'promotion_run_id' => "Apply refused: enrollment(s) {$list} are still awaiting manual review; "
                .'override each with a decisive outcome first.',
        ]);
    }

    /**
     * Steps 4–6 for one decision.
     *
     * @param  array<string, mixed>  $year
     * @param  array<string, mixed>  $targetYear
     * @param  array{promoted: list<int>, repeated: list<int>, graduated: list<int>, excluded: list<int>}  $applied
     */
    private function applyOne(
        PromotionDecision $decision,
        PromotionRun $run,
        array $year,
        array $targetYear,
        Actor $writer,
        array &$applied,
    ): void {
        /** @var PromotionOutcome $outcome */
        $outcome = $decision->outcome ?? throw ValidationException::withMessages([
            'promotion_run_id' => "Decision {$decision->getKey()} lost its outcome mid-apply.",
        ]);

        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($decision->enrollment_id);

        $yearEndsOn = (string) $year['ends_on'];
        $statusBefore = $enrollment->status;

        // ---- Step 4: close the year out ------------------------------
        if ($enrollment->status->isLive()) {
            EnrollmentSegment::query()
                ->where('enrollment_id', (int) $enrollment->getKey())
                ->whereNull('ends_on')
                ->update(['ends_on' => $yearEndsOn, 'updated_at' => now()]);

            // The year-end completion of §10.6 step 4 — written directly
            // rather than through the operator transition graph, because
            // completing the year is the one transition the graph reserves
            // for this Action.
            $enrollment->forceFill([
                'status' => EnrollmentStatus::Completed,
                'left_on' => $yearEndsOn,
                'updated_by' => $writer->id,
            ])->save();
        }

        $newEnrollmentId = null;

        // ---- Step 5: the next-year enrollment ------------------------
        if ($outcome->createsNextYearEnrollment() && $decision->applied_enrollment_id === null) {
            $newEnrollmentId = $this->createNextYearEnrollment($decision, $outcome, $run, $targetYear, $writer);

            $decision->forceFill(['applied_enrollment_id' => $newEnrollmentId])->save();
        }

        // ---- Step 6 (and the §3.2 derivation for every path) ---------
        $this->deriveStatus->handle($enrollment->student_id);

        $bucket = match ($outcome) {
            PromotionOutcome::Promote, PromotionOutcome::ConditionalPromote => 'promoted',
            PromotionOutcome::Repeat => 'repeated',
            PromotionOutcome::Graduate => 'graduated',
            default => 'excluded',
        };

        $applied[$bucket][] = $enrollment->student_id;

        $this->audit->handle(
            action: AuditAction::Updated,
            module: 'Students',
            auditableType: Enrollment::class,
            auditableId: (int) $enrollment->getKey(),
            before: ['status' => $statusBefore->value],
            after: [
                'status' => EnrollmentStatus::Completed->value,
                'left_on' => $yearEndsOn,
                'outcome' => $outcome->value,
                'applied_enrollment_id' => $newEnrollmentId,
                'promotion_run_id' => (int) $run->getKey(),
            ],
            actor: $writer,
        );
    }

    /**
     * @param  array<string, mixed>  $targetYear
     */
    private function createNextYearEnrollment(
        PromotionDecision $decision,
        PromotionOutcome $outcome,
        PromotionRun $run,
        array $targetYear,
        Actor $writer,
    ): int {
        $sourceLevelId = (int) DB::table('enrollments')
            ->where('id', $decision->enrollment_id)
            ->value('class_level_id');

        $levelId = $outcome === PromotionOutcome::Repeat
            ? $sourceLevelId
            : $decision->target_class_level_id;

        if ($levelId === null) {
            throw ValidationException::withMessages([
                'target_class_level_id' => "Decision for enrollment {$decision->enrollment_id} has no "
                    .'target class level; override it with an explicit destination.',
            ]);
        }

        $sectionId = DB::table('class_levels')->where('id', $levelId)->value('school_section_id');

        if (! is_numeric($sectionId)) {
            throw ValidationException::withMessages([
                'target_class_level_id' => 'The target class level is not attached to a school section.',
            ]);
        }

        $studentId = (int) DB::table('enrollments')
            ->where('id', $decision->enrollment_id)
            ->value('student_id');

        $groupId = $decision->target_class_group_id;
        $startsOn = (string) $targetYear['starts_on'];

        if ($groupId !== null) {
            $group = DB::table('class_groups')->where('id', $groupId)->lockForUpdate()->first();

            if ($group === null || (int) $group->academic_year_id !== $run->target_academic_year_id) {
                throw ValidationException::withMessages([
                    'target_class_group_id' => 'The target class group does not exist in the target year.',
                ]);
            }
        }

        $enrollment = Enrollment::query()->create([
            'student_id' => $studentId,
            'academic_year_id' => $run->target_academic_year_id,
            'class_level_id' => $levelId,
            'stream_id' => null,
            'school_section_id' => (int) $sectionId,
            // §10.6 step 5: a deferred group leaves the enrollment `pending`
            // until the rollover wizard sets one; a chosen group activates it.
            'status' => $groupId === null ? EnrollmentStatus::Pending : EnrollmentStatus::Active,
            'is_repeat' => $outcome === PromotionOutcome::Repeat,
            'enrollment_type' => EnrollmentType::Returning,
            'enrolled_on' => $startsOn,
            'boarding_status' => 'day',
            'created_by' => $writer->id,
            'updated_by' => $writer->id,
        ]);

        if ($groupId !== null) {
            EnrollmentSegment::query()->create([
                'enrollment_id' => (int) $enrollment->getKey(),
                'class_group_id' => $groupId,
                'starts_on' => $startsOn,
                'ends_on' => null,
                'reason' => SegmentReason::Initial,
                'created_by' => $writer->id,
            ]);
        }

        return (int) $enrollment->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function year(int $academicYearId): array
    {
        $row = DB::table('academic_years')->where('id', $academicYearId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'The academic year does not exist.',
            ]);
        }

        return (array) $row;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
