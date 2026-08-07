<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\SegmentReason;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * C2 (docs/specs/07-students.md 5) - the mid-year class transfer.
 *
 * The defect this fixes: in v1 a transfer created a SECOND Enrollment, and
 * `Mark` keys on `enrollment_id`, so every mark earned before the transfer
 * vanished from the term average and the old class's mean was still computed
 * over a student who had left. 5's worked example puts the damage at a Maths
 * average of 16 instead of 15.
 *
 * The fix is that a transfer touches segments only: close the open one the day
 * BEFORE the effective date, open a new one ON it. One Enrollment survives, so
 * every mark, invoice and attendance row stays attached to it, and 5.3's
 * "the segment covering P.ends_on owns rank and statistics" resolves the
 * student into exactly one class per period.
 */
final class TransferStudentClass
{
    public function handle(
        int $enrollmentId,
        int $newClassGroupId,
        string $effectiveOn,
        SegmentReason $reason = SegmentReason::ClassTransfer,
        ?string $reasonText = null,
        ?int $rollNumber = null,
        bool $capacityOverride = false,
    ): EnrollmentSegment {
        Gate::authorize(Permission::StudentsManage->value);

        if ($reason === SegmentReason::Initial) {
            // 5.2: the first segment's reason is `initial` and it is created
            // with the enrollment. A second one would make "which segment
            // started this enrollment" ambiguous.
            throw ValidationException::withMessages([
                'reason' => 'Only EnrollStudent may create an `initial` segment.',
            ]);
        }

        if ($reason->requiresReasonText() && trim((string) $reasonText) === '') {
            throw ValidationException::withMessages([
                'reason_text' => 'A correction must say what is being corrected.',
            ]);
        }

        return DB::transaction(function () use (
            $enrollmentId, $newClassGroupId, $effectiveOn, $reason,
            $reasonText, $rollNumber, $capacityOverride
        ): EnrollmentSegment {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollmentId);

            if (! $enrollment->status->isLive()) {
                throw ValidationException::withMessages([
                    'enrollment_id' => 'A '.$enrollment->status->value
                        .' enrollment has no open segment to transfer out of.',
                ]);
            }

            $target = $this->lockedTarget($newClassGroupId, $enrollment, $capacityOverride);

            $open = EnrollmentSegment::query()
                ->where('enrollment_id', $enrollmentId)
                ->whereNull('ends_on')
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                // uq_segment_open guarantees at most one; 5.2's coverage rule
                // guarantees at least one while the enrollment is live. Getting
                // here means the invariant is already broken upstream.
                throw ValidationException::withMessages([
                    'enrollment_id' => 'This enrollment has no open segment; its history is incomplete.',
                ]);
            }

            if ($open->class_group_id === $newClassGroupId) {
                throw ValidationException::withMessages([
                    'class_group_id' => 'The student is already in this class group.',
                ]);
            }

            $effective = Carbon::parse($effectiveOn)->startOfDay();
            $closesOn = $effective->copy()->subDay();

            // Contiguity has a floor: the closing segment must still be at
            // least one day long. Transferring ON the day the current segment
            // began would produce ends_on < starts_on, which chk_enrollment_
            // segments_range rejects - better to say why here.
            if ($closesOn->lt($open->starts_on)) {
                throw ValidationException::withMessages([
                    'effective_on' => 'A transfer cannot take effect on or before the day the '
                        .'current class placement began ('.$open->starts_on->toDateString().').',
                ]);
            }

            if ($effective->lt($enrollment->enrolled_on)) {
                throw ValidationException::withMessages([
                    'effective_on' => 'A transfer cannot precede the enrolment date.',
                ]);
            }

            $actor = $this->currentActor();
            $fromClassGroupId = $open->class_group_id;

            // ---- The whole of C2, in two writes -------------------------
            // Closing on effective-1 and opening on effective makes the pair
            // adjacent by ARITHMETIC, not by convention: there is no date that
            // both segments claim (no overlap) and no date that neither claims
            // (no gap). 5.2 needs both, because a gap would leave days
            // belonging to no class group and 9's attendance denominator
            // resolves a date to a class group through this table.
            $open->ends_on = $closesOn;
            $open->save();

            $segment = EnrollmentSegment::query()->create([
                'enrollment_id' => $enrollmentId,
                'class_group_id' => $newClassGroupId,
                'starts_on' => $effective,
                'ends_on' => null,
                'roll_number' => $rollNumber,
                'reason' => $reason,
                'reason_text' => $reasonText,
                'capacity_override' => $capacityOverride,
                'created_by' => $actor->id,
            ]);
            // -------------------------------------------------------------

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: Enrollment::class,
                auditableId: $enrollmentId,
                before: [
                    'class_group_id' => $fromClassGroupId,
                    'segment_id' => (int) $open->getKey(),
                ],
                after: [
                    'class_group_id' => $newClassGroupId,
                    'segment_id' => (int) $segment->getKey(),
                    'closed_on' => $closesOn->toDateString(),
                    'effective_on' => $effective->toDateString(),
                    'reason' => $reason->value,
                    'reason_text' => $reasonText,
                    'capacity_override' => $capacityOverride,
                ],
                actor: $actor,
            );

            $this->logActivity(
                $enrollment,
                (int) $segment->getKey(),
                (string) $target['name'],
                $reason,
                $effective->toDateString(),
                $actor,
            );

            return $segment;
        });
    }

    /**
     * 5.2's target validation, plus 4.2 invariant 7's capacity check under
     * SELECT ... FOR UPDATE (00-core 10.2).
     *
     * The class group is read through the query builder. `ClassGroup` is an
     * Academics model and tests/Architecture/ModuleBoundaryTest.php forbids
     * this module from using another module's Models, stating in terms that
     * the rule has "No exceptions".
     *
     * @return array<string, mixed>
     */
    private function lockedTarget(int $classGroupId, Enrollment $enrollment, bool $override): array
    {
        $row = DB::table('class_groups')
            ->where('id', $classGroupId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'class_group_id' => 'The selected class group does not exist.',
            ]);
        }

        /** @var array<string, mixed> $target */
        $target = (array) $row;

        if ((int) $target['academic_year_id'] !== $enrollment->academic_year_id) {
            throw ValidationException::withMessages([
                'class_group_id' => 'A mid-year transfer cannot move a student into another '
                    .'academic year; that is a new enrolment.',
            ]);
        }

        if ((int) $target['class_level_id'] !== $enrollment->class_level_id) {
            // 4.1 freezes class_level_id on the Enrollment for the year, and
            // 5.1's `reason` list has no value for a level change. Moving a
            // student up or down a level mid-year is a promotion decision
            // (10), not a segment edit.
            throw ValidationException::withMessages([
                'class_group_id' => 'The target class group is at a different class level; '
                    .'the level is fixed for the year.',
            ]);
        }

        $occupied = DB::table('enrollment_segments')
            ->where('class_group_id', $classGroupId)
            ->whereNull('ends_on')
            ->count();

        $capacity = (int) $target['capacity'];

        if ($occupied >= $capacity && ! $override) {
            throw ValidationException::withMessages([
                'class_group_id' => "This class group is full ({$occupied}/{$capacity}); "
                    .'an explicit capacity override is required.',
            ]);
        }

        return $target;
    }

    /**
     * The student-visible activity feed (8.3). Written through the student
     * workstream's published Action, resolved from the container: 00-core 6.2
     * rule 2 makes an Action the sanctioned door, and LogStudentActivity
     * deliberately runs inside the caller's transaction so the entry rolls back
     * with the transfer it describes.
     */
    private function logActivity(
        Enrollment $enrollment,
        int $segmentId,
        string $targetName,
        SegmentReason $reason,
        string $effectiveOn,
        Actor $actor,
    ): void {
        app(LogStudentActivity::class)->handle(
            studentId: $enrollment->student_id,
            event: $reason === SegmentReason::StreamChange
                ? StudentActivityEvent::StreamChanged
                : StudentActivityEvent::ClassTransferred,
            // 5.3: the report card prints a one-line provenance note driven by
            // the segment rows; this is the same sentence in the activity feed.
            summary: "Transferred to {$targetName} with effect from {$effectiveOn}.",
            enrollmentId: (int) $enrollment->getKey(),
            relatedType: EnrollmentSegment::class,
            relatedId: $segmentId,
            actor: $actor,
        );
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
