<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildAcademics;
use App\Modules\Guardians\Support\Portal\PublishedResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Slice C - results, report cards, attendance, discipline, timetable
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4, rows 5-12, 16, 19, 20,
 * 26).
 *
 * Every read here is narrowed twice: by the matrix row (may this guardian see
 * this KIND of thing for this child) and by a query conjunct that the matrix
 * explicitly refuses to own (is this particular row publishable to a
 * guardian at all). The second is not a convenience - the matrix header names
 * each one and says it belongs to the query.
 */
final class AcademicsController
{
    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly PublishedResults $results,
        private readonly ChildAcademics $academics,
    ) {
    }

    /**
     * `GET /v1/me/children/{student}/results` - rows 5, 7, 9, 10.
     *
     * The list is the periods; each entry carries the stored snapshot payload.
     * `rank` is stripped without row 9. Promotion appears only with row 10 AND
     * an applied decision.
     */
    public function results(int $student): JsonResponse
    {
        $this->requireChild($student);

        if (! $this->policy->allows(GuardianCapability::R05ViewReportCard, $student)) {
            abort(403);
        }

        $canRank = $this->policy->allows(GuardianCapability::R09ViewRankAndClassMean, $student);
        $canPromotion = $this->policy->allows(GuardianCapability::R10ViewAnnualAverageAndPromotion, $student);

        $periods = [];

        foreach ($this->results->snapshots($student) as $snapshot) {
            $entry = [
                'snapshot_id' => (int) $snapshot->id,
                'period' => [
                    'id' => (int) $snapshot->assessment_period_id,
                    'name' => $snapshot->period_name,
                    'name_fr' => $snapshot->period_name_fr,
                ],
                'generation' => (int) $snapshot->generation,
                'issued_at' => $snapshot->issued_at,
                'payload' => $this->results->payload($snapshot, $canRank),
            ];

            if ($canPromotion) {
                $promotion = $this->results->appliedPromotion((int) $snapshot->enrollment_id);

                if ($promotion !== null) {
                    $entry['promotion'] = $promotion;
                }
            }

            $periods[] = $entry;
        }

        return response()->json([
            'data' => [
                'can_download' => $this->policy->allows(GuardianCapability::R06DownloadReportCard, $student),
                'periods' => $periods,
            ],
        ]);
    }

    /**
     * `GET /v1/me/children/{student}/attendance` - rows 11 and 12.
     *
     * Two scopes again, and the split matters: row 11 is the summary a parent
     * sees on a dashboard, row 12 is every session with its justification
     * state. A link holding only one gets only that one.
     */
    public function attendance(int $student): JsonResponse
    {
        $this->requireChild($student);

        $canSummary = $this->policy->allows(GuardianCapability::R11ViewAttendanceSummary, $student);
        $canDetail = $this->policy->allows(GuardianCapability::R12ViewAttendanceDetail, $student);

        if (! $canSummary && ! $canDetail) {
            abort(403);
        }

        return response()->json([
            'data' => [
                'scope' => $canDetail ? 'detail' : 'summary',
                'summaries' => $this->academics->attendanceSummaries($student)->all(),
                // Row 12 only. A summary-only link gets an empty list, not a
                // truncated one - the difference is the whole point of the row.
                'records' => $canDetail ? $this->academics->attendanceRecords($student)->all() : [],
            ],
        ]);
    }

    /**
     * `GET /v1/me/children/{student}/discipline` - rows 19 and 20.
     *
     * `visibility = 'guardian'` is the query conjunct row 20's comment assigns
     * here: a case naming another minor is `internal` and invisible to every
     * guardian, whatever their flags. The NARRATIVE (description, resolution
     * note, sanction notes) is additionally row 20 - a guardian may be
     * entitled to know a case exists without being entitled to read its prose.
     */
    public function discipline(int $student): JsonResponse
    {
        $this->requireChild($student);

        if (! $this->policy->allows(GuardianCapability::R19ViewDisciplineList, $student)) {
            abort(403);
        }

        $canNarrative = $this->policy->allows(GuardianCapability::R20ViewDisciplineNarrative, $student);

        $cases = DB::table('discipline_cases as c')
            ->join('discipline_categories as cat', 'cat.id', '=', 'c.discipline_category_id')
            ->where('c.student_id', $student)
            ->where('c.visibility', 'guardian')
            ->orderByDesc('c.occurred_on')
            ->get([
                'c.id', 'c.occurred_on', 'c.status', 'c.is_positive',
                'c.description', 'c.resolution_note',
                'cat.name as category_name', 'cat.name_fr as category_name_fr',
            ]);

        $caseIds = $cases->pluck('id')->all();

        $sanctions = $caseIds === [] ? collect() : DB::table('discipline_sanctions')
            ->whereIn('discipline_case_id', $caseIds)
            ->orderByDesc('starts_on')
            ->get(['id', 'discipline_case_id', 'type', 'starts_on', 'ends_on', 'acknowledged_at', 'notes']);

        $byCase = $sanctions->groupBy('discipline_case_id');

        $data = $cases->map(function (\stdClass $case) use ($byCase, $canNarrative): array {
            $row = [
                'id' => (int) $case->id,
                'occurred_on' => $case->occurred_on,
                'status' => $case->status,
                'is_positive' => (bool) $case->is_positive,
                'category' => ['name' => $case->category_name, 'name_fr' => $case->category_name_fr],
                'sanctions' => collect($byCase->get($case->id, collect()))
                    ->map(static fn ($sanction): array => [
                        'id' => (int) $sanction->id,
                        'type' => $sanction->type,
                        'starts_on' => $sanction->starts_on,
                        'ends_on' => $sanction->ends_on,
                        'acknowledged_at' => $sanction->acknowledged_at,
                    ])->values()->all(),
            ];

            if ($canNarrative) {
                $row['description'] = $case->description;
                $row['resolution_note'] = $case->resolution_note;
            }

            return $row;
        })->values()->all();

        return response()->json([
            'data' => [
                'can_read_narrative' => $canNarrative,
                'can_acknowledge' => $this->policy->allows(GuardianCapability::R21AcknowledgeSanction, $student),
                'cases' => $data,
            ],
        ]);
    }

    /**
     * `GET /v1/me/children/{student}/timetable` - row 26, granted on any valid
     * link. The child's CLASS timetable, effective today, ordered as a week.
     */
    public function timetable(int $student): JsonResponse
    {
        $this->requireChild($student);

        if (! $this->policy->allows(GuardianCapability::R26ViewTimetableAndAnnouncements, $student)) {
            abort(403);
        }

        return response()->json([
            'data' => ['slots' => $this->academics->timetable($student)->all()],
        ]);
    }

    /** Row 32: no valid link, no child - and no confirmation that one exists. */
    private function requireChild(int $student): void
    {
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $student)) {
            abort(404);
        }
    }
}
