<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions\Import;

use App\Modules\Guardians\Actions\CreateGuardian;
use App\Modules\Guardians\Actions\LinkGuardian;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Actions\CreateStudent;
use App\Modules\Students\Domain\Gender;
use App\Modules\Students\Domain\ImportBatchStatus;
use App\Modules\Students\Domain\ImportKind;
use App\Modules\Students\Domain\ImportRowStatus;
use App\Modules\Students\Models\ImportBatch;
use App\Modules\Students\Models\ImportRow;
use App\Modules\Students\Models\Student;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Phase 3 of 3 - create the real records, one domain Action call per valid
 * row (00-core §15 Phase 2).
 *
 * Calls the real domain Action per kind - CreateStudent, CreateGuardian,
 * HireStaffMember - rather than INSERTing, because each of those Actions
 * allocates its own number, derives status, and writes the audit entry. An
 * importer writing straight to a table would bypass all three and produce
 * records the rest of the product does not believe in.
 *
 * `imported_record_type` for Guardians/Staff is a plain STRING literal, not
 * `Guardian::class`/`StaffMember::class`: importing another module's Model
 * would violate the module-boundary rule (00-core §6.2) this codebase
 * enforces with an architecture test. Crossing into Guardians/HR is done
 * the permitted way - through their own Actions - and the FQCN is recorded
 * as data, never as a real class dependency.
 *
 * RESUMABLE BY CONSTRUCTION: only rows still marked `valid` are processed,
 * and each becomes `imported` with the id it created inside the same
 * transaction as the Action call. A run killed halfway - a timeout, a closed
 * laptop - is finished simply by running it again, and cannot produce a
 * second copy of anyone.
 *
 * A row whose Action throws is marked `invalid` and the loop CONTINUES. One
 * bad row out of 1 200 must not abandon the other 1 199.
 */
final class CommitImportBatch
{
    public function __construct(
        private readonly CreateStudent $createStudent,
        private readonly CreateGuardian $createGuardian,
        private readonly LinkGuardian $linkGuardian,
        private readonly HireStaffMember $hireStaffMember,
    ) {}

    public function handle(int $batchId): ImportBatch
    {
        Gate::authorize(Permission::StudentsManage->value);

        $user = Auth::user();

        if ($user === null) {
            throw new DomainException('Committing an import is an audited act; it needs a user.');
        }

        $actor = new Actor((int) $user->getKey(), (string) $user->name);

        /** @var ImportBatch $batch */
        $batch = ImportBatch::query()->findOrFail($batchId);

        $imported = 0;
        $failed = 0;

        $batch->rows()
            ->where('status', ImportRowStatus::Valid->value)
            ->orderBy('row_no')
            ->chunkById(100, function ($rows) use ($batch, $actor, &$imported, &$failed): void {
                /** @var ImportRow $row */
                foreach ($rows as $row) {
                    try {
                        DB::transaction(function () use ($row, $batch, $actor): void {
                            [$recordType, $recordId] = match ($batch->kind) {
                                ImportKind::Students => $this->commitStudent($row->payload),
                                ImportKind::Guardians => $this->commitGuardian($row->payload, $actor),
                                ImportKind::Staff => $this->commitStaff($row->payload),
                            };

                            $row->forceFill([
                                'status' => ImportRowStatus::Imported->value,
                                'errors' => null,
                                'imported_record_type' => $recordType,
                                'imported_record_id' => $recordId,
                            ])->save();
                        });

                        $imported++;
                    } catch (Throwable $e) {
                        $failed++;

                        $row->forceFill([
                            'status' => ImportRowStatus::Invalid->value,
                            'errors' => ['_action' => [$e->getMessage()]],
                        ])->save();
                    }
                }
            });

        $alreadyImported = (int) $batch->rows()
            ->where('status', ImportRowStatus::Imported->value)
            ->count();

        $batch->forceFill([
            'imported_count' => $alreadyImported,
            'invalid_count' => (int) $batch->rows()->where('status', ImportRowStatus::Invalid->value)->count(),
            'valid_count' => (int) $batch->rows()->where('status', ImportRowStatus::Valid->value)->count(),
            'status' => $failed > 0 && $imported === 0
                ? ImportBatchStatus::Failed->value
                : ImportBatchStatus::Committed->value,
            'committed_by' => (int) $user->getKey(),
            'committed_at' => now(),
        ])->save();

        return $batch->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int}
     */
    private function commitStudent(array $payload): array
    {
        $gender = $this->normaliseGender((string) ($payload['gender'] ?? ''));

        $student = $this->createStudent->handle(
            firstName: (string) $payload['first_name'],
            lastName: (string) $payload['last_name'],
            dateOfBirth: (string) $payload['date_of_birth'],
            gender: $gender,
            schoolSectionId: null,
            matriculeFormat: null,
            middleName: isset($payload['middle_name']) ? (string) $payload['middle_name'] : null,
            preferredName: isset($payload['preferred_name']) ? (string) $payload['preferred_name'] : null,
            placeOfBirth: isset($payload['place_of_birth']) ? (string) $payload['place_of_birth'] : null,
            nationality: isset($payload['nationality']) ? (string) $payload['nationality'] : 'CM',
        );

        return [Student::class, (int) $student->getKey()];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int}
     */
    private function commitGuardian(array $payload, Actor $actor): array
    {
        $result = $this->createGuardian->handle([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'middle_name' => $payload['middle_name'] ?? null,
            'phone' => $payload['phone'],
            'email' => $payload['email'] ?? null,
            'id_number' => $payload['national_id_number'] ?? null,
        ], $actor);

        $guardian = $result['guardian'];

        // A matricule that resolves to nobody leaves the guardian created
        // but unlinked - not a row failure. The operator can link them by
        // hand from the guardian's own screen; refusing the whole row over
        // one typo'd matricule would be worse than an unlinked guardian.
        $matricule = isset($payload['student_matricule']) ? trim((string) $payload['student_matricule']) : '';

        if ($matricule !== '') {
            $studentId = DB::table('students')->where('matricule', $matricule)->value('id');

            if ($studentId !== null) {
                $relationship = GuardianRelationship::tryFrom(
                    mb_strtolower((string) ($payload['relationship'] ?? ''))
                ) ?? GuardianRelationship::Other;

                $this->linkGuardian->handle(
                    studentId: (int) $studentId,
                    guardianId: (int) $guardian->getKey(),
                    relationship: $relationship,
                    receivesReports: true,
                    receivesInvoices: true,
                    isFeePayer: true,
                    actor: $actor,
                );
            }
        }

        // A plain string, not Guardian::class - see the class docblock.
        return ['App\\Modules\\Guardians\\Models\\Guardian', (int) $guardian->getKey()];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int}
     */
    private function commitStaff(array $payload): array
    {
        $staff = $this->hireStaffMember->handle(
            firstName: (string) $payload['first_name'],
            lastName: (string) $payload['last_name'],
            gender: mb_strtolower((string) $payload['gender']) === 'f' || mb_strtolower((string) $payload['gender']) === 'female'
                ? 'female' : 'male',
            dateOfBirth: (string) $payload['date_of_birth'],
            phone: (string) $payload['phone'],
            hiredOn: (string) $payload['hired_on'],
            otherNames: isset($payload['middle_name']) ? (string) $payload['middle_name'] : null,
            email: isset($payload['email']) ? (string) $payload['email'] : null,
        );

        // A plain string, not StaffMember::class - see the class docblock.
        return ['App\\Modules\\HR\\Models\\StaffMember', (int) $staff->getKey()];
    }

    private function normaliseGender(string $gender): Gender
    {
        return match (mb_strtolower($gender)) {
            'm', 'male' => Gender::Male,
            'f', 'female' => Gender::Female,
            default => throw new DomainException("Unrecognised gender '{$gender}'."),
        };
    }
}
