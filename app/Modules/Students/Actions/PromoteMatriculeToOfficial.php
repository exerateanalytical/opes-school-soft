<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Models\Student;
use App\Support\Audit\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 6.4 - the one supervised temporary -> official
 * matricule transition, and the only mutation of a matricule the product
 * permits.
 *
 * Three defences, none of which is redundant:
 *
 *  1. **Its own permission.** Not students.manage. Finalising is irreversible
 *     and the number is printed on certificates, so the right to do it is
 *     granted separately (00-core 9.1).
 *  2. **Conditional UPDATE with an affected-rows check** (00-core 10.4). A
 *     read-then-write would let two concurrent calls both observe
 *     matricule_is_official = 0 and both write, and the second would
 *     overwrite a number already issued to the government. Here the database
 *     decides: the WHERE clause is the guard and 0 affected rows is the
 *     rejection. This is what makes the transition single-use rather than
 *     usually-single-use.
 *  3. **The model observer** (Student::booted). Once official, any Eloquent
 *     write to the column throws - so the accidental
 *     `$student->matricule = ...` written next sprint fails loudly.
 *
 * Note the UPDATE is issued through the query builder, bypassing that
 * observer, which is exactly why the conditional WHERE has to carry the
 * guarantee. Eloquent cannot express "update only if the stored value is still
 * X" atomically.
 */
final class PromoteMatriculeToOfficial
{
    public function handle(int $studentId, string $newMatricule): Student
    {
        Gate::authorize(Permission::StudentsMatriculeFinalise->value);

        $newMatricule = trim($newMatricule);

        if ($newMatricule === '') {
            throw ValidationException::withMessages([
                'matricule' => 'An official matricule cannot be blank.',
            ]);
        }

        $actor = $this->currentActor();

        return DB::transaction(function () use ($studentId, $newMatricule, $actor): Student {
            /** @var Student $student */
            $student = Student::query()->findOrFail($studentId);
            $previous = $student->matricule;

            try {
                $affected = DB::table('students')
                    ->where('id', '=', $studentId)
                    ->where('matricule_is_official', '=', false)
                    ->update([
                        'matricule' => $newMatricule,
                        'matricule_is_official' => true,
                        'updated_by' => $actor->id,
                        'updated_at' => now(),
                    ]);
            } catch (UniqueConstraintViolationException) {
                // matricule is globally UNIQUE (00-core 12). Surfaced as a
                // field error rather than a 500, because on a bulk finalisation
                // day this is a typo, not an outage.
                throw ValidationException::withMessages([
                    'matricule' => "The matricule {$newMatricule} is already held by another student.",
                ]);
            }

            if ($affected === 0) {
                throw ValidationException::withMessages([
                    'matricule' => "Student {$previous} already holds an official matricule; "
                        .'the temporary-to-official transition is single-use (07-students 6.4).',
                ]);
            }

            // 6.4 requires both: AuditLog answers "who changed this row",
            // StudentActivityLog answers "what happened to this child", and
            // staff read the second without holding audit.view.
            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: Student::class,
                auditableId: $studentId,
                before: ['matricule' => $previous, 'matricule_is_official' => false],
                after: ['matricule' => $newMatricule, 'matricule_is_official' => true],
                actor: $actor,
            );

            app(LogStudentActivity::class)->handle(
                studentId: $studentId,
                event: StudentActivityEvent::MatriculeFinalised,
                summary: sprintf('Matricule finalised: %s replaced the temporary %s.', $newMatricule, $previous),
                actor: $actor,
            );

            /** @var Student $fresh */
            $fresh = Student::query()->findOrFail($studentId);

            return $fresh;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
