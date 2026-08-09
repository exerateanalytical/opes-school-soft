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
 * SuspendEnrollment's inverse — docs/specs/07-students.md 3.3
 * suspended -> active. The pair lives in Students so the enrollment
 * lifecycle has exactly one owner; Welfare calls it when a suspension
 * sanction ends.
 */
final class ReinstateEnrollment
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
                'reason' => 'A reinstatement must record why it happened.',
            ]);
        }

        return DB::transaction(function () use ($enrollmentId, $reason): Enrollment {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollmentId);

            $from = $enrollment->status;

            if ($from !== EnrollmentStatus::Suspended) {
                throw ValidationException::withMessages([
                    'enrollment_id' => "An enrollment that is {$from->value} cannot be reinstated.",
                ]);
            }

            $actor = $this->currentActor();

            $enrollment->status = EnrollmentStatus::Active;
            $enrollment->updated_by = $actor->id;
            $enrollment->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: Enrollment::class,
                auditableId: $enrollmentId,
                before: ['status' => $from->value],
                after: ['status' => EnrollmentStatus::Active->value, 'reason' => $reason],
                actor: $actor,
            );

            app(DeriveStudentStatus::class)->handle($enrollment->student_id);

            app(LogStudentActivity::class)->handle(
                studentId: $enrollment->student_id,
                event: StudentActivityEvent::Reinstated,
                summary: "Reinstated: {$reason}",
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
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
