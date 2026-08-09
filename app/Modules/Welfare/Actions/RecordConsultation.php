<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\ConsultationSeverity;
use App\Modules\Welfare\Domain\MedicalPermission;
use App\Modules\Welfare\Models\MedicalConsultation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W3). Records a sick-bay visit.
 *
 *  - The student must exist; read via DB::table, never through Students'
 *    Models (ModuleBoundaryTest).
 *  - When an enrollment is named it must belong to that student - a
 *    consultation filed against another child's enrollment would corrupt
 *    both histories.
 *  - Clinical narrative is encrypted by the model casts; the audit entry
 *    deliberately carries NO clinical text (00-core 9.5: the audit log is
 *    plaintext and widely readable, so only non-clinical facts - who, when,
 *    severity, outcome - may land there).
 */
final class RecordConsultation
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $studentId,
        ?int $enrollmentId,
        Carbon $visitedAt,
        string $presentingComplaint,
        ?string $diagnosis,
        ?string $treatment,
        ConsultationSeverity $severity,
        ConsultationOutcome $outcome,
        Actor $actor,
    ): MedicalConsultation {
        Gate::authorize(MedicalPermission::MANAGE);

        if (trim($presentingComplaint) === '') {
            throw ValidationException::withMessages([
                'presenting_complaint' => 'A consultation requires the presenting complaint.',
            ]);
        }

        return DB::transaction(function () use (
            $studentId, $enrollmentId, $visitedAt, $presentingComplaint,
            $diagnosis, $treatment, $severity, $outcome, $actor
        ): MedicalConsultation {
            $studentExists = DB::table('students')->where('id', $studentId)->exists();

            if (! $studentExists) {
                throw new DomainException('The student does not exist.');
            }

            if ($enrollmentId !== null) {
                /** @var object{id: int|string, student_id: int|string}|null $enrollment */
                $enrollment = DB::table('enrollments')
                    ->where('id', $enrollmentId)
                    ->first(['id', 'student_id']);

                if ($enrollment === null) {
                    throw new DomainException('The enrollment does not exist.');
                }

                if ((int) $enrollment->student_id !== $studentId) {
                    throw new DomainException(
                        'The enrollment does not belong to this student; a '
                        .'consultation cannot be filed against another child\'s year.'
                    );
                }
            }

            $consultation = MedicalConsultation::query()->create([
                'student_id' => $studentId,
                'enrollment_id' => $enrollmentId,
                'visited_at' => $visitedAt,
                'presenting_complaint' => trim($presentingComplaint),
                'diagnosis' => $diagnosis !== null && trim($diagnosis) !== '' ? trim($diagnosis) : null,
                'treatment' => $treatment !== null && trim($treatment) !== '' ? trim($treatment) : null,
                'severity' => $severity,
                'outcome' => $outcome,
                'recorded_by' => $actor->id,
            ]);

            // NO clinical text in the audit trail - it is plaintext at rest.
            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: MedicalConsultation::class,
                auditableId: (int) $consultation->getKey(),
                after: [
                    'student_id' => $studentId,
                    'enrollment_id' => $enrollmentId,
                    'visited_at' => $visitedAt->toDateTimeString(),
                    'severity' => $severity->value,
                    'outcome' => $outcome->value,
                ],
                actor: $actor,
            );

            return $consultation->refresh();
        });
    }
}
