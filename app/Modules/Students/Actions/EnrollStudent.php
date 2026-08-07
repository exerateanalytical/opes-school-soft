<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\EnrollmentType;
use App\Modules\Students\Domain\SegmentReason;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use App\Support\Audit\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 4 and 5.2.
 *
 * Creates the one Enrollment for a (student, year) plus the `initial` segment
 * whose `starts_on` equals `enrolled_on`. Both in one transaction: an
 * enrollment with no segment would be a student who belongs to no class group,
 * and 9's attendance denominator resolves a date to a class group through the
 * segment table, so a segment-less enrollment is not merely untidy - it is
 * invisible to attendance.
 *
 * No App\Modules\Academics\Models class is used. The class group and academic
 * year are read through the query builder, because
 * tests/Architecture/ModuleBoundaryTest.php forbids this module from touching
 * another module's Models and states that the rule has no exceptions.
 */
final class EnrollStudent
{
    public function handle(
        int $studentId,
        int $academicYearId,
        int $classGroupId,
        string $enrolledOn,
        EnrollmentType $enrollmentType = EnrollmentType::New,
        bool $isRepeat = false,
        string $boardingStatus = 'day',
        ?int $rollNumber = null,
        bool $capacityOverride = false,
    ): Enrollment {
        Gate::authorize(Permission::StudentsManage->value);

        return DB::transaction(function () use (
            $studentId, $academicYearId, $classGroupId, $enrolledOn,
            $enrollmentType, $isRepeat, $boardingStatus, $rollNumber, $capacityOverride
        ): Enrollment {
            // 4.2 invariant 7: capacity is checked under SELECT ... FOR UPDATE
            // on the ClassGroup row (00-core 10.2), so two concurrent enrolments
            // into the last free seat serialise instead of both succeeding.
            $classGroup = DB::table('class_groups')
                ->where('id', $classGroupId)
                ->lockForUpdate()
                ->first();

            if ($classGroup === null) {
                throw ValidationException::withMessages([
                    'class_group_id' => 'The selected class group does not exist.',
                ]);
            }

            /** @var array<string, mixed> $group */
            $group = (array) $classGroup;

            if ((int) $group['academic_year_id'] !== $academicYearId) {
                // A segment pointing at another year's group would make every
                // date-to-class-group lookup in 5.3 and 9 answer with a group
                // that does not exist in the year being reported on.
                throw ValidationException::withMessages([
                    'class_group_id' => 'The class group belongs to a different academic year.',
                ]);
            }

            $year = DB::table('academic_years')->where('id', $academicYearId)->first();

            if ($year === null) {
                throw ValidationException::withMessages([
                    'academic_year_id' => 'The selected academic year does not exist.',
                ]);
            }

            /** @var array<string, mixed> $yearRow */
            $yearRow = (array) $year;

            // 4.2 invariant 1. Not expressible as a MySQL 8 CHECK across
            // tables, so it is enforced here (and by the nightly job).
            if ($enrolledOn < (string) $yearRow['starts_on'] || $enrolledOn > (string) $yearRow['ends_on']) {
                throw ValidationException::withMessages([
                    'enrolled_on' => 'The enrolment date falls outside the academic year.',
                ]);
            }

            $this->assertCapacity($classGroupId, (int) $group['capacity'], $capacityOverride);

            $classLevelId = (int) $group['class_level_id'];
            $sectionId = DB::table('class_levels')->where('id', $classLevelId)->value('school_section_id');

            if (! is_numeric($sectionId)) {
                throw ValidationException::withMessages([
                    'class_group_id' => 'The class group is not attached to a valid class level.',
                ]);
            }

            $actor = $this->currentActor();

            try {
                $enrollment = Enrollment::query()->create([
                    'student_id' => $studentId,
                    'academic_year_id' => $academicYearId,
                    'class_level_id' => $classLevelId,
                    // 4.1: the stream drives the elective basket and the
                    // ranking cohort, and is inherited from the group entered.
                    'stream_id' => $group['stream_id'] === null ? null : (int) $group['stream_id'],
                    // 4.1: denormalised from the class level and FROZEN here.
                    'school_section_id' => (int) $sectionId,
                    'status' => EnrollmentStatus::Active,
                    'is_repeat' => $isRepeat,
                    'enrollment_type' => $enrollmentType,
                    'enrolled_on' => $enrolledOn,
                    'boarding_status' => $boardingStatus,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // C1 (4.3). Either uq_enrollment_active_year or
                // uq_enrollment_student_year fired. The spec's acceptance
                // criterion is explicit that the loser of the race must see
                // "already enrolled for 2026/2027", not a 500.
                throw ValidationException::withMessages([
                    'student_id' => 'This student is already enrolled for '
                        .(string) $yearRow['code'].'.',
                ]);
            }

            // 5.2: "The first segment's starts_on = Enrollment.enrolled_on;
            // reason = 'initial'." Open-ended, so its union with any later
            // segment covers [enrolled_on, left_on ?? year end] by construction.
            EnrollmentSegment::query()->create([
                'enrollment_id' => (int) $enrollment->getKey(),
                'class_group_id' => $classGroupId,
                'starts_on' => $enrolledOn,
                'ends_on' => null,
                'roll_number' => $rollNumber,
                'reason' => SegmentReason::Initial,
                'capacity_override' => $capacityOverride,
                'created_by' => $actor->id,
            ]);

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Students',
                auditableType: Enrollment::class,
                auditableId: (int) $enrollment->getKey(),
                after: [
                    'student_id' => $studentId,
                    'academic_year_id' => $academicYearId,
                    'class_group_id' => $classGroupId,
                    'class_level_id' => $classLevelId,
                    'enrolled_on' => $enrolledOn,
                    'status' => EnrollmentStatus::Active->value,
                    'enrollment_type' => $enrollmentType->value,
                ],
                actor: $actor,
            );

            // 3.2: the derivation runs inside every Action that writes an
            // Enrollment status, never as an afterthought on a schedule.
            app(DeriveStudentStatus::class)->handle($studentId);

            return $enrollment;
        });
    }

    /**
     * Counts the students the group currently holds, which under 5 means the
     * OPEN segments pointing at it - `class_group_id` no longer lives on
     * Enrollment (4.3), so the roll of a class is a segment question.
     */
    private function assertCapacity(int $classGroupId, int $capacity, bool $override): void
    {
        $occupied = DB::table('enrollment_segments')
            ->where('class_group_id', $classGroupId)
            ->whereNull('ends_on')
            ->count();

        if ($occupied >= $capacity && ! $override) {
            throw ValidationException::withMessages([
                'class_group_id' => "This class group is full ({$occupied}/{$capacity}); "
                    .'an explicit capacity override is required.',
            ]);
        }
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
