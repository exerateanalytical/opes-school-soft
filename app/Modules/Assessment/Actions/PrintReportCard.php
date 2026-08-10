<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\ReportCardSnapshot;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §6.1 - prints the bulletin for one enrolment in
 * one assessment period.
 *
 * This Action assembles NOTHING. `RPT-CARD` is snapshot-backed against a
 * REGISTERED source (Reporting\Domain\SnapshotSourceMap maps
 * `ReportCardSnapshot` -> report_card_snapshots.payload), so RenderDocument
 * loads the immutable published payload itself and the Blade renders it. All
 * this class does is resolve WHICH snapshot - which is the whole of the job,
 * because printing the wrong generation of a bulletin is printing a
 * superseded permanent record.
 *
 * A period that has not been published has no snapshot, and this refuses
 * rather than falling back to a live read: 01-assessment §13's re-render
 * guarantee exists precisely so that no bulletin is ever derived from
 * `marks` after the fact.
 */
final class PrintReportCard
{
    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $enrollmentId, int $periodId, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::AcademicsView->value);

        /** @var ReportCardSnapshot|null $snapshot */
        $snapshot = ReportCardSnapshot::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('assessment_period_id', $periodId)
            // The CURRENT card is the one nothing supersedes. An amendment
            // (01-assessment §15) writes a new generation and points the old
            // one at it; the old one stays printable only as history, never as
            // the card a guardian is handed.
            ->whereNull('superseded_by_snapshot_id')
            ->orderByDesc('generation')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            throw new DomainException(
                "No published report card exists for enrolment {$enrollmentId} in period {$periodId}. "
                .'A bulletin is issued from the publication snapshot, never re-derived from marks '
                .'(01-assessment §13), so the period must be published first.'
            );
        }

        return $this->render->handle(
            templateCode: 'RPT-CARD',
            subjectType: 'Enrollment',
            subjectId: $enrollmentId,
            subjectLabel: $this->label($enrollmentId, $periodId),
            snapshotId: (int) $snapshot->getKey(),
            language: $language,
            data: [],
            // The RPT series is academic-year scoped; the year the card belongs
            // to is the class group's academic year, not the year the print
            // button happens to be clicked in.
            seriesScopeValue: $this->academicYear($snapshot),
        );
    }

    /**
     * Denormalised onto the print log, so it must survive a later rename.
     */
    private function label(int $enrollmentId, int $periodId): string
    {
        $row = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.id', $enrollmentId)
            ->first(['s.first_name', 's.last_name', 's.matricule']);

        $period = DB::table('assessment_periods')->where('id', $periodId)->value('name');

        if ($row === null) {
            return 'Report card for enrolment '.$enrollmentId;
        }

        return trim((string) $row->first_name.' '.(string) $row->last_name)
            .' ('.(string) $row->matricule.') - '
            .(is_string($period) ? $period : 'period '.$periodId);
    }

    private function academicYear(ReportCardSnapshot $snapshot): ?string
    {
        $year = DB::table('class_groups as cg')
            ->join('academic_years as ay', 'ay.id', '=', 'cg.academic_year_id')
            ->where('cg.id', $snapshot->class_group_id)
            ->value('ay.starts_on');

        return is_string($year) ? substr($year, 0, 4) : null;
    }
}
