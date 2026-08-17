<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\BulkPrintJob;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * docs/specs/10-documents.md §18.2 - runs a queued BulkPrintJob.
 *
 * It renders NOTHING itself: every document goes through
 * `Reporting\Actions\RenderDocument::forSubjects()`, the batch signature
 * 00-core §6.2.5 requires, which is per-subject transactional - one failure
 * marks that subject failed and the job `partial`, and never rolls back or
 * blocks the successes.
 *
 * SCOPE, stated plainly rather than faked: this Action drives templates that
 * are snapshot-backed against a source registered in
 * `Reporting\Domain\SnapshotSourceMap` (today: `RPT-CARD` ->
 * `ReportCardSnapshot`). For those, the payload IS the published snapshot and
 * this Action only has to resolve WHICH snapshot per subject - no payload
 * assembly, so no second copy of an owning module's logic. Templates whose
 * payload is assembled by their owning module's print Action
 * (`FEE-STATEMENT` -> Fees\PrintStatement, `PAYSLIP` -> Payroll\PrintPayslip)
 * are refused at queue time instead of being driven through a duplicated
 * payload builder here; wiring them needs a batch seam on those Actions,
 * which is a change to code this build was told not to modify.
 *
 * §18.2's merge half: `output_path` holds the one combined PDF, built here
 * from the already-rendered, already-hashed per-subject files RenderDocument
 * wrote - the merge never re-renders a document or touches its hash, it only
 * concatenates the finished pages. `manifest_path` holds the JSON index of
 * those per-subject files, which the screen still reads to link to each
 * individually hashed, print-logged document.
 */
final class ProcessBulkPrint
{
    /**
     * The template codes this driver can actually run today.
     *
     * `bulk_printable` is the SPEC's extensibility flag and stays the queue-time
     * gate; this narrower list is the honest statement of what the driver can
     * feed RenderDocument without duplicating an owning module's payload
     * assembly. A screen filters on it so an operator is never offered a
     * document that will only fail on click.
     *
     * @var list<string>
     */
    public const DRIVEABLE = ['RPT-CARD'];

    public function __construct(private readonly RenderDocument $render) {}

    public function handle(BulkPrintJob $job): BulkPrintJob
    {
        $job->update(['status' => 'running', 'started_at' => now()]);

        try {
            $template = $this->template($job);
            $subjects = $this->subjectsFor($job);

            $job->update(['total' => count($subjects)]);

            if ($subjects === []) {
                $job->update([
                    'status' => 'completed',
                    'succeeded' => 0,
                    'failed' => 0,
                    'finished_at' => now(),
                ]);

                return $job->refresh();
            }

            $outcome = $this->render->forSubjects(
                templateCode: $template->code,
                subjectType: self::subjectTypeFor($template->code),
                subjects: $subjects,
                language: $job->language,
                bulkPrintJobId: (int) $job->getKey(),
                seriesScopeValue: $this->seriesScopeValue($job),
            );

            $succeeded = count($outcome['results']);
            $failed = count($outcome['failures']);

            $job->update([
                'succeeded' => $succeeded,
                'failed' => $failed,
                'status' => $failed === 0 ? 'completed' : ($succeeded === 0 ? 'failed' : 'partial'),
                'manifest_path' => $this->writeIndex($job, $outcome),
                'output_path' => $succeeded > 0 ? $this->mergePdfs($job, $outcome) : null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'manifest_path' => $this->writeFailureNote($job, $e->getMessage()),
            ]);
        }

        return $job->refresh();
    }

    /**
     * Which subject entity a template prints for. Kept beside the driver
     * because it must match the owning module's single-print Action exactly -
     * a bulk run that logged a different `subject_type` would split the
     * "who printed this" query in two.
     */
    public static function subjectTypeFor(string $templateCode): string
    {
        return match ($templateCode) {
            'RPT-CARD' => 'Enrollment',
            default => throw new RuntimeException(
                "Template [{$templateCode}] has no registered bulk-print subject type."
            ),
        };
    }

    /**
     * The subjects this job will print for, in the job's own mode.
     *
     * `unprinted` is §18.1's exact definition: subjects in the selection with
     * no successful print log for THIS template, THIS subject and THIS
     * snapshot version - so a reissued (superseded) report card counts as
     * unprinted again.
     *
     * @return list<array{subject_id: int, subject_label: string, snapshot_id: int|null}>
     */
    public function subjectsFor(BulkPrintJob $job): array
    {
        $template = $this->template($job);

        if ($template->code !== 'RPT-CARD') {
            throw new RuntimeException(
                "Template [{$template->code}] is not driveable by the bulk printer yet; its payload is "
                .'assembled by its owning module print Action, which has no batch seam.'
            );
        }

        if ($job->assessment_period_id === null) {
            throw new RuntimeException('A report-card bulk run needs an assessment period.');
        }

        $rows = DB::table('report_card_snapshots as snap')
            ->join('enrollments as e', 'e.id', '=', 'snap.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('snap.assessment_period_id', $job->assessment_period_id)
            // The CURRENT card is the one nothing supersedes - the same rule
            // Assessment\Actions\PrintReportCard applies for a single print.
            ->whereNull('snap.superseded_by_snapshot_id')
            ->when(
                $job->class_group_id !== null,
                fn ($q) => $q->where('snap.class_group_id', $job->class_group_id),
            )
            ->when(
                $job->mode === 'selected',
                fn ($q) => $q->whereIn('snap.enrollment_id', $job->subject_ids ?? [-1]),
            )
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->get([
                'snap.id as snapshot_id',
                'snap.generation',
                'snap.enrollment_id',
                's.first_name',
                's.last_name',
                's.matricule',
            ]);

        $subjects = [];

        foreach ($rows as $row) {
            $enrollmentId = (int) $row->enrollment_id;

            if ($job->mode === 'unprinted' && $this->alreadyPrinted($template, $enrollmentId, (int) $row->generation)) {
                continue;
            }

            $subjects[] = [
                'subject_id' => $enrollmentId,
                'subject_label' => trim((string) $row->first_name.' '.(string) $row->last_name)
                    .' ('.(string) $row->matricule.')',
                'snapshot_id' => (int) $row->snapshot_id,
            ];
        }

        return $subjects;
    }

    private function alreadyPrinted(DocumentTemplate $template, int $subjectId, int $generation): bool
    {
        return DB::table('document_print_logs')
            ->where('document_template_id', $template->getKey())
            ->where('subject_type', self::subjectTypeFor($template->code))
            ->where('subject_id', $subjectId)
            ->where('snapshot_version', $generation)
            ->exists();
    }

    private function template(BulkPrintJob $job): DocumentTemplate
    {
        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->findOrFail($job->document_template_id);

        return $template;
    }

    /**
     * The RPT series is academic-year scoped; the year is the job's year, not
     * the year the button was clicked in.
     */
    private function seriesScopeValue(BulkPrintJob $job): ?string
    {
        $startsOn = DB::table('academic_years')->where('id', $job->academic_year_id)->value('starts_on');

        return is_string($startsOn) ? substr($startsOn, 0, 4) : null;
    }

    /**
     * @param  array{results: array<int, \App\Modules\Reporting\Domain\RenderedDocument>, failures: array<int, string>}  $outcome
     */
    private function writeIndex(BulkPrintJob $job, array $outcome): string
    {
        $documents = [];

        foreach ($outcome['results'] as $subjectId => $document) {
            $documents[] = [
                'subject_id' => (int) $subjectId,
                'serial' => $document->serial,
                'path' => $document->storagePath,
                'print_log_id' => $document->printLogId,
                'is_duplicate' => $document->isDuplicate,
            ];
        }

        return $this->put($job, [
            'job_id' => (int) $job->getKey(),
            'documents' => $documents,
            'failures' => $outcome['failures'],
        ]);
    }

    /**
     * §18.2's merge, finally implemented: import every page of every
     * successfully-rendered subject file, in the SAME order `writeIndex()`
     * lists them, and write one combined PDF. FPDI only imports pages - it
     * never touches the source file's bytes, so the individual per-subject
     * PDFs (and their hashes, already written by RenderDocument) are
     * untouched by this step; the merge is additive output, not a rewrite.
     *
     * @param  array{results: array<int, \App\Modules\Reporting\Domain\RenderedDocument>, failures: array<int, string>}  $outcome
     */
    private function mergePdfs(BulkPrintJob $job, array $outcome): ?string
    {
        if ($outcome['results'] === []) {
            return null;
        }

        $pdf = new Fpdi();

        foreach ($outcome['results'] as $document) {
            $sourcePath = storage_path($document->storagePath);
            $pageCount = $pdf->setSourceFile($sourcePath);

            for ($page = 1; $page <= $pageCount; $page++) {
                $templateId = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']],
                );
                $pdf->useTemplate($templateId);
            }
        }

        $relative = sprintf('documents/bulk/%d-merged.pdf', (int) $job->getKey());
        $absolute = storage_path($relative);
        $dir = dirname($absolute);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdf->Output('F', $absolute);

        return $relative;
    }

    private function writeFailureNote(BulkPrintJob $job, string $message): string
    {
        return $this->put($job, [
            'job_id' => (int) $job->getKey(),
            'documents' => [],
            'failures' => ['job' => $message],
        ]);
    }

    /**
     * @param  array<string, mixed>  $index
     */
    private function put(BulkPrintJob $job, array $index): string
    {
        $relative = sprintf('documents/bulk/%d.json', (int) $job->getKey());
        $absolute = storage_path($relative);
        $dir = dirname($absolute);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($absolute, json_encode($index, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $relative;
    }
}
