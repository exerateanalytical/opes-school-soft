<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Actions;

use App\Modules\Alumni\Models\AlumnusRecord;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The door from the student lifecycle into the alumni relationship -
 * gap #3's "conversion", mirroring what the admissions gap analysis says
 * about applicant -> student: a transition this consequential must be one
 * enforced Action, not an INSERT anyone can shape.
 *
 * Eligibility is read from `students.status = 'graduated'`, which is
 * 07-students 3.2's DERIVED cache of the enrollment history: it is
 * `graduated` exactly when the latest terminal enrollment is `completed`
 * on an exit-level class - the state ApplyPromotionRun's Graduate outcome
 * and CompleteEnrollment both drive through DeriveStudentStatus. Reading
 * the cache rather than re-deriving keeps this module out of the Students
 * module's derivation logic while agreeing with it by construction.
 *
 * The final class group name and academic year name are denormalised HERE,
 * at conversion - label-at-time, the same discipline the reporting module
 * applies to bulletins. A class group renamed years later must not rewrite
 * what this cohort's diploma class was called.
 */
final class ConvertGraduateToAlumnus
{
    public const PERMISSION = Permission::AlumniManage->value;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $studentId, Actor $actor): AlumnusRecord
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($studentId, $actor): AlumnusRecord {
            /** @var object{id: int|string, status: string}|null $student */
            $student = DB::table('students')
                ->where('id', $studentId)
                ->lockForUpdate()
                ->first(['id', 'status']);

            if ($student === null) {
                throw new DomainException("Student {$studentId} does not exist.");
            }

            if ($student->status !== 'graduated') {
                throw new DomainException(
                    "Student {$studentId} is {$student->status}, not graduated; "
                    .'only a graduate can become an alumnus (07-students 3.2 rule 4).'
                );
            }

            if (AlumnusRecord::query()->where('student_id', $studentId)->exists()) {
                throw new DomainException(
                    "Student {$studentId} has already been converted to an alumnus."
                );
            }

            // The completing enrollment: the latest completed one on an
            // exit-level class - the same row DeriveStudentStatus rule 4 read
            // to call this student graduated. left_on DESC then id DESC
            // mirrors that Action's tie-break.
            /** @var object{id: int|string, left_on: string|null, academic_year_name: string}|null $enrollment */
            $enrollment = DB::table('enrollments as e')
                ->join('class_levels as cl', 'cl.id', '=', 'e.class_level_id')
                ->join('academic_years as ay', 'ay.id', '=', 'e.academic_year_id')
                ->where('e.student_id', $studentId)
                ->where('e.status', 'completed')
                ->where('cl.is_exam_class', true)
                ->orderByDesc('e.left_on')
                ->orderByDesc('e.id')
                ->first(['e.id', 'e.left_on', 'ay.name as academic_year_name']);

            if ($enrollment === null || $enrollment->left_on === null) {
                // The status cache says graduated but the history does not
                // support it - refusing beats denormalising a fiction.
                throw new DomainException(
                    "Student {$studentId} is marked graduated but no completed "
                    .'exit-level enrollment was found to convert from.'
                );
            }

            // The class the graduate actually sat in at the end: the last
            // segment of the completing enrollment.
            $finalClassGroupName = DB::table('enrollment_segments as es')
                ->join('class_groups as cg', 'cg.id', '=', 'es.class_group_id')
                ->where('es.enrollment_id', (int) $enrollment->id)
                ->orderByDesc('es.starts_on')
                ->orderByDesc('es.id')
                ->value('cg.name');

            if (! is_string($finalClassGroupName) || $finalClassGroupName === '') {
                throw new DomainException(
                    "Student {$studentId}'s completing enrollment has no class "
                    .'segment to record a final class from.'
                );
            }

            // Seed the contact columns from what the roll already knows, so
            // "reachable" starts honest rather than empty.
            /** @var object{email: string|null, phone: string|null}|null $contact */
            $contact = DB::table('students')
                ->where('id', $studentId)
                ->first(['email', 'phone']);

            $graduationYear = (int) Carbon::parse($enrollment->left_on)->format('Y');

            $record = AlumnusRecord::query()->create([
                'student_id' => $studentId,
                'graduation_year' => $graduationYear,
                'final_class_group_name' => $finalClassGroupName,
                'academic_year_name' => $enrollment->academic_year_name,
                'contact_email' => $contact?->email,
                'contact_phone' => $contact?->phone,
                // Stated rather than left to the column default. create()
                // returns the in-memory instance, which never learns a
                // default applied by the database, so every caller reading
                // this straight off the returned record saw null where the
                // stored row says false - and "we have no idea whether this
                // person is alive" is a different claim from "they are".
                'is_deceased' => false,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Alumni',
                auditableType: AlumnusRecord::class,
                auditableId: (int) $record->getKey(),
                after: [
                    'student_id' => $studentId,
                    'graduation_year' => $graduationYear,
                    'final_class_group_name' => $finalClassGroupName,
                    'academic_year_name' => $enrollment->academic_year_name,
                ],
                actor: $actor,
            );

            return $record;
        });
    }
}
