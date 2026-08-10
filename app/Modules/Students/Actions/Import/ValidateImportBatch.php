<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions\Import;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\ImportBatchStatus;
use App\Modules\Students\Domain\ImportKind;
use App\Modules\Students\Domain\ImportRowStatus;
use App\Modules\Students\Models\ImportBatch;
use App\Modules\Students\Models\ImportRow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 2 of 3 - judge every staged row and write NOTHING to the domain
 * (00-core §15 Phase 2).
 *
 * This is the dry run. Every row gets a verdict and, when it fails, the
 * per-field reasons - so the operator repairs the source file once rather
 * than discovering the fourth problem after the third import.
 *
 * An invalid row never blocks a valid one. A school of 1 200 students whose
 * file has 8 bad dates should import 1 192 and be told about 8, not be
 * refused wholesale.
 */
final class ValidateImportBatch
{
    public function handle(int $batchId): ImportBatch
    {
        Gate::authorize(Permission::StudentsManage->value);

        /** @var ImportBatch $batch */
        $batch = ImportBatch::query()->findOrFail($batchId);

        $rules = $this->rulesFor($batch->kind);

        $valid = 0;
        $invalid = 0;

        $batch->rows()
            ->whereIn('status', [ImportRowStatus::Pending->value, ImportRowStatus::Invalid->value, ImportRowStatus::Valid->value])
            ->orderBy('row_no')
            ->chunkById(200, function ($rows) use ($rules, &$valid, &$invalid): void {
                /** @var ImportRow $row */
                foreach ($rows as $row) {
                    $validator = Validator::make($row->payload, $rules);

                    if ($validator->fails()) {
                        $invalid++;
                        $row->forceFill([
                            'status' => ImportRowStatus::Invalid->value,
                            'errors' => $validator->errors()->toArray(),
                        ])->save();

                        continue;
                    }

                    $valid++;
                    $row->forceFill([
                        'status' => ImportRowStatus::Valid->value,
                        'errors' => null,
                    ])->save();
                }
            });

        $batch->forceFill([
            'valid_count' => $valid,
            'invalid_count' => $invalid,
            'status' => ImportBatchStatus::Validated->value,
        ])->save();

        return $batch->refresh();
    }

    /**
     * @return array<string, string>
     */
    private function rulesFor(ImportKind $kind): array
    {
        return match ($kind) {
            ImportKind::Students => [
                'first_name' => 'required|string|max:120',
                'last_name' => 'required|string|max:120',
                'date_of_birth' => 'required|date|before:today',
                'gender' => 'required|string|in:male,female,m,f',
                'email' => 'nullable|email|max:190',
                'phone' => 'nullable|string|max:40',
            ],
            ImportKind::Guardians => [
                'first_name' => 'required|string|max:120',
                'last_name' => 'required|string|max:120',
                'phone' => 'required|string|max:40',
                'email' => 'nullable|email|max:190',
                'relationship' => 'nullable|string|in:father,mother,stepfather,stepmother,grandparent,uncle,aunt,sibling,legal_guardian,sponsor,other',
                // NOT validated against the students table here - Validate
                // is a pure dry run with no domain reads by design, and a
                // matricule that does not resolve is not a row ERROR, it
                // just leaves the guardian unlinked (CommitImportBatch).
                'student_matricule' => 'nullable|string|max:40',
            ],
            ImportKind::Staff => [
                'first_name' => 'required|string|max:120',
                'last_name' => 'required|string|max:120',
                'hired_on' => 'required|date',
                'gender' => 'required|string|in:male,female,m,f',
                'date_of_birth' => 'required|date|before:today',
                'phone' => 'required|string|max:40',
                'email' => 'nullable|email|max:190',
            ],
        };
    }
}
