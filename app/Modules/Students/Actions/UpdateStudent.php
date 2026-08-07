<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Models\Student;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/07-students.md 12 - biographical corrections only.
 *
 * Three things this Action structurally cannot do, each of them a defect if it
 * could:
 *
 *  - **matricule** - 6.4 permits exactly one supervised change, through
 *    PromoteMatriculeToOfficial with its own permission.
 *  - **status** - 3.2 makes it a derived cache of the enrollment history;
 *    hand-editing it is what made v1's two lifecycles diverge inside a term.
 *  - **admission_no** - issued once by Admissions from a sequence.
 *
 * The allow-list below is the enforcement, not a convention: an attribute
 * absent from it is dropped before the model is touched, so a caller that
 * passes `status` gets no error and no effect, and the model's $fillable
 * refuses it a second time.
 */
final class UpdateStudent
{
    /** @var list<string> */
    public const EDITABLE = [
        'first_name',
        'middle_name',
        'last_name',
        'preferred_name',
        'date_of_birth',
        'birth_certificate_no',
        'place_of_birth',
        'gender',
        'nationality',
        'state_of_origin',
        'religion',
        'blood_group',
        'genotype',
        'photo_path',
        'phone',
        'email',
        'address_line',
        'city',
        'region',
        'house_id',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(int $studentId, array $attributes): Student
    {
        Gate::authorize(Permission::StudentsManage->value);

        $actor = $this->currentActor();

        return DB::transaction(function () use ($studentId, $attributes, $actor): Student {
            /** @var Student $student */
            $student = Student::query()->lockForUpdate()->findOrFail($studentId);

            $changes = array_intersect_key(
                $attributes,
                array_flip(self::EDITABLE),
            );

            $student->fill($changes);

            if ($student->isDirty()) {
                // Only the changed keys, and never an encrypted field's value:
                // 00-core 9.5 keeps that data out of logs and exports, and an
                // audit payload is both.
                $before = [];
                $after = [];

                foreach (array_keys($student->getDirty()) as $key) {
                    if (in_array($key, ['religion', 'blood_group', 'genotype', 'national_id_number'], true)) {
                        $before[$key] = '[encrypted]';
                        $after[$key] = '[encrypted]';

                        continue;
                    }

                    $before[$key] = $student->getOriginal($key);
                    $after[$key] = $student->getAttribute($key);
                }

                $student->updated_by = $actor->id;
                $student->save();

                app(WriteAuditEntry::class)->handle(
                    action: AuditAction::Updated,
                    module: 'Students',
                    auditableType: Student::class,
                    auditableId: (int) $student->getKey(),
                    before: $before,
                    after: $after,
                    actor: $actor,
                );
            }

            return $student;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
