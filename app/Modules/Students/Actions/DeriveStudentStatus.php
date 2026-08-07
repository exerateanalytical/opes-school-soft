<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * docs/specs/07-students.md 3.2 - the derivation rule, and nothing else.
 *
 * "Student.status is a derived cache of the student's enrollment history,
 *  recomputed by one Action and never hand-edited."
 *
 * v1 had two overlapping lifecycles with no stated relationship, and they
 * diverged within a term. The fix is not a better UI: it is making one of the
 * two a pure function of the other. `derive()` is that function - it reads,
 * decides, and returns; it writes nothing. `handle()` is the thin persistence
 * wrapper the enrollment Actions call.
 *
 * The spec names this Action `RecomputeStudentStatus` in its 12 table; the
 * Phase 2 work split names it `DeriveStudentStatus`. Same function.
 */
final class DeriveStudentStatus
{
    /**
     * Derive and persist. Returns the derived Student.status value.
     *
     * No Gate check here on purpose: this is not an operator-initiated change
     * of state, it is the maintenance of a cache that must always agree with
     * data the caller was already authorised to write. Gating it would mean an
     * authorised WithdrawStudent could leave the cache stale.
     */
    public function handle(int $studentId): StudentStatus
    {
        $derived = $this->derive($studentId);

        return DB::transaction(function () use ($studentId, $derived): StudentStatus {
            $student = Student::query()->findOrFail($studentId);
            $previous = $student->status;

            if ($previous === $derived) {
                return $derived;
            }

            // Student::applyDerivedStatus is the model's single guarded writer;
            // a plain `$student->status = ...` throws. Going through it is the
            // whole point of 3.2's "recomputed by one Action and never
            // hand-edited".
            $student->applyDerivedStatus($derived);

            // 3.3: append-only, and the model's docblock is explicit that the
            // caller writes it in the SAME transaction. Written through the
            // query builder because StudentStatusTransition has no model on
            // disk in this workstream's ownership.
            $actor = $this->currentActor();

            DB::table('student_status_transitions')->insert([
                'student_id' => $studentId,
                'from_status' => $previous->value,
                'to_status' => $derived->value,
                'effective_on' => BusinessDate::today(),
                'reason_code' => 'enrollment_derived',
                'reason_text' => 'Recomputed from enrollment history (07-students 3.2).',
                'actor_id' => $actor->id,
                'actor_name_at_time' => $actor->name,
                'created_at' => now(),
            ]);

            return $derived;
        });
    }

    /**
     * The pure function. Evaluated in the order of the 3.2 table; first match
     * wins. Reads only - callers that want it persisted use handle().
     */
    public function derive(int $studentId): StudentStatus
    {
        $student = DB::table('students')->where('id', $studentId)->first();

        if ($student === null) {
            throw new RuntimeException("Cannot derive a status for unknown student {$studentId}.");
        }

        /** @var array<string, mixed> $studentRow */
        $studentRow = (array) $student;

        // 1. deceased_on is set.
        if (($studentRow['deceased_on'] ?? null) !== null) {
            return StudentStatus::Deceased;
        }

        $currentYearId = DB::table('academic_years')->where('is_current', true)->value('id');
        $currentYearId = is_numeric($currentYearId) ? (int) $currentYearId : null;

        // `is_exam_class` is this schema's name for the spec's `is_exit_level`
        // (migration 200004): the terminal class of a section is the one that
        // sits an external exam. Joined rather than related, so no
        // App\Modules\Academics\Models class is used - see
        // tests/Architecture/ModuleBoundaryTest.php.
        $rows = DB::table('enrollments as e')
            ->join('class_levels as cl', 'cl.id', '=', 'e.class_level_id')
            ->where('e.student_id', $studentId)
            ->orderBy('e.id')
            ->get(['e.id', 'e.academic_year_id', 'e.status', 'e.left_on', 'cl.is_exam_class']);

        /** @var list<array<string, mixed>> $enrollments */
        $enrollments = $rows->map(static fn (object $row): array => (array) $row)->all();

        if ($enrollments === []) {
            // 8. No enrollment at all.
            return StudentStatus::Prospective;
        }

        $inCurrentYear = array_values(array_filter(
            $enrollments,
            static fn (array $e): bool => $currentYearId !== null
                && (int) $e['academic_year_id'] === $currentYearId,
        ));

        // 2. An enrollment in the current year with status active or suspended.
        foreach ($inCurrentYear as $enrollment) {
            if (in_array($enrollment['status'], ['active', 'suspended'], true)) {
                return StudentStatus::Active;
            }
        }

        // 3. An enrollment in the current year with status pending.
        foreach ($inCurrentYear as $enrollment) {
            if ($enrollment['status'] === 'pending') {
                return StudentStatus::Prospective;
            }
        }

        $latestTerminal = $this->latestTerminal($enrollments);

        if ($latestTerminal !== null) {
            // 4. Latest terminal enrollment is completed AND its class level
            //    is an exit level.
            if ($latestTerminal['status'] === 'completed' && (bool) $latestTerminal['is_exam_class']) {
                return StudentStatus::Graduated;
            }

            // 5 and 6.
            if ($latestTerminal['status'] === 'transferred_out') {
                return StudentStatus::TransferredOut;
            }

            if ($latestTerminal['status'] === 'withdrawn') {
                return StudentStatus::Withdrawn;
            }
        }

        // 7. No enrollment in the current year but at least one historical
        //    enrollment.
        //
        // The 3.2 table has no row for `cancelled`, and 3.3 says why: a
        // cancelled enrollment is one created in error before the student ever
        // attended. It is therefore not history. A student whose ONLY
        // enrollments are cancelled has never been enrolled and stays
        // `prospective`; anyone with a real enrollment behind them and none in
        // the current year is `inactive`. This is the one place the table is
        // silent and the reading is stated rather than assumed.
        $hasRealEnrollment = array_filter(
            $enrollments,
            static fn (array $e): bool => $e['status'] !== EnrollmentStatus::Cancelled->value,
        ) !== [];

        return $hasRealEnrollment ? StudentStatus::Inactive : StudentStatus::Prospective;
    }

    /**
     * "Latest terminal enrollment" - ordered by the day it ended, with the row
     * id as the tie-break so two enrollments closed on the same date resolve
     * deterministically rather than by whatever order MySQL returns.
     *
     * @param  list<array<string, mixed>>  $enrollments
     * @return array<string, mixed>|null
     */
    private function latestTerminal(array $enrollments): ?array
    {
        $terminal = array_values(array_filter($enrollments, static function (array $e): bool {
            $status = EnrollmentStatus::tryFrom((string) $e['status']);

            return $status !== null && $status->isTerminal();
        }));

        usort($terminal, static function (array $a, array $b): int {
            $left = strcmp((string) ($b['left_on'] ?? ''), (string) ($a['left_on'] ?? ''));

            return $left !== 0 ? $left : ((int) $b['id'] <=> (int) $a['id']);
        });

        return $terminal[0] ?? null;
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
