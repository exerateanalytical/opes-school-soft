<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Modules\Attendance\Domain\JustificationType;
use App\Modules\Attendance\Jobs\RebuildAttendanceSummaryJob;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * §9.7 (C6): a justification is received AFTER the fact and is orthogonal to
 * the status — an `absent` record becomes `is_justified = 1` and STAYS
 * `absent`. The MINESEC justified/unjustified hours split reads this flag,
 * so the summary is re-queued.
 *
 * The optional supporting document must belong to the same student the
 * record's enrollment belongs to — a certificate filed against another
 * child justifies nothing.
 */
final class JustifyAbsence
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $recordId,
        JustificationType $type,
        ?int $justificationDocumentId = null,
    ): AttendanceRecord {
        Gate::authorize(Permission::AttendanceJustify->value);

        $record = DB::transaction(function () use ($recordId, $type, $justificationDocumentId): AttendanceRecord {
            /** @var AttendanceRecord $record */
            $record = AttendanceRecord::query()
                ->lockForUpdate()
                ->findOrFail($recordId);

            if (! $record->status->isJustifiable()) {
                throw ValidationException::withMessages([
                    'record' => sprintf(
                        'A %s record is not an absence; there is nothing to justify.',
                        $record->status->value,
                    ),
                ]);
            }

            if ($justificationDocumentId !== null) {
                $this->assertDocumentBelongsToStudent($record, $justificationDocumentId);
            }

            $before = [
                'is_justified' => $record->is_justified,
                'justification_type' => $record->justification_type?->value,
            ];

            $record->fill([
                'is_justified' => true,
                'justification_type' => $type,
                'justification_document_id' => $justificationDocumentId,
                'justified_by' => (int) auth()->id(),
                'justified_at' => now(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Attendance',
                auditableType: AttendanceRecord::class,
                auditableId: (int) $record->getKey(),
                before: $before,
                after: [
                    'is_justified' => true,
                    'justification_type' => $type->value,
                    'justification_document_id' => $justificationDocumentId,
                ],
                actor: auth()->user()?->toAuditActor(),
            );

            return $record;
        });

        // The justified/unjustified hours split changed (§9.7) — re-queue.
        RebuildAttendanceSummaryJob::dispatch($record->attendance_register_id);

        return $record;
    }

    private function assertDocumentBelongsToStudent(AttendanceRecord $record, int $documentId): void
    {
        // student_documents and enrollments are Students-owned — DB::table
        // reads only (ModuleBoundaryTest).
        $documentStudentId = DB::table('student_documents')
            ->where('id', $documentId)
            ->value('student_id');

        if ($documentStudentId === null) {
            throw ValidationException::withMessages([
                'justification_document_id' => 'The supporting document does not exist.',
            ]);
        }

        $enrollmentStudentId = DB::table('enrollments')
            ->where('id', $record->enrollment_id)
            ->value('student_id');

        if ((int) $documentStudentId !== (int) $enrollmentStudentId) {
            throw ValidationException::withMessages([
                'justification_document_id' => 'The supporting document belongs to a different '
                    .'student than this attendance record.',
            ]);
        }
    }
}
