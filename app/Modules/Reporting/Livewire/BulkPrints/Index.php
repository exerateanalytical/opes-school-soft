<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Livewire\BulkPrints;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\ProcessBulkPrint;
use App\Modules\Reporting\Actions\QueueBulkPrint;
use App\Modules\Reporting\Models\BulkPrintJob;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * docs/specs/10-documents.md §18 - the Bulk Prints screen.
 *
 * Queues and lists BulkPrintJobs. Nothing renders here: the screen calls
 * QueueBulkPrint / ProcessBulkPrint, which call RenderDocument's batch
 * signature. There is no second renderer anywhere in this file.
 *
 * Strings are literal English on purpose: lang/en|fr/opes.php is being edited
 * concurrently by another build and this screen must not add keys to it.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    public string $templateCode = '';

    public string $academicYearId = '';

    public string $classGroupId = '';

    public string $assessmentPeriodId = '';

    public string $mode = 'all';

    public string $language = 'en';

    public ?int $expandedJobId = null;

    public function mount(): void
    {
        Gate::authorize(Permission::DocumentsBulkPrint->value);

        $this->academicYearId = (string) (DB::table('academic_years')
            ->orderByDesc('starts_on')
            ->value('id') ?? '');

        $this->templateCode = (string) (DocumentTemplate::query()
            ->where('is_active', true)
            ->where('bulk_printable', true)
            ->whereIn('code', ProcessBulkPrint::DRIVEABLE)
            ->orderBy('code')
            ->value('code') ?? '');
    }

    /**
     * Queue AND run in the same click.
     *
     * §18.2 wants a queued worker (a 1 200-card run must not hold an HTTP
     * request open) and this is deliberately NOT pretending to be one: the
     * run is synchronous, which is honest for the class-sized batches this
     * screen can currently target. Moving it behind the queue is a one-line
     * change at this call site once a job class exists.
     */
    public function queueJob(QueueBulkPrint $queue, ProcessBulkPrint $processor): void
    {
        Gate::authorize(Permission::DocumentsBulkPrint->value);

        try {
            $job = $queue->handle(
                templateCode: $this->templateCode,
                academicYearId: (int) $this->academicYearId,
                classGroupId: $this->classGroupId === '' ? null : (int) $this->classGroupId,
                assessmentPeriodId: $this->assessmentPeriodId === '' ? null : (int) $this->assessmentPeriodId,
                mode: $this->mode,
                language: $this->language,
            );

            $processor->handle($job);

            session()->flash('status', 'Bulk print job #'.$job->getKey().' finished.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * §18.2's resumability: a failed or partial job is retried by queueing the
     * same selection in `unprinted` mode, which picks up exactly the subjects
     * that produced nothing. The original row is never rewritten - its counts
     * are the record of what happened that day.
     */
    public function retry(int $jobId, QueueBulkPrint $queue, ProcessBulkPrint $processor): void
    {
        Gate::authorize(Permission::DocumentsBulkPrint->value);

        /** @var BulkPrintJob|null $original */
        $original = BulkPrintJob::query()->find($jobId);

        if ($original === null || ! $original->isRetryable()) {
            session()->flash('error', 'Only a failed or partial job can be retried.');

            return;
        }

        try {
            $template = DocumentTemplate::query()->findOrFail($original->document_template_id);

            $job = $queue->handle(
                templateCode: $template->code,
                academicYearId: $original->academic_year_id,
                classGroupId: $original->class_group_id,
                assessmentPeriodId: $original->assessment_period_id,
                mode: 'unprinted',
                language: $original->language,
            );

            $processor->handle($job);

            session()->flash('status', 'Retried as job #'.$job->getKey().'.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function toggleJob(int $jobId): void
    {
        $this->expandedJobId = $this->expandedJobId === $jobId ? null : $jobId;
    }

    /**
     * Serve a file this job already produced.
     *
     * This is NOT a render: RenderDocument wrote the bytes and logged the
     * print at run time; re-downloading the same artefact must not allocate a
     * serial or add a print-log row, so nothing here touches RenderDocument.
     */
    public function download(int $jobId, int $documentIndex): ?BinaryFileResponse
    {
        Gate::authorize(Permission::DocumentsPrint->value);

        /** @var BulkPrintJob|null $job */
        $job = BulkPrintJob::query()->find($jobId);

        $documents = $job === null ? [] : $this->documentsFor($job);
        $document = $documents[$documentIndex] ?? null;

        if ($document === null) {
            session()->flash('error', 'That document is no longer on disk.');

            return null;
        }

        $absolute = storage_path((string) $document['path']);

        if (! is_file($absolute)) {
            session()->flash('error', 'That document is no longer on disk.');

            return null;
        }

        return response()->download($absolute);
    }

    /**
     * The per-subject files this job produced, read from the index
     * ProcessBulkPrint wrote. Empty for a job that produced nothing, which is
     * shown as such rather than as a broken link.
     *
     * @return list<array{subject_id: int, serial: string|null, path: string, print_log_id: int, is_duplicate: bool}>
     */
    private function documentsFor(BulkPrintJob $job): array
    {
        if ($job->output_path === null) {
            return [];
        }

        $absolute = storage_path($job->output_path);

        if (! is_file($absolute)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($absolute), true);

        if (! is_array($raw) || ! isset($raw['documents']) || ! is_array($raw['documents'])) {
            return [];
        }

        /** @var list<array{subject_id: int, serial: string|null, path: string, print_log_id: int, is_duplicate: bool}> $documents */
        $documents = array_values($raw['documents']);

        return $documents;
    }

    public function render(): mixed
    {
        $canQueue = Gate::allows(Permission::DocumentsBulkPrint->value);

        /** @var BulkPrintJob|null $expanded */
        $expanded = $this->expandedJobId === null
            ? null
            : BulkPrintJob::query()->find($this->expandedJobId);

        return view('livewire.reporting.bulk-prints.index', [
            'canQueue' => $canQueue,
            // Resolved here rather than called from the view: a Blade partial
            // that reaches back into the component is a second entry point
            // into a file read, and the file read is permissioned.
            'expandedDocuments' => $expanded === null ? [] : $this->documentsFor($expanded),
            'templates' => DocumentTemplate::query()
                ->where('is_active', true)
                ->where('bulk_printable', true)
                ->whereIn('code', ProcessBulkPrint::DRIVEABLE)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'paper_size']),
            'notYetDriveable' => DocumentTemplate::query()
                ->where('is_active', true)
                ->where('bulk_printable', true)
                ->whereNotIn('code', ProcessBulkPrint::DRIVEABLE)
                ->orderBy('code')
                ->pluck('code')
                ->all(),
            'academicYears' => DB::table('academic_years')
                ->orderByDesc('starts_on')
                ->get(['id', 'name']),
            'classGroups' => $this->academicYearId === ''
                ? collect()
                : DB::table('class_groups')
                    ->where('academic_year_id', (int) $this->academicYearId)
                    ->orderBy('name')
                    ->get(['id', 'name']),
            'assessmentPeriods' => $this->academicYearId === ''
                ? collect()
                : DB::table('assessment_periods')
                    ->where('academic_year_id', (int) $this->academicYearId)
                    ->orderBy('order_index')
                    ->get(['id', 'name']),
            'jobs' => BulkPrintJob::query()
                ->with('template')
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }
}
