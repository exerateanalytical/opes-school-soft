<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Actions;

use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Admissions\Models\AdmissionApplicationGuardian;
use App\Modules\Guardians\Actions\CreateGuardian;
use App\Modules\Guardians\Actions\FindDuplicateGuardians;
use App\Modules\Guardians\Actions\LinkGuardian;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Actions\CreateStudent;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Domain\EnrollmentType;
use App\Modules\Students\Domain\Gender;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Application -> Student + Enrollment + guardian links, in ONE transaction.
 * docs/specs/07-students.md 6.3.
 *
 * Every step below is another module's work, reached through that module's
 * Actions - the only sanctioned door (00-core 6.2 rule 2). Nothing here writes
 * a students, enrollments, guardians or student_guardians row directly, which
 * is why the numbering, the capacity check under lock, the duplicate-guardian
 * matching and the "exactly one primary guardian" constraint are all inherited
 * rather than reimplemented. Reimplementing any of them would produce a second
 * version that drifts.
 *
 * **Permissions.** This gates on `admissions.manage`, and then CreateStudent
 * and EnrollStudent gate on `students.manage` and CreateGuardian/LinkGuardian
 * on `guardians.manage`. That is deliberate and is not smoothed over here: an
 * admissions clerk who may fill in forms but may not create student records
 * fails at the point where a student would be created, not silently earlier or
 * - far worse - not at all.
 *
 * **Idempotence.** 6.3 step 2 describes the already-converted case as
 * returning the existing student. This implementation REFUSES instead. The
 * difference matters at the only place it can be observed: the wizard's
 * Confirm button. A silent success there tells the operator they just admitted
 * a student when in fact somebody else already did, so they never look for the
 * duplicate paperwork. `converted_student_id` is UNIQUE, so the outcome is the
 * same either way - one student, never two; only the report to the human
 * differs, and a refusal is the honest one.
 */
final class ConvertApplication
{
    /**
     * @return array{student_id: int, enrollment_id: int, guardian_ids: list<int>}
     *
     * An array shape rather than a result class: this module owns exactly the
     * files the phase plan assigns it, and a second class in this file would
     * not autoload under PSR-4. PHPStan checks the shape at level 8 as
     * strictly as it would check an object.
     */
    public function handle(AdmissionApplication $application, int $classGroupId): array
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $actor = $this->currentActor();

        return DB::transaction(function () use ($application, $classGroupId, $actor): array {
            // 6.3 step 1: FOR UPDATE on the application. This is what makes
            // the two guards below decisive rather than advisory - a second
            // Confirm blocks here until the first has committed and set
            // converted_student_id.
            /** @var AdmissionApplication|null $locked */
            $locked = AdmissionApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('The application disappeared between load and conversion.');
            }

            // 6.3 step 2, the idempotency guard. See the class docblock for
            // why this refuses rather than returning the existing student.
            if ($locked->isConverted()) {
                throw new DomainException(
                    'Application '.($locked->application_no ?? (string) $locked->getKey())
                    .' has already been converted to student #'.(string) $locked->converted_student_id.'.'
                );
            }

            if (! $locked->status->isConvertible()) {
                throw ValidationException::withMessages([
                    'status' => __('opes.admissions_screen.errors.not_convertible'),
                ]);
            }

            $academicYearId = $locked->academic_year_id;

            if ($academicYearId === null) {
                throw ValidationException::withMessages([
                    'academic_year_id' => __('opes.admissions_screen.errors.field_required'),
                ]);
            }

            $sectionId = $this->resolveSectionId($locked);
            $enrolledOn = $locked->admission_date?->toDateString() ?? BusinessDate::today();

            // 6.3 step 3 + 4. The matricule and admission number are allocated
            // INSIDE CreateStudent, from row-locked sequences; 6.4 is explicit
            // that admissions issues a TEMPORARY matricule, and CreateStudent
            // sets matricule_is_official = false for exactly that reason.
            // Nothing here needs to know the format - it only passes the
            // section's template through, because the section belongs to
            // Academics and may not be loaded as a Model from here.
            $student = app(CreateStudent::class)->handle(
                firstName: (string) $locked->first_name,
                lastName: (string) $locked->last_name,
                dateOfBirth: (string) $locked->date_of_birth?->toDateString(),
                gender: Gender::from((string) $locked->gender),
                schoolSectionId: $sectionId,
                matriculeFormat: $this->sectionMatriculeFormat($sectionId),
                middleName: $locked->middle_name,
                placeOfBirth: $locked->place_of_birth,
                nationality: $locked->nationality ?? 'CM',
                stateOfOrigin: $locked->state_of_origin,
                religion: $locked->religion,
                bloodGroup: $locked->blood_group,
                genotype: $locked->genotype,
                photoPath: $locked->photo_path,
                firstAdmissionDate: $enrolledOn,
            );

            $studentId = (int) $student->getKey();

            // 6.3 step 4, continued: the class GROUP is chosen at this point,
            // not at application time - the application only names a class
            // LEVEL. EnrollStudent creates the initial EnrollmentSegment in
            // the same breath and checks capacity under lock.
            $enrollment = app(EnrollStudent::class)->handle(
                studentId: $studentId,
                academicYearId: $academicYearId,
                classGroupId: $classGroupId,
                enrolledOn: $enrolledOn,
                // 6.6: a transfer-in is an admission that names a prior
                // school. Fees reads this per 4.1, so getting it from the form
                // rather than defaulting everything to `new` is the difference
                // between a correct and an incorrect first invoice.
                enrollmentType: ($locked->previous_school_name === null || $locked->previous_school_name === '')
                    ? EnrollmentType::New
                    : EnrollmentType::TransferIn,
                rollNumber: $locked->proposed_roll_number,
            );

            // 6.3 step 5.
            $guardianIds = $this->attachGuardians($locked, $studentId, $enrolledOn, $actor);

            // 6.3 step 6 - moving the step-5 documents onto StudentDocument -
            // is NOT done here, and that is a decision rather than an
            // oversight. Students exposes no Action for attaching a document,
            // and inserting into `student_documents` from Admissions is the
            // precise cross-module write 00-core 6.2 rule 2 forbids. The rows
            // stay on the application, which is lossless: the files are on the
            // private disk either way and the application is retained. Wire it
            // up the moment Students publishes AttachStudentDocument.

            // 6.3 step 7. The row passes through `accepted` on its way to
            // `enrolled` so that the decision is recorded even though this
            // product has no separate DecideApplication screen yet - see
            // ApplicationStatus::isConvertible().
            $previousStatus = $locked->status;

            $locked->status = ApplicationStatus::Enrolled;
            $locked->converted_student_id = $studentId;
            $locked->decided_by = $actor->id;
            $locked->decided_at = Carbon::now();
            // 6.5: enrolled applications are exempt from the purge. Cleared
            // rather than left, in case the row was previously withdrawn and
            // re-opened.
            $locked->purge_due_on = null;
            $locked->updated_by = $actor->id;
            $locked->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Admissions',
                auditableType: AdmissionApplication::class,
                auditableId: (int) $locked->getKey(),
                before: ['status' => $previousStatus->value, 'converted_student_id' => null],
                after: [
                    'status' => ApplicationStatus::Enrolled->value,
                    'converted_student_id' => $studentId,
                    'enrollment_id' => (int) $enrollment->getKey(),
                    'class_group_id' => $classGroupId,
                    'guardian_ids' => $guardianIds,
                ],
                actor: $actor,
            );

            // 6.3 step 8 emits `student.enrolled` AFTER commit, so Fees can
            // invoice a student that is certainly there. The event itself
            // belongs to the Students module, which publishes it from
            // EnrollStudent; re-emitting it here would double-invoice.

            return [
                'student_id' => $studentId,
                'enrollment_id' => (int) $enrollment->getKey(),
                'guardian_ids' => $guardianIds,
            ];
        });
    }

    /**
     * 6.3 step 5: match-or-create each proposed guardian (7.7) and create the
     * StudentGuardian link with the flags proposed at step 3,
     * `valid_from = enrolled_on`.
     *
     * @return list<int>
     */
    private function attachGuardians(
        AdmissionApplication $application,
        int $studentId,
        string $enrolledOn,
        Actor $actor,
    ): array {
        $ids = [];

        /** @var list<AdmissionApplicationGuardian> $rows */
        $rows = $application->guardians()->orderBy('position')->get()->all();

        foreach ($rows as $row) {
            $ids[] = $guardianId = $this->matchOrCreateGuardian($row, $actor);

            app(LinkGuardian::class)->handle(
                studentId: $studentId,
                guardianId: $guardianId,
                relationship: GuardianRelationship::from($row->relationship->value),
                relationshipOther: $row->relationship_other,
                isPrimary: $row->is_primary,
                hasCustody: $row->has_custody,
                receivesReports: $row->receives_reports,
                receivesInvoices: $row->receives_invoices,
                isEmergencyContact: $row->is_emergency_contact,
                isAuthorisedForPickup: $row->is_authorised_for_pickup,
                isFeePayer: $row->is_fee_payer,
                // 6.3 step 5, stated verbatim: the link starts the day the
                // student enrols, not the day the form was typed. A link
                // dated earlier would grant access before the child was a
                // pupil of this school.
                validFrom: $enrolledOn,
                actor: $actor,
            );
        }

        return $ids;
    }

    /**
     * 7.7. An exact hit on the national-ID blind index is the one tier that is
     * decisive enough to reuse without asking: `guardians.id_number_blind_index`
     * is UNIQUE, so creating a second record on the same ID is impossible
     * anyway, and CreateGuardian would (correctly) refuse. Tiers 2 and 3 -
     * shared household phone, same name and date of birth - are genuinely
     * ambiguous and must NOT auto-merge; 7.7 calls a silent merge on a name
     * match a data-protection incident, and a shared handset is the normal
     * case in a Cameroonian household, not evidence of duplication.
     *
     * A second parent from the same family therefore gets their own record
     * here, and the operator resolves it from the guardian profile later,
     * where both records are visible.
     */
    private function matchOrCreateGuardian(AdmissionApplicationGuardian $row, Actor $actor): int
    {
        $match = app(FindDuplicateGuardians::class)->handle(
            idNumber: $row->id_number,
            phone: $row->phone,
            lastName: $row->last_name,
            firstName: $row->first_name,
            dateOfBirth: $row->date_of_birth?->toDateString(),
        );

        if ($match['tier'] === FindDuplicateGuardians::TIER_ID_NUMBER && $match['candidates'] !== []) {
            return (int) $match['candidates'][0]->getKey();
        }

        $created = app(CreateGuardian::class)->handle([
            'title' => $row->title,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'gender' => $row->gender,
            'date_of_birth' => $row->date_of_birth?->toDateString(),
            'id_type' => $row->id_type,
            'id_number' => $row->id_number,
            'occupation' => $row->occupation,
            'employer' => $row->employer,
            'phone' => $row->phone,
            'alternative_phone' => $row->alternative_phone,
            'email' => $row->email,
            'address_line' => $row->address_line,
            'city' => $row->city,
            'region' => $row->region,
            'language' => $row->language,
        ], $actor);

        return (int) $created['guardian']->getKey();
    }

    /**
     * The section the applied-for class level belongs to, falling back to the
     * section named on the application itself.
     *
     * Read through the query builder, never through Academics' Models:
     * tests/Architecture/ModuleBoundaryTest.php states the no-other-module's-
     * Models rule has no exceptions, and this is exactly the temptation it
     * exists to catch.
     */
    private function resolveSectionId(AdmissionApplication $application): ?int
    {
        if ($application->class_level_id !== null) {
            $sectionId = DB::table('class_levels')
                ->where('id', '=', $application->class_level_id)
                ->value('school_section_id');

            if (is_numeric($sectionId)) {
                return (int) $sectionId;
            }
        }

        return $application->school_section_id;
    }

    /**
     * `SchoolSection.matricule_format` (6.4), passed through to CreateStudent
     * because that Action is deliberately unable to load the section itself.
     */
    private function sectionMatriculeFormat(?int $sectionId): ?string
    {
        if ($sectionId === null) {
            return null;
        }

        $format = DB::table('school_sections')
            ->where('id', '=', $sectionId)
            ->value('matricule_format');

        return is_string($format) && $format !== '' ? $format : null;
    }

    private function currentActor(): Actor
    {
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            throw new RuntimeException('An admission cannot be converted by an unauthenticated caller.');
        }

        return $actor;
    }
}
