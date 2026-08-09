<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\AnnualComposition;
use App\Modules\Assessment\Domain\GradingPipeline;
use App\Modules\Assessment\Domain\PeriodContribution;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\PeriodResult;
use App\Support\Score\Score;
use Illuminate\Support\Facades\DB;

/**
 * READ DOOR (docs/plans/phase-08.md F4): the promotion engine's
 * `annual_average` criterion — "the same annual-average service the report
 * card uses" (07-students §10.4, non-negotiable).
 *
 * All arithmetic is `GradingPipeline::annualAverage`, THE one implementation
 * (01-assessment §9.4/T23 make a second one a review-blocking defect: v1's
 * promotion engine had its own mean, so the bulletin printed 13.13 and the
 * promotion list said 13.28 for the same student in the same run). This
 * Action only assembles that function's inputs from the persisted
 * `period_results` stage-5 output — it never re-averages marks itself.
 *
 * NULL is honest: an enrollment with no assessed leaf period has NO annual
 * average — the promotion criterion treats it as indeterminate, never a pass
 * and never a zero.
 *
 * Deliberately not Gate-checked: consumed inside Actions that carry their own
 * authorization (EvaluatePromotionRun), the ResolveCalendarDay pattern.
 */
final class GetAnnualAveragesForEnrollments
{
    public function __construct(private readonly GradingPipeline $pipeline = new GradingPipeline) {}

    /**
     * @param  array<int, int>  $enrollmentIds
     * @return array<int, string|null> enrollment id => annual average to 3 dp
     *         (Score::toString), or NULL when unassessed. Every requested id
     *         is present in the result.
     */
    public function handle(int $academicYearId, array $enrollmentIds): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $enrollmentIds,
        )));

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = null;
        }

        if ($ids === []) {
            return $result;
        }

        $periods = $this->periods($academicYearId);

        if ($periods === []) {
            return $result;
        }

        $composition = $this->composition($periods);

        $leafIds = $this->leafPeriodIds($periods);
        $termIds = $this->termPeriodIds($periods);
        $childRows = $this->rootChildren($periods);

        $neededPeriodIds = array_values(array_unique(array_merge(
            $leafIds,
            $termIds,
            array_map(static fn (array $row): int => (int) $row['id'], $childRows),
        )));

        if ($neededPeriodIds === []) {
            return $result;
        }

        /** @var array<int, array<int, PeriodResult>> $byEnrollment */
        $byEnrollment = [];

        PeriodResult::query()
            ->whereIn('enrollment_id', $ids)
            ->whereIn('assessment_period_id', $neededPeriodIds)
            ->get()
            ->each(function (PeriodResult $row) use (&$byEnrollment): void {
                $byEnrollment[$row->enrollment_id][$row->assessment_period_id] = $row;
            });

        foreach ($ids as $enrollmentId) {
            $rows = $byEnrollment[$enrollmentId] ?? [];

            $composed = $this->pipeline->annualAverage(
                $composition,
                leafPeriodAverages: $this->averagesFor($rows, $leafIds),
                termAverages: $this->averagesFor($rows, $termIds),
                weightedChildren: $this->contributionsFor($rows, $childRows),
            );

            $result[$enrollmentId] = $composed->score?->toString();
        }

        return $result;
    }

    /**
     * The `subject_minimum` criterion's number (07-students §10.4): the
     * per-subject annual average — unweighted mean over the year's leaf
     * periods of the subject's stage-4 score, read from the persisted
     * `subject_scores` payload ComputePeriodResults stores precisely so that
     * "10.6 subject rank and 10.7 statistics read one table instead of
     * replaying the pipeline per figure". The mean itself is
     * Score::weightedAverage — the same arithmetic the GradingPipeline's
     * meanOf uses.
     *
     * @param  array<int, int>  $enrollmentIds
     * @return array<int, string|null> enrollment id => subject annual average
     *         to 3 dp, NULL when the subject was never assessed.
     */
    public function subjectAnnualAverages(int $academicYearId, array $enrollmentIds, int $subjectId): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $enrollmentIds,
        )));

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = null;
        }

        if ($ids === []) {
            return $result;
        }

        $allocationIds = [];

        $allocationRows = DB::table('subject_allocations')
            ->where('academic_year_id', $academicYearId)
            ->where('subject_id', $subjectId)
            ->pluck('id');

        foreach ($allocationRows as $allocationId) {
            $allocationIds[] = (int) $allocationId;
        }

        if ($allocationIds === []) {
            return $result;
        }

        $periods = $this->periods($academicYearId);
        $leafIds = $this->leafPeriodIds($periods);

        if ($leafIds === []) {
            return $result;
        }

        $rows = PeriodResult::query()
            ->whereIn('enrollment_id', $ids)
            ->whereIn('assessment_period_id', $leafIds)
            ->orderBy('assessment_period_id')
            ->get();

        /** @var array<int, list<array{Score, int}>> $scoresByEnrollment */
        $scoresByEnrollment = [];

        foreach ($rows as $row) {
            // PHP re-keys decimal-string array keys to ints on decode, so the
            // payload is normalised to INT keys before lookup.
            /** @var array<int, mixed> $payload */
            $payload = [];

            foreach ($row->subject_scores ?? [] as $key => $entry) {
                if (is_numeric($key)) {
                    $payload[(int) $key] = $entry;
                }
            }

            foreach ($allocationIds as $allocationId) {
                $entry = $payload[$allocationId] ?? null;

                if (! is_array($entry)) {
                    continue;
                }

                $score = $entry['score'] ?? null;

                if (is_string($score) && is_numeric($score)) {
                    $scoresByEnrollment[$row->enrollment_id][] = [Score::of($score), 1];

                    break;
                }
            }
        }

        foreach ($ids as $enrollmentId) {
            $weighted = $scoresByEnrollment[$enrollmentId] ?? [];

            $result[$enrollmentId] = $weighted === []
                ? null
                : Score::weightedAverage($weighted)?->toString();
        }

        return $result;
    }

    /**
     * All the year's periods in one read, as plain arrays — assessment_periods
     * is an Academics table and this module reads it via the query builder.
     *
     * @return list<array<string, mixed>>
     */
    private function periods(int $academicYearId): array
    {
        $periods = [];

        $rows = DB::table('assessment_periods')
            ->where('academic_year_id', $academicYearId)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            /** @var array<string, mixed> $period */
            $period = (array) $row;

            $periods[] = $period;
        }

        return $periods;
    }

    /**
     * The framework's annual_composition — from the first period that names a
     * framework (the year's periods share one). MINESEC default otherwise.
     *
     * @param  list<array<string, mixed>>  $periods
     */
    private function composition(array $periods): AnnualComposition
    {
        foreach ($periods as $period) {
            if (is_numeric($period['framework_id'] ?? null)) {
                $framework = AssessmentFramework::query()->find((int) $period['framework_id']);

                if ($framework !== null) {
                    return AnnualComposition::from($framework->annual_composition);
                }
            }
        }

        return AnnualComposition::MeanOfLeafPeriods;
    }

    /**
     * Leaf = no children and counts toward its parent — the "six sequence
     * averages" of §9.2, in calendar order.
     *
     * @param  list<array<string, mixed>>  $periods
     * @return list<int>
     */
    private function leafPeriodIds(array $periods): array
    {
        $parentIds = [];

        foreach ($periods as $period) {
            if (is_numeric($period['parent_id'] ?? null)) {
                $parentIds[(int) $period['parent_id']] = true;
            }
        }

        $leaves = [];

        foreach ($periods as $period) {
            $id = (int) $period['id'];

            if (! isset($parentIds[$id]) && (bool) $period['counts_toward_parent']) {
                $leaves[] = $id;
            }
        }

        return $leaves;
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @return list<int>
     */
    private function termPeriodIds(array $periods): array
    {
        $ids = [];

        foreach ($periods as $period) {
            if (in_array((string) $period['type'], ['term', 'trimestre'], true)) {
                $ids[] = (int) $period['id'];
            }
        }

        return $ids;
    }

    /**
     * Immediate children of the year root, for `weighted_children`.
     *
     * @param  list<array<string, mixed>>  $periods
     * @return list<array<string, mixed>>
     */
    private function rootChildren(array $periods): array
    {
        $rootId = null;

        foreach ($periods as $period) {
            if ((string) $period['type'] === 'year' && ($period['parent_id'] ?? null) === null) {
                $rootId = (int) $period['id'];

                break;
            }
        }

        if ($rootId === null) {
            return [];
        }

        return array_values(array_filter(
            $periods,
            static fn (array $period): bool => is_numeric($period['parent_id'] ?? null)
                && (int) $period['parent_id'] === $rootId,
        ));
    }

    /**
     * @param  array<int, PeriodResult>  $rows  keyed by assessment_period_id
     * @param  list<int>  $periodIds
     * @return list<Score|null>
     */
    private function averagesFor(array $rows, array $periodIds): array
    {
        $averages = [];

        foreach ($periodIds as $periodId) {
            $rounded = ($rows[$periodId] ?? null)?->general_average_rounded;

            $averages[] = $rounded === null ? null : Score::of($rounded);
        }

        return $averages;
    }

    /**
     * @param  array<int, PeriodResult>  $rows  keyed by assessment_period_id
     * @param  list<array<string, mixed>>  $childRows
     * @return list<PeriodContribution>
     */
    private function contributionsFor(array $rows, array $childRows): array
    {
        $contributions = [];

        foreach ($childRows as $child) {
            $id = (int) $child['id'];
            $rounded = ($rows[$id] ?? null)?->general_average_rounded;

            $contributions[] = new PeriodContribution(
                key: $id,
                score: $rounded === null ? null : Score::of($rounded),
                weightTenThousandths: max(1, (int) round(((float) $child['weight']) * 10_000)),
                countsTowardParent: (bool) $child['counts_toward_parent'],
            );
        }

        return $contributions;
    }
}
