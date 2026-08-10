<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions\Import;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\ImportBatchStatus;
use App\Modules\Students\Domain\ImportKind;
use App\Modules\Students\Domain\ImportRowStatus;
use App\Modules\Students\Models\ImportBatch;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 1 of 3 - parse a CSV into `import_rows` and write NOTHING to the
 * domain (00-core §15 Phase 2).
 *
 * Separating this from validation and from the commit is what makes a dry
 * run possible: the operator sees every row the file contains, and later
 * which of them would fail, before a single student exists. An importer that
 * parsed and created in one pass would leave a half-imported school behind
 * on the first malformed line.
 *
 * Unknown columns are ignored rather than rejected - a school's export
 * carries its own extra fields, and refusing the file over a column nobody
 * asked about would send them back to typing.
 */
final class StageImportBatch
{
    public function handle(ImportKind $kind, string $filename, string $csv): ImportBatch
    {
        Gate::authorize(Permission::StudentsManage->value);

        $user = Auth::user();

        if ($user === null) {
            throw new DomainException('Staging an import is an audited act; it needs a user.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));

        if ($lines === []) {
            throw new DomainException('The uploaded file is empty.');
        }

        $header = array_map(
            static fn (string $h): string => str_replace(' ', '_', mb_strtolower(trim($h))),
            str_getcsv(array_shift($lines))
        );

        $missing = array_diff($kind->requiredColumns(), $header);

        if ($missing !== []) {
            throw new DomainException(sprintf(
                'The file is missing required column(s): %s. Expected header: %s',
                implode(', ', $missing),
                implode(', ', $kind->allColumns()),
            ));
        }

        $known = $kind->allColumns();

        return DB::transaction(function () use ($kind, $filename, $csv, $lines, $header, $known, $user): ImportBatch {
            $batch = ImportBatch::query()->create([
                'kind' => $kind->value,
                'original_filename' => $filename,
                'sha256' => hash('sha256', $csv),
                'status' => ImportBatchStatus::Staged->value,
                'row_count' => 0,
                'uploaded_by' => (int) $user->getKey(),
                'uploaded_at' => now(),
            ]);

            $rowNo = 0;

            foreach ($lines as $line) {
                $cells = str_getcsv($line);
                $payload = [];

                foreach ($header as $index => $column) {
                    if (! in_array($column, $known, true)) {
                        continue;
                    }

                    $value = $cells[$index] ?? null;
                    $value = is_string($value) ? trim($value) : $value;
                    $payload[$column] = ($value === '' ? null : $value);
                }

                $rowNo++;

                $batch->rows()->create([
                    'row_no' => $rowNo,
                    'payload' => $payload,
                    'status' => ImportRowStatus::Pending->value,
                ]);
            }

            $batch->forceFill(['row_count' => $rowNo])->save();

            return $batch->refresh();
        });
    }
}
