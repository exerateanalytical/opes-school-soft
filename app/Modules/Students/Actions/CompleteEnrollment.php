<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Models\Enrollment;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 3.3 - the `active -> completed` transition, the
 * one WithdrawStudent deliberately refuses to make. A completed enrollment on
 * an exit-level class is what DeriveStudentStatus reads as "graduated"
 * (3.2 rule 4), so this is the door the rollover wizard's step 8
 * ("Graduates & leavers", 08-operations §6.2) walks through - archived by
 * status, never deleted.
 *
 * The segment pairing mirrors WithdrawStudent, and for the same reason (5.2):
 * a completed enrollment whose segment stayed open would keep the graduate on
 * the class roll - in the attendance denominator, the ranking cohort and the
 * capacity count - for the rest of time.
 */
final class CompleteEnrollment
{
    public const PERMISSION = Permission::StudentsManage->value;

    public function handle(int $enrollmentId, string $on, Actor $actor): Enrollment
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($enrollmentId, $on, $actor): Enrollment {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollmentId);

            $from = $enrollment->status;

            // 3.3: only `active` may complete. A suspended student must be
            // reactivated first - completing a suspension silently would hide
            // an unresolved disciplinary or fee matter behind a diploma.
            if (! $from->canTransitionTo(EnrollmentStatus::Completed)) {
                throw ValidationException::withMessages([
                    'status' => "An enrollment that is {$from->value} cannot become completed.",
                ]);
            }

            if ($on < $enrollment->enrolled_on->toDateString()) {
                throw ValidationException::withMessages([
                    'on' => 'An enrollment cannot complete before the day it began.',
                ]);
            }

            // 4.2 invariant 3: terminal status and left_on are set together.
            $enrollment->status = EnrollmentStatus::Completed;
            $enrollment->left_on = Carbon::parse($on);
            $enrollment->updated_by = $actor->id;
            $enrollment->save();

            // 5.2: the open segment closes in the same transaction.
            DB::table('enrollment_segments')
                ->where('enrollment_id', $enrollmentId)
                ->whereNull('ends_on')
                ->update(['ends_on' => $on, 'updated_at' => now()]);

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: Enrollment::class,
                auditableId: $enrollmentId,
                before: ['status' => $from->value, 'left_on' => null],
                after: ['status' => EnrollmentStatus::Completed->value, 'left_on' => $on],
                actor: $actor,
            );

            // 3.2: the derivation runs inside every Action that writes an
            // Enrollment status - this is what flips the student to
            // `graduated` when the class level is an exit level.
            app(DeriveStudentStatus::class)->handle($enrollment->student_id);

            return $enrollment;
        });
    }
}
