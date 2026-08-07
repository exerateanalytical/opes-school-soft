<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\Gender;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 12.
 *
 * Creates the person, not the enrollment: `class`, `stream` and `academic
 * year` are properties of an Enrollment (3.1) and are the enrollment
 * workstream's to write. A student created here is `prospective` until an
 * enrollment moves them, per the 3.2 derivation.
 */
final class CreateStudent
{
    /**
     * Fallback template when the section has none configured. The TMP prefix
     * is the point: 6.4 permits exactly one supervised replacement of a
     * temporary matricule, and a number that does not announce itself as
     * temporary gets printed on a certificate and then cannot be changed.
     */
    public const TEMPORARY_MATRICULE_FORMAT = 'TMP/{year}/{seq}';

    public const ADMISSION_NO_FORMAT = 'ADM/{year}/{seq}';

    /**
     * @param  string  $dateOfBirth  Y-m-d
     * @param  int|null  $schoolSectionId  scopes the matricule series; the section itself
     *                                     belongs to Academics and is never loaded here
     *                                     (00-core 6.2 rule 2)
     * @param  string|null  $matriculeFormat  SchoolSection.matricule_format, passed in by the
     *                                        caller for the same reason
     */
    public function handle(
        string $firstName,
        string $lastName,
        string $dateOfBirth,
        Gender $gender,
        ?int $schoolSectionId = null,
        ?string $matriculeFormat = null,
        ?string $middleName = null,
        ?string $preferredName = null,
        ?string $placeOfBirth = null,
        string $nationality = 'CM',
        ?string $stateOfOrigin = null,
        ?string $birthCertificateNo = null,
        ?string $religion = null,
        ?string $bloodGroup = null,
        ?string $genotype = null,
        ?string $nationalIdNumber = null,
        ?string $photoPath = null,
        ?string $phone = null,
        ?string $email = null,
        ?string $addressLine = null,
        ?string $city = null,
        ?string $region = null,
        ?int $houseId = null,
        ?string $firstAdmissionDate = null,
    ): Student {
        Gate::authorize(Permission::StudentsManage->value);

        $actor = $this->currentActor();
        $admissionDate = $firstAdmissionDate ?? BusinessDate::today();
        $year = (int) Carbon::parse($admissionDate)->format('Y');

        return DB::transaction(function () use (
            $firstName, $lastName, $dateOfBirth, $gender, $schoolSectionId, $matriculeFormat,
            $middleName, $preferredName, $placeOfBirth, $nationality, $stateOfOrigin,
            $birthCertificateNo, $religion, $bloodGroup, $genotype, $nationalIdNumber,
            $photoPath, $phone, $email, $addressLine, $city, $region, $houseId,
            $admissionDate, $year, $actor,
        ): Student {
            $allocator = app(SequenceAllocator::class);

            // Both numbers come from row-locked sequences INSIDE this
            // transaction (00-core 12). Never max()+1: two front-desk clerks
            // admitting at once would read the same max and produce the same
            // matricule, and the failure would surface as a UNIQUE violation
            // on the busiest day of the year.
            $matriculeSeries = 'matricule.'.$year.'.'
                .($schoolSectionId === null ? 'GLOBAL' : 'SEC'.$schoolSectionId);

            $matricule = $this->render(
                $matriculeFormat ?? self::TEMPORARY_MATRICULE_FORMAT,
                $year,
                $allocator->allocate($matriculeSeries),
                $schoolSectionId,
            );

            $admissionNo = $this->render(
                self::ADMISSION_NO_FORMAT,
                $year,
                $allocator->allocate('admission_no.'.$year),
                $schoolSectionId,
            );

            $student = new Student([
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'preferred_name' => $preferredName,
                'date_of_birth' => $dateOfBirth,
                'birth_certificate_no' => $birthCertificateNo,
                'place_of_birth' => $placeOfBirth,
                'gender' => $gender,
                'nationality' => $nationality,
                'state_of_origin' => $stateOfOrigin,
                'religion' => $religion,
                'blood_group' => $bloodGroup,
                'genotype' => $genotype,
                'national_id_number' => $nationalIdNumber,
                'photo_path' => $photoPath,
                'phone' => $phone,
                'email' => $email,
                'address_line' => $addressLine,
                'city' => $city,
                'region' => $region,
                'house_id' => $houseId,
                'first_admission_date' => $admissionDate,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            // Not mass-assignable: the matricule is issued once here and
            // afterwards only 6.4's supervised transition may touch it.
            $student->matricule = $matricule;

            // Always temporary at creation, whichever template produced it.
            // The official matricule is the government number, which does not
            // exist yet at admission; 6.4's PromoteMatriculeToOfficial is the
            // one supervised path that sets this true.
            $student->matricule_is_official = false;

            $student->admission_no = $admissionNo;
            $student->status = StudentStatus::Prospective;

            // 00-core 9.5: an encrypted column cannot be indexed, so
            // uniqueness of the national ID rides on an HMAC of the plaintext.
            $student->national_id_blind_index = $nationalIdNumber === null
                ? null
                : $this->blindIndex($nationalIdNumber);

            try {
                $student->save();
            } catch (UniqueConstraintViolationException) {
                // The sequence makes a matricule collision essentially
                // impossible, so in practice this is the blind index catching
                // a second student registered on one national ID number.
                throw ValidationException::withMessages([
                    'national_id_number' => 'This identity is already recorded against another student.',
                ]);
            }

            app(LogStudentActivity::class)->handle(
                studentId: (int) $student->getKey(),
                event: StudentActivityEvent::Admitted,
                summary: sprintf('Student record created with matricule %s.', $matricule),
                actor: $actor,
            );

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Students',
                auditableType: Student::class,
                auditableId: (int) $student->getKey(),
                after: [
                    'matricule' => $matricule,
                    'admission_no' => $admissionNo,
                    // No date of birth, no national ID, no religion, no
                    // genotype: 00-core 9.5 keeps encrypted-field values out
                    // of logs and exports, and an audit payload is a log.
                    'name' => $student->fullName(),
                ],
                actor: $actor,
            );

            return $student;
        });
    }

    /**
     * Expand a matricule / admission-number template.
     *
     * Supported placeholders: `{year}`, `{seq}` (zero-padded to 5),
     * `{seq:N}` for another width, `{section}`. Anything else is left as
     * written, so a section format may carry literal school initials -
     * which is exactly what 6.4's non-collision check relies on.
     */
    private function render(string $template, int $year, int $sequence, ?int $sectionId): string
    {
        $rendered = str_replace(
            ['{year}', '{section}'],
            [(string) $year, (string) ($sectionId ?? 0)],
            $template,
        );

        $rendered = (string) preg_replace_callback(
            '/\{seq:(\d+)\}/',
            static fn (array $m): string => str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT),
            $rendered,
        );

        return str_replace('{seq}', str_pad((string) $sequence, 5, '0', STR_PAD_LEFT), $rendered);
    }

    /**
     * HMAC-SHA256(value, key) - 00-core 9.5.
     *
     * Trimmed and upper-cased so that "  ab123456 " and "AB123456" are the
     * same identity; a blind index that misses the duplicate it exists to
     * catch is worse than none, because it looks like it worked.
     */
    private function blindIndex(string $value): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? $key : $decoded;
        }

        return hash_hmac('sha256', mb_strtoupper(trim($value)), $key);
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
