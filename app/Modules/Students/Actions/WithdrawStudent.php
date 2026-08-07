<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Enrollment;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 3.3 and 5.2 - end an enrollment and, in the SAME
 * transaction, end the segment that is still open.
 *
 * The pairing is not housekeeping. 5.2 requires the union of an enrollment's
 * segments to cover exactly [enrolled_on, left_on ?? year end]. A withdrawal
 * that closed the enrollment and left the segment open would leave the student
 * on the class roll for the rest of the year: still counted in the attendance
 * denominator (9), still in the ranking cohort (5.3), still occupying a seat
 * against capacity (4.2 invariant 7).
 */
final class WithdrawStudent
{
    public function handle(
        int $enrollmentId,
        string $on,
        string $reason,
        EnrollmentStatus $to = EnrollmentStatus::Withdrawn,
    ): Enrollment {
        Gate::authorize(Permission::StudentsManage->value);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A withdrawal must record why it happened.',
            ]);
        }

        if (! in_array($to, [EnrollmentStatus::Withdrawn, EnrollmentStatus::TransferredOut], true)) {
            throw ValidationException::withMessages([
                'status' => 'WithdrawStudent only ends an enrollment as withdrawn or transferred_out.',
            ]);
        }

        return DB::transaction(function () use ($enrollmentId, $on, $reason, $to): Enrollment {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollmentId);

            $from = $enrollment->status;

            // 3.3: the transition graph is enforced by the Action, not merely
            // by the UI. Re-withdrawing a withdrawn enrollment would otherwise
            // silently rewrite left_on and corrupt every date-keyed report.
            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "An enrollment that is {$from->value} cannot become {$to->value}.",
                ]);
            }

            // 4.2 invariant 2 is also a CHECK, but failing here gives a form
            // message instead of a driver exception.
            if ($on < $enrollment->enrolled_on->toDateString()) {
                throw ValidationException::withMessages([
                    'on' => 'A student cannot leave before the day they enrolled.',
                ]);
            }

            $actor = $this->currentActor();

            // 4.2 invariant 3: terminal status <=> left_on set. Written
            // together so the pair can never drift.
            $enrollment->status = $to;
            $enrollment->left_on = Carbon::parse($on);
            $enrollment->updated_by = $actor->id;
            $enrollment->save();

            // 5.2: "On a terminal enrollment status, the open segment's
            // ends_on is set to left_on in the same transaction."
            $closed = DB::table('enrollment_segments')
                ->where('enrollment_id', $enrollmentId)
                ->whereNull('ends_on')
                ->update(['ends_on' => $on, 'updated_at' => now()]);

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: Enrollment::class,
                auditableId: $enrollmentId,
                before: ['status' => $from->value, 'left_on' => null],
                after: [
                    'status' => $to->value,
                    'left_on' => $on,
                    'reason' => $reason,
                    'segments_closed' => $closed,
                ],
                actor: $actor,
            );

            // 3.2: recomputed inside every Action that writes an Enrollment
            // status. A withdrawal in the current year moves the student to
            // `withdrawn`; one in a past year leaves the current year's
            // enrollment deciding, which is exactly what the table says.
            $derived = app(DeriveStudentStatus::class)->handle($enrollment->student_id);

            $this->logActivity($enrollment, $to, $on, $reason, $derived, $actor);

            return $enrollment;
        });
    }

    /**
     * The student-visible activity feed (8.3). Written through the student
     * workstream's published Action, resolved from the container: 00-core 6.2
     * rule 2 makes an Action the sanctioned door, and LogStudentActivity
     * deliberately runs inside the caller's transaction so the entry rolls back
     * with the withdrawal it describes.
     */
    private function logActivity(
        Enrollment $enrollment,
        EnrollmentStatus $to,
        string $on,
        string $reason,
        StudentStatus $derived,
        Actor $actor,
    ): void {
        $event = $to === EnrollmentStatus::TransferredOut
            ? StudentActivityEvent::TransferredOut
            : StudentActivityEvent::Withdrawn;

        app(LogStudentActivity::class)->handle(
            studentId: $enrollment->student_id,
            event: $event,
            summary: ucfirst(str_replace('_', ' ', $event->value))." on {$on}: {$reason}"
                ." (student now {$derived->value})",
            enrollmentId: (int) $enrollment->getKey(),
            relatedType: Enrollment::class,
            relatedId: (int) $enrollment->getKey(),
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
