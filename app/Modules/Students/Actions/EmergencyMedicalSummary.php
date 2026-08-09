<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentMedicalRecord;
use Illuminate\Support\Facades\Gate;

/**
 * The cross-module door the phase-10 plan §1 recommends: when the sick bay
 * (Welfare) needs a child's chronic conditions IN THE CLEAR - allergies
 * before medicating, conditions before referring - it calls this Action
 * rather than decrypting Students' rows itself. Welfare's own reads of
 * `student_medical_records` stay DB::table COUNTs; only this door, inside
 * the owning module, touches the encrypted `detail`.
 *
 * Scope: emergency-relevant records only (the StudentMedicalRecord 8.2
 * boundary) - the full chart stays on the Students screens.
 *
 * Gated on `medical.view`: the literal here is the same two-segment value
 * Welfare's MedicalPermission::VIEW names and Phase 10's wiring package
 * adds to Identity\Domain\Permission; a string (not an import) so Students
 * takes no dependency on Welfare.
 */
final class EmergencyMedicalSummary
{
    /**
     * @return array{
     *     student: array{id: int, matricule: string, name: string},
     *     records: list<array{
     *         condition_type: string,
     *         summary: string,
     *         severity: string,
     *         detail: string|null,
     *         recorded_at: string,
     *     }>,
     * }
     */
    public function handle(int $studentId): array
    {
        Gate::authorize('medical.view');

        /** @var Student $student */
        $student = Student::query()->findOrFail($studentId);

        $records = [];

        $rows = StudentMedicalRecord::query()
            ->where('student_id', $studentId)
            ->emergencyRelevant()
            ->orderByDesc('severity')
            ->orderByDesc('recorded_at')
            ->get();

        foreach ($rows as $record) {
            $records[] = [
                'condition_type' => $record->condition_type->value,
                'summary' => $record->summary,
                'severity' => $record->severity->value,
                // Decrypted by the model cast - this is the point of the door.
                'detail' => $record->detail,
                'recorded_at' => $record->recorded_at->toDateTimeString(),
            ];
        }

        return [
            'student' => [
                'id' => (int) $student->getKey(),
                'matricule' => $student->matricule,
                'name' => trim($student->first_name.' '.$student->last_name),
            ],
            'records' => $records,
        ];
    }
}
