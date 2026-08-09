<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Models\Enrollment;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Students-owned door a suspension sanction walks through
 * (docs/plans/phase-08.md F3: "Suspension sanctions call Students\Actions
 * doors, never write enrollments directly").
 *
 * Suspension is a lifecycle pause, NOT a departure: the enrollment stays
 * live (docs/specs/07-students.md 3.3 active -> suspended), the open segment
 * stays open and the student stays on the class roll — attendance then
 * marks the days `suspended`, which 9.6 removes from BOTH sides of the
 * rate so the school's own exclusion never counts against the child.
 * That is why, unlike WithdrawStudent, nothing here touches segments or
 * `left_on`.
 *
 * Gate: students.manage OR discipline.manage. The Surveillant Général holds
 * only the latter, and this door exists precisely so his sanction can flip
 * the status without handing him the whole Students module.
 */
final class SuspendEnrollment
{
    public function handle(int $enrollmentId, string $reason): Enrollment
    {
        if (! Gate::any([
            Permission::StudentsManage->value,
            Permission::DisciplineManage->value,
        ])) {
            throw new AuthorizationException();
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A suspension must record why it happened.',
            ]);
        }

        return DB::transaction(function () use ($enrollmentId, $reason): Enrollment {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollmentId);

            $from = $enrollment->status;

            // 3.3: only `active` may suspend; suspending a suspended
            // enrollment would silently stack punishments in the audit trail.
            if (! $from->canTransitionTo(EnrollmentStatus::Suspended)) {
                throw ValidationException::withMessages([
                    'enrollment_id' => "An enrollment that is {$from->value} cannot be suspended.",
                ]);
            }

            $actor = $this->currentActor();

            $enrollment->status = EnrollmentStatus::Suspended;
            $enrollment->updated_by = $actor->id;
            $enrollment->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: Enrollment::class,
                auditableId: $enrollmentId,
                before: ['status' => $from->value],
                after: ['status' => EnrollmentStatus::Suspended->value, 'reason' => $reason],
                actor: $actor,
            );

            // 3.2: recomputed inside every Action that writes an Enrollment
            // status.
            app(DeriveStudentStatus::class)->handle($enrollment->student_id);

            app(LogStudentActivity::class)->handle(
                studentId: $enrollment->student_id,
                event: StudentActivityEvent::Suspended,
                summary: "Suspended: {$reason}",
                enrollmentId: (int) $enrollment->getKey(),
                relatedType: Enrollment::class,
                relatedId: (int) $enrollment->getKey(),
                actor: $actor,
            );

            return $enrollment;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
