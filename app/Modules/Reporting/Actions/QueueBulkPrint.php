<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Models\BulkPrintJob;
use App\Modules\Reporting\Models\DocumentTemplate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §18.2 - queue a bulk print run.
 *
 * Queue only: it validates the selection, pins the template VERSION at queue
 * time (so a template edited mid-run cannot change what the batch prints) and
 * records `total` from the resolved selection. `ProcessBulkPrint` does the
 * rendering.
 */
final class QueueBulkPrint
{
    public function __construct(private readonly ProcessBulkPrint $processor) {}

    /**
     * @param  list<int>  $subjectIds  mode=selected only
     */
    public function handle(
        string $templateCode,
        int $academicYearId,
        ?int $classGroupId,
        ?int $assessmentPeriodId,
        string $mode = 'all',
        array $subjectIds = [],
        ?string $language = null,
        int $copies = 1,
    ): BulkPrintJob {
        Gate::authorize(Permission::DocumentsBulkPrint->value);

        if (! in_array($mode, ['all', 'unprinted', 'selected'], true)) {
            throw new DomainException("Unknown bulk print mode [{$mode}].");
        }

        if ($mode === 'selected' && $subjectIds === []) {
            throw new DomainException('Print Selected was chosen but no subject is selected.');
        }

        /** @var DocumentTemplate|null $template */
        $template = DocumentTemplate::query()->where('code', $templateCode)->first();

        if ($template === null || ! $template->is_active) {
            throw new DomainException("Document template [{$templateCode}] is not available.");
        }

        if (! $template->bulk_printable) {
            // §18.1: the document-type list is extensible via this flag, so
            // the flag is the gate, not a hard-coded list in a screen.
            throw new DomainException(
                "Template [{$templateCode}] is not marked bulk_printable (10-documents §18.1)."
            );
        }

        if (! DB::table('academic_years')->where('id', $academicYearId)->exists()) {
            throw new DomainException('The selected academic year does not exist.');
        }

        $job = BulkPrintJob::query()->create([
            'document_template_id' => $template->getKey(),
            'template_version' => $template->version,
            'academic_year_id' => $academicYearId,
            'class_group_id' => $classGroupId,
            'assessment_period_id' => $assessmentPeriodId,
            'mode' => $mode,
            'subject_ids' => $mode === 'selected' ? $subjectIds : null,
            'language' => $language ?? 'en',
            'paper_size' => $template->paper_size,
            'copies' => max(1, $copies),
            'collate' => true,
            'duplex' => $template->duplex === 'double_sided' ? 'double_sided' : 'none',
            'status' => 'queued',
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'requested_by' => (int) auth()->id(),
            'requested_at' => now(),
        ]);

        // Resolving here surfaces "this selection prints nothing" (an
        // unpublished period, an empty class) at the click, not silently
        // inside a worker minutes later. A resolution failure deletes the row
        // rather than leaving a queued job nothing can ever run.
        try {
            $job->update(['total' => count($this->processor->subjectsFor($job))]);
        } catch (\Throwable $e) {
            $job->forceDelete();

            throw new DomainException($e->getMessage(), previous: $e);
        }

        return $job->refresh();
    }
}
