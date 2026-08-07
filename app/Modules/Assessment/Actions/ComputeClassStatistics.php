<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\ClassStatistics as ClassStatisticsCalculator;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\ClassStatistic;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Score\Score;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/01-assessment.md 10.7, orchestrated and persisted.
 *
 * Every figure - mean, cote, median, population standard deviation, pass count
 * and pass rate - is computed by `Domain\ClassStatistics`, because a class mean
 * is an average and T23 forbids an average being computed anywhere else. What
 * this Action contributes is the POPULATION, and that is the whole of C3:
 *
 *   statistics are computed over RANKED, NON-NULL students only.
 *
 * A student whose Sigma-coef was 0 is not a zero dragging the class mean down,
 * and is not a failure dragging the pass rate down. They are not in the sample
 * at all - `n` does not count them (10.2). Neither is an NC student (10.5).
 * Running this after ComputeRanking rather than in parallel with it is what
 * makes those two exclusions literally the same set: the `ranked` scope.
 *
 * Rows are rebuilt for the whole period rather than updated in place, so a
 * cohort that no longer exists - a stream emptied by transfers, a basket
 * reconfigured - leaves no stale mean behind for a bulletin to print.
 */
final class ComputeClassStatistics
{
    /**
     * @return list<ClassStatistic>
     */
    public function handle(int $assessmentPeriodId, ?Actor $actor = null): array
    {
        Gate::authorize(Permission::MarksValidate->value);

        $writer = $actor ?? $this->currentActor();

        return DB::transaction(function () use ($assessmentPeriodId, $writer): array {
            $framework = $this->framework($assessmentPeriodId);
            $precision = $framework->score_precision;
            $passScore = Score::of($framework->pass_score);

            /** @var list<PeriodResult> $results */
            $results = PeriodResult::query()
                ->where('assessment_period_id', $assessmentPeriodId)
                ->ranked()
                ->orderBy('id')
                ->get()
                ->all();

            ClassStatistic::query()->where('assessment_period_id', $assessmentPeriodId)->delete();

            /** @var array<string, array{group: int, cohort: string, allocation: int, scores: list<Score>}> $samples */
            $samples = [];

            foreach ($results as $result) {
                $rounded = $result->general_average_rounded;

                if ($rounded === null) {
                    // Unreachable through ->ranked(), which is the point: the
                    // scope is the guard, not a comment.
                    continue;
                }

                $this->collect(
                    $samples,
                    $result->class_group_id,
                    $result->cohort_key,
                    ClassStatistic::GENERAL,
                    Score::of($rounded),
                );

                $this->collectSubjects($samples, $result);
            }

            $rows = [];

            foreach ($samples as $sample) {
                $rows[] = $this->persist($assessmentPeriodId, $sample, $passScore, $precision);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: ClassStatistic::class,
                auditableId: $assessmentPeriodId,
                after: [
                    'assessment_period_id' => $assessmentPeriodId,
                    'rows' => count($rows),
                    // The sample size, stated: 10.7's `n` is the count of
                    // ranked, non-NULL students, never the class roll.
                    'ranked_students' => count($results),
                ],
                actor: $writer,
            );

            return $rows;
        });
    }

    /**
     * 10.2's subject-level rule: "a subject with no surviving component weight
     * is NULL, prints `n/e`, and is absent from that subject's rank and
     * statistics". A NULL subject score is skipped here for exactly the reason
     * a NULL general average is skipped above.
     *
     * @param  array<string, array{group: int, cohort: string, allocation: int, scores: list<Score>}>  $samples
     */
    private function collectSubjects(array &$samples, PeriodResult $result): void
    {
        foreach ($result->subject_scores ?? [] as $allocationId => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $score = $payload['score'] ?? null;

            if (! is_string($score)) {
                continue;
            }

            $this->collect(
                $samples,
                $result->class_group_id,
                $result->cohort_key,
                (int) $allocationId,
                Score::of($score),
            );
        }
    }

    /**
     * @param  array<string, array{group: int, cohort: string, allocation: int, scores: list<Score>}>  $samples
     */
    private function collect(
        array &$samples,
        int $classGroupId,
        string $cohortKey,
        int $allocationId,
        Score $score,
    ): void {
        $key = $classGroupId.'|'.$cohortKey.'|'.$allocationId;

        if (! array_key_exists($key, $samples)) {
            $samples[$key] = [
                'group' => $classGroupId,
                'cohort' => $cohortKey,
                'allocation' => $allocationId,
                'scores' => [],
            ];
        }

        $samples[$key]['scores'][] = $score;
    }

    /**
     * @param  array{group: int, cohort: string, allocation: int, scores: list<Score>}  $sample
     */
    private function persist(
        int $assessmentPeriodId,
        array $sample,
        Score $passScore,
        int $precision,
    ): ClassStatistic {
        /** @var list<Score|null> $scores */
        $scores = $sample['scores'];

        $statistics = ClassStatisticsCalculator::of($scores, $passScore, $precision);

        /** @var ClassStatistic $row */
        $row = ClassStatistic::query()->create([
            'assessment_period_id' => $assessmentPeriodId,
            'class_group_id' => $sample['group'],
            'subject_allocation_id' => $sample['allocation'],
            'cohort_key' => $sample['cohort'],
            'n' => $statistics->n,
            'mean' => $statistics->mean?->toString(),
            'min_score' => $statistics->min?->toString(),
            'max_score' => $statistics->max?->toString(),
            'median' => $statistics->median?->toString(),
            'stdev_population' => $statistics->stdevPopulation?->toString(),
            'pass_count' => $statistics->passCount,
            // 10.3 all the way down: the rate counts PeriodResult rows whose
            // pass verdict came from PassRule, and GradeBand.is_pass is never
            // consulted anywhere in this path.
            'pass_rate' => $statistics->passRate?->toPercentString(),
            'computed_at' => now(),
        ]);

        return $row;
    }

    private function framework(int $assessmentPeriodId): AssessmentFramework
    {
        $frameworkId = DB::table('assessment_periods')
            ->where('id', $assessmentPeriodId)
            ->value('framework_id');

        if (! is_numeric($frameworkId)) {
            throw ValidationException::withMessages([
                'framework_id' => 'This assessment period has no assessment framework; '
                    .'there is no pass score to compute a pass rate against.',
            ]);
        }

        /** @var AssessmentFramework $framework */
        $framework = AssessmentFramework::query()->findOrFail((int) $frameworkId);

        return $framework;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
