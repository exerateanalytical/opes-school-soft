<?php

declare(strict_types=1);

namespace App\Modules\Students\Livewire\Import;

use App\Modules\Students\Actions\Import\CommitImportBatch;
use App\Modules\Students\Actions\Import\StageImportBatch;
use App\Modules\Students\Actions\Import\ValidateImportBatch;
use App\Modules\Students\Domain\ImportKind;
use App\Modules\Students\Models\ImportBatch;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * The import screen (00-core §15 Phase 2): upload, see the dry-run report,
 * then commit.
 *
 * The commit button is deliberately unavailable until the batch has been
 * validated and has at least one valid row. The operator should never be one
 * click away from importing a file they have not seen judged.
 */
final class Index extends Component
{
    public string $kind = 'students';

    public string $filename = '';

    public string $csv = '';

    public ?int $batchId = null;

    public string $message = '';

    public string $error = '';

    public function stage(): void
    {
        $this->message = '';
        $this->error = '';

        try {
            $batch = app(StageImportBatch::class)->handle(
                ImportKind::from($this->kind),
                $this->filename !== '' ? $this->filename : 'pasted.csv',
                $this->csv,
            );

            $this->batchId = (int) $batch->getKey();
            $this->message = __('opes.import_screen.staged', ['count' => $batch->row_count]);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function validateBatch(): void
    {
        $this->message = '';
        $this->error = '';

        if ($this->batchId === null) {
            $this->error = __('opes.import_screen.no_batch');

            return;
        }

        try {
            $batch = app(ValidateImportBatch::class)->handle($this->batchId);

            $this->message = __('opes.import_screen.validated', [
                'valid' => $batch->valid_count,
                'invalid' => $batch->invalid_count,
            ]);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function commit(): void
    {
        $this->message = '';
        $this->error = '';

        if ($this->batchId === null) {
            $this->error = __('opes.import_screen.no_batch');

            return;
        }

        try {
            $batch = app(CommitImportBatch::class)->handle($this->batchId);

            $this->message = __('opes.import_screen.committed', ['count' => $batch->imported_count]);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $batch = $this->batchId === null
            ? null
            : ImportBatch::query()->find($this->batchId);

        return view('livewire.students.import.index', [
            'kinds' => ImportKind::cases(),
            'batch' => $batch,
            'rows' => $batch === null
                ? collect()
                : $batch->rows()->orderBy('row_no')->limit(100)->get(),
            'recent' => ImportBatch::query()->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
