<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions\Import;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Actions\CreateStudent;
use App\Modules\Students\Domain\Gender;
use App\Modules\Students\Domain\ImportBatchStatus;
use App\Modules\Students\Domain\ImportKind;
use App\Modules\Students\Domain\ImportRowStatus;
use App\Modules\Students\Models\ImportBatch;
use App\Modules\Students\Models\ImportRow;
use App\Modules\Students\Models\Student;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Phase 3 of 3 - create the real records, one domain Action call per valid
 * row (00-core §15 Phase 2).
 *
 * It calls `CreateStudent` rather than INSERTing, because that Action
 * allocates the matricule, derives the status and writes the audit entry. An
 * importer writing to `students` directly would bypass all three and produce
 * records the rest of the product does not believe in.
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
    public function __construct(private readonly CreateStudent $createStudent) {}

    public function handle(int $batchId): ImportBatch
    {
        Gate::authorize(Permission::StudentsManage->value);

        $user = Auth::user();

        if ($user === null) {
            throw new DomainException('Committing an import is an audited act; it needs a user.');
        }

        /** @var ImportBatch $batch */
        $batch = ImportBatch::query()->findOrFail($batchId);

        if ($batch->kind !== ImportKind::Students) {
            throw new DomainException(sprintf(
                'Only the students import can be committed today; %s staging and validation work, '
                .'but their domain Actions are not wired to this pipeline yet.',
                $batch->kind->value,
            ));
        }

        $imported = 0;
        $failed = 0;

        $batch->rows()
            ->where('status', ImportRowStatus::Valid->value)
            ->orderBy('row_no')
            ->chunkById(100, function ($rows) use (&$imported, &$failed): void {
                /** @var ImportRow $row */
                foreach ($rows as $row) {
                    try {
                        DB::transaction(function () use ($row): void {
                            $student = $this->createStudentFrom($row->payload);

                            $row->forceFill([
                                'status' => ImportRowStatus::Imported->value,
                                'errors' => null,
                                'imported_record_type' => Student::class,
                                'imported_record_id' => (int) $student->getKey(),
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
     */
    private function createStudentFrom(array $payload): Student
    {
        $gender = mb_strtolower((string) ($payload['gender'] ?? ''));
        $gender = match ($gender) {
            'm', 'male' => Gender::Male,
            'f', 'female' => Gender::Female,
            default => throw new DomainException("Unrecognised gender '{$gender}'."),
        };

        return $this->createStudent->handle(
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
    }
}
