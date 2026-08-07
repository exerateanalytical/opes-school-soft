<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\Gpa;
use App\Modules\Assessment\Domain\GradingPipeline;
use App\Modules\Assessment\Domain\PassRule;
use App\Modules\Assessment\Domain\SubjectResult;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Score\Score;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Stage 5 of the pipeline, orchestrated and persisted
 * (docs/specs/01-assessment.md 2.2, 10.1, 10.2, 10.5, 11, 12.6).
 *
 * WHAT THIS CLASS DOES NOT DO: arithmetic. Every number below comes out of
 * `Assessment\Domain` - `GradingPipeline::generalAverage` for the moyenne,
 * `Rounding` for the single rounding, `PassRule` for pass, `Gpa` for the grade
 * -point average. 9.4 and T23 make a second implementation of any of them a
 * review-blocking defect: v1's promotion engine had its own mean, so the
 * bulletin printed 13.13 and the promotion list said 13.28 for the same student
 * in the same run and the school could explain neither.
 *
 * WHAT IT DOES DO is the four decisions the Domain cannot make because they
 * need rows:
 *
 *   1. WHICH CLASS GROUP owns the result - the segment covering
 *      AssessmentPeriod.ends_on (12.6 rule 1);
 *   2. WHICH COHORT the student is ranked in - framework.rank_cohort_rule
 *      (10.4);
 *   3. WHETHER the student is assessable at all - 10.5's NC rules;
 *   4. WHICH BAND the rounded average falls in, which is what turns a score
 *      into a grade point and therefore into a GPA (11).
 *
 * Stage 1 (COLLECT) is deliberately the caller's: it needs `Mark`,
 * `ComponentWeight` and the attendance lookup that `redistribute` consults, and
 * the caller passes the stage-4 `SubjectResult` list this Action aggregates.
 * That keeps this class free of the entry-side schema and lets the report card,
 * the broadsheet and the promotion engine feed it the same values.
 */
final class ComputePeriodResults
{
    public function __construct(private readonly GradingPipeline $pipeline = new GradingPipeline) {}

    /**
     * @param  array<int, list<SubjectResult>>  $subjectResultsByEnrollment  stage-4 output, keyed by enrollment id
     * @return list<PeriodResult>
     */
    public function handle(
        int $assessmentPeriodId,
        array $subjectResultsByEnrollment,
        ?Actor $actor = null,
    ): array {
        // Compiling a class's results is the compilation step of 7.2's flow,
        // between validation and publication; `marks.validate` is the gate the
        // Class Master and the exams office hold and the subject teacher does
        // not.
        Gate::authorize(Permission::MarksValidate->value);

        $writer = $actor ?? $this->currentActor();

        return DB::transaction(function () use (
            $assessmentPeriodId, $subjectResultsByEnrollment, $writer
        ): array {
            $period = $this->period($assessmentPeriodId);
            $framework = $this->framework($period);

            $precision = $framework->score_precision;
            $passScore = Score::of($framework->pass_score);
            $endsOn = (string) $period['ends_on'];

            $results = [];

            foreach ($subjectResultsByEnrollment as $enrollmentId => $subjects) {
                $results[] = $this->computeOne(
                    $assessmentPeriodId,
                    (int) $enrollmentId,
                    $subjects,
                    $framework,
                    $period,
                    $endsOn,
                    $passScore,
                    $precision,
                );
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: PeriodResult::class,
                auditableId: $assessmentPeriodId,
                after: [
                    'assessment_period_id' => $assessmentPeriodId,
                    'framework_id' => (int) $framework->getKey(),
                    'enrollments' => count($results),
                    // 10.2 is the number a head teacher must be able to see
                    // without opening a report card: how many students the
                    // engine could not assess at all.
                    'not_assessed' => count(array_filter(
                        $results,
                        static fn (PeriodResult $r): bool => ! $r->isAssessed(),
                    )),
                ],
                actor: $writer,
            );

            return $results;
        });
    }

    /**
     * 12.6 rule 1, in one place: "rank and class statistics for a period belong
     * to the class group of the segment covering AssessmentPeriod.ends_on".
     * A student who transferred in November is ranked ONCE, in the class they
     * finished the sequence in, and never appears on two rosters for one
     * period (rule 5).
     *
     * `enrollment_segments` is read through the QUERY BUILDER, not through
     * Students\Models\Enrollment::segmentCovering(): that Model belongs to
     * another module and tests/Architecture/ModuleBoundaryTest.php states the
     * rule is absolute. This is the same lookup, expressed in SQL.
     */
    public function owningClassGroupId(int $enrollmentId, string $date): ?int
    {
        $classGroupId = DB::table('enrollment_segments')
            ->where('enrollment_id', $enrollmentId)
            ->where('starts_on', '<=', $date)
            ->where(function (QueryBuilder $query) use ($date): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $date);
            })
            ->orderByDesc('starts_on')
            ->value('class_group_id');

        return is_numeric($classGroupId) ? (int) $classGroupId : null;
    }

    /**
     * 10.4's `rank_cohort_rule`, as a fingerprint.
     *
     * The cohort is NOT "the class". Different elective baskets mean different
     * Sigma-coef and therefore non-comparable averages, and a Cameroonian
     * conseil de classe will reject a ranking that mixes them - a student
     * carrying four sciences and one taking two arts subjects are not competing
     * in the same event.
     *
     *   `all`               everyone in the rank scope, whatever they study
     *   `same_stream`       students sharing Stream.subject_basket. Keyed on the
     *                       CONTENT of the basket, not on stream_id: two streams
     *                       configured with the same basket are one cohort, and
     *                       one stream whose basket was edited mid-year is not
     *                       silently two.
     *   `identical_basket`  students whose set of counts_toward_average
     *                       allocations is identical. Read from the student's
     *                       OWN materialised Mark rows, because that is the only
     *                       record of which electives they actually took; 6.2
     *                       guarantees those rows exist for every effective
     *                       allocation, so "no row" cannot silently shrink a
     *                       basket.
     */
    public function cohortKeyFor(int $enrollmentId, int $assessmentPeriodId, string $rule): string
    {
        return match ($rule) {
            'all' => PeriodResult::COHORT_KEY_ALL,
            'same_stream' => $this->streamBasketKey($enrollmentId),
            'identical_basket' => $this->identicalBasketKey($enrollmentId, $assessmentPeriodId),
            default => throw ValidationException::withMessages([
                'rank_cohort_rule' => "Unknown rank_cohort_rule `{$rule}`.",
            ]),
        };
    }

    /**
     * @param  list<SubjectResult>  $subjects
     * @param  array<string, mixed>  $period
     */
    private function computeOne(
        int $assessmentPeriodId,
        int $enrollmentId,
        array $subjects,
        AssessmentFramework $framework,
        array $period,
        string $endsOn,
        Score $passScore,
        int $precision,
    ): PeriodResult {
        $average = $this->pipeline->generalAverage($subjects, $precision);

        $classGroupId = $this->owningClassGroupId($enrollmentId, $endsOn);

        if ($classGroupId === null) {
            // 12.6 rule 5: rosters, effectif and the ranking denominator ALL
            // derive from segments. A student with no segment covering the
            // period's last day cannot be placed in any class, so there is no
            // honest answer to "which class's mean does this student move" -
            // and guessing would move somebody else's rank.
            throw ValidationException::withMessages([
                'enrollment_id' => "Enrollment {$enrollmentId} has no class-group segment covering "
                    ."{$endsOn}; 12.6 rule 1 has nowhere to put its rank.",
            ]);
        }

        $rounded = $average->rounded;
        $band = $rounded === null ? null : $this->bandFor($framework, $rounded, $enrollmentId);

        $ncReason = $this->ncReason($enrollmentId, $assessmentPeriodId, $period, $framework, $average->rounded);

        $attributes = [
            'class_group_id' => $classGroupId,
            'framework_id' => (int) $framework->getKey(),
            'cohort_key' => $this->cohortKeyFor(
                $enrollmentId,
                $assessmentPeriodId,
                $framework->rank_cohort_rule,
            ),
            'rank_scope' => $framework->rank_scope,
            'coefficient_sum' => $this->hundredthsToDecimal($average->sumCoefficientHundredths),
            'weighted_total' => $rounded === null ? null : $average->weightedTotal()->toString(),
            'general_average' => $average->raw?->toString(),
            'general_average_rounded' => $rounded?->toString(),
            'is_pass' => $rounded === null
                ? null
                : PassRule::passes($rounded, $passScore, $precision),
            'gpa' => $rounded === null
                ? null
                : $this->gpaFor($subjects, $framework, $enrollmentId, $precision),
            'grade_band_id' => $band === null ? null : (int) $band->getKey(),
            'subjects_counted' => count($average->contributingSubjectKeys),
            'subject_scores' => $this->subjectScorePayload($subjects),
            // Stage 6 is a SEPARATE pass (ComputeRanking): a rank is a fact
            // about a cohort, not about a student, and cannot be known while
            // the cohort is still being computed one student at a time.
            'is_ranked' => false,
            'rank_position' => null,
            'rank_denominator' => null,
            'nc_reason' => $ncReason,
            'computed_at' => now(),
        ];

        /** @var PeriodResult $result */
        $result = PeriodResult::query()->updateOrCreate(
            [
                'assessment_period_id' => $assessmentPeriodId,
                'enrollment_id' => $enrollmentId,
            ],
            $attributes,
        );

        return $result;
    }

    /**
     * 10.5. Three reasons, named, so the card can print a footnote instead of a
     * bare `NC` that a parent has to telephone about.
     *
     * @param  array<string, mixed>  $period
     */
    private function ncReason(
        int $enrollmentId,
        int $assessmentPeriodId,
        array $period,
        AssessmentFramework $framework,
        ?Score $rounded,
    ): ?string {
        if ($rounded === null) {
            // 10.2 / C3. Not a fail, not a zero: not assessed.
            return PeriodResult::NC_NULL_AVERAGE;
        }

        $enrollment = DB::table('enrollments')->where('id', $enrollmentId)->first();

        if ($enrollment === null) {
            throw ValidationException::withMessages([
                'enrollment_id' => "Enrollment {$enrollmentId} does not exist.",
            ]);
        }

        /** @var array<string, mixed> $row */
        $row = (array) $enrollment;

        $assessableFrom = $row['assessable_from_period_id'] ?? null;

        if (is_numeric($assessableFrom)) {
            $from = DB::table('assessment_periods')
                ->where('id', (int) $assessableFrom)
                ->value('ends_on');

            // Compared on ends_on rather than on id or order_index: ids are
            // insertion order and order_index is only unique within a parent,
            // so neither orders a sequence against a trimestre. A November
            // arrival is simply not ranked against students who sat Sequence 1.
            if (is_string($from) && (string) $period['ends_on'] < $from) {
                return PeriodResult::NC_NOT_YET_ASSESSABLE;
            }
        }

        // "for the term/annual card" - the rule counts LEAF periods with a
        // non-NULL average, so it can only be applied to a period that HAS
        // leaves. Applying it to a sequence would require that sequence to
        // contain itself.
        if (! $this->hasChildren($assessmentPeriodId)) {
            return null;
        }

        $minimum = is_numeric($row['min_periods_assessed'] ?? null)
            ? (int) $row['min_periods_assessed']
            : $framework->min_periods_assessed;

        if ($minimum <= 0) {
            return null;
        }

        $assessed = PeriodResult::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereNotNull('general_average_rounded')
            ->whereIn('assessment_period_id', $this->leafPeriodIds($assessmentPeriodId))
            ->count();

        return $assessed < $minimum ? PeriodResult::NC_INSUFFICIENT_PERIODS : null;
    }

    /**
     * @return list<int>
     */
    private function leafPeriodIds(int $rootId): array
    {
        $frontier = [$rootId];
        $leaves = [];

        while ($frontier !== []) {
            $children = DB::table('assessment_periods')
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($frontier as $candidate) {
                if (! $this->hasChildren($candidate)) {
                    $leaves[] = $candidate;
                }
            }

            $frontier = $children;
        }

        return $leaves;
    }

    private function hasChildren(int $periodId): bool
    {
        return DB::table('assessment_periods')->where('parent_id', $periodId)->exists();
    }

    /**
     * 3.3 / 11. The band whose half-open [min, max) interval contains the
     * ROUNDED average - the top band closed, so a perfect score bands.
     *
     * A class-level-specific band set wins over the framework-wide one (3.3's
     * optional narrowing), which is why the ordering below puts the narrower
     * rows first rather than relying on the caller to have configured only one.
     */
    private function bandFor(AssessmentFramework $framework, Score $score, int $enrollmentId): ?GradeBand
    {
        $classLevelId = DB::table('enrollments')->where('id', $enrollmentId)->value('class_level_id');

        /** @var list<GradeBand> $bands */
        $bands = GradeBand::query()
            ->where('framework_id', $framework->getKey())
            ->where('purpose', GradeBand::PURPOSE_INTERNAL)
            ->where('scale_basis', GradeBand::BASIS_OUT_OF_MAX)
            ->where(function (EloquentBuilder $query) use ($classLevelId): void {
                $query->whereNull('class_level_id');

                if (is_numeric($classLevelId)) {
                    $query->orWhere('class_level_id', (int) $classLevelId);
                }
            })
            // Narrower first, then by interval, so the first containing band is
            // also the most specific one.
            ->orderByRaw('class_level_id IS NULL')
            ->orderBy('min_score')
            ->get()
            ->all();

        $topIndex = count($bands) - 1;

        foreach ($bands as $index => $band) {
            if ($band->contains($score, $index === $topIndex)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * 11. Coefficient-weighted mean of GradeBand.grade_point, computed by
     * `Domain\Gpa`. NULL propagates: if ANY banded subject in scope has a NULL
     * point - or falls in no configured band at all - the GPA is NULL rather
     * than silently computed over the subset that happened to be configured.
     *
     * @param  list<SubjectResult>  $subjects
     */
    private function gpaFor(
        array $subjects,
        AssessmentFramework $framework,
        int $enrollmentId,
        int $precision,
    ): ?string {
        $pairs = [];

        foreach ($subjects as $subject) {
            if (! $subject->contributesToAverage() || $subject->score === null) {
                continue;
            }

            $band = $this->bandFor($framework, $subject->score, $enrollmentId);
            $point = $band?->grade_point;

            $pairs[] = [
                $point === null ? null : Score::of($point),
                $subject->coefficientHundredths,
            ];
        }

        return Gpa::compute($pairs, $precision)?->toDisplayString();
    }

    /**
     * The stage-4 output, kept so 10.6 subject rank and 10.7's per-subject
     * statistics read one table instead of replaying the pipeline per figure.
     *
     * @param  list<SubjectResult>  $subjects
     * @return array<string, array<string, mixed>>
     */
    private function subjectScorePayload(array $subjects): array
    {
        $payload = [];

        foreach ($subjects as $subject) {
            $payload[(string) $subject->key] = [
                // NULL is "unassessed" and is preserved as NULL: 10.2's subject
                // -level rule prints `n/e` and drops the subject from that
                // subject's rank and statistics. A 0 here would be a mark.
                'score' => $subject->score?->toString(),
                'coefficient_hundredths' => $subject->coefficientHundredths,
                'counts_toward_average' => $subject->countsTowardAverage,
            ];
        }

        return $payload;
    }

    /**
     * Stream.subject_basket is JSON. It is fingerprinted after a recursive key
     * sort so that two baskets differing only in the order the administrator
     * typed the subjects are ONE cohort - otherwise the cohort would depend on
     * data-entry order, which no conseil could defend.
     */
    private function streamBasketKey(int $enrollmentId): string
    {
        $streamId = DB::table('enrollments')->where('id', $enrollmentId)->value('stream_id');

        if (! is_numeric($streamId)) {
            // 5.1's sentinel case: no stream means the whole class level, which
            // is itself a single comparable basket.
            return 'stream:none';
        }

        $basket = DB::table('streams')->where('id', (int) $streamId)->value('subject_basket');

        if (! is_string($basket)) {
            return 'stream:none';
        }

        /** @var mixed $decoded */
        $decoded = json_decode($basket, true);

        if (is_array($decoded)) {
            $this->sortRecursive($decoded);
            $basket = (string) json_encode($decoded);
        }

        return 'stream:'.sha1($basket);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }

        unset($item);

        if (array_is_list($value)) {
            sort($value);

            return;
        }

        ksort($value);
    }

    private function identicalBasketKey(int $enrollmentId, int $assessmentPeriodId): string
    {
        $allocationIds = DB::table('marks')
            ->join(
                'subject_allocations',
                'subject_allocations.id',
                '=',
                'marks.subject_allocation_id',
            )
            ->where('marks.enrollment_id', $enrollmentId)
            ->where('marks.assessment_period_id', $assessmentPeriodId)
            ->where('subject_allocations.counts_toward_average', true)
            ->distinct()
            ->pluck('marks.subject_allocation_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        return 'basket:'.sha1(implode(',', $allocationIds));
    }

    /**
     * @return array<string, mixed>
     */
    private function period(int $assessmentPeriodId): array
    {
        // Through the query builder: AssessmentPeriod is an Academics Model and
        // ModuleBoundaryTest forbids importing it here.
        $row = DB::table('assessment_periods')->where('id', $assessmentPeriodId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'assessment_period_id' => 'The assessment period does not exist.',
            ]);
        }

        return (array) $row;
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function framework(array $period): AssessmentFramework
    {
        $frameworkId = $period['framework_id'] ?? null;

        if (! is_numeric($frameworkId)) {
            // 20 / 00-core 16: nothing is seeded, and a period with no
            // framework has no max_score, no pass_score and no cohort rule.
            // Guessing any of them would produce a bulletin nobody configured.
            throw ValidationException::withMessages([
                'framework_id' => 'This assessment period has no assessment framework; '
                    .'results cannot be computed until one is configured.',
            ]);
        }

        /** @var AssessmentFramework $framework */
        $framework = AssessmentFramework::query()->findOrFail((int) $frameworkId);

        return $framework;
    }

    /** 1800 hundredths => "18.00", with no float in the middle. */
    private function hundredthsToDecimal(int $hundredths): string
    {
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
