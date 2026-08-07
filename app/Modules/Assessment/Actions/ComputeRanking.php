<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\Ranking;
use App\Modules\Assessment\Models\AssessmentFramework;
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
 * Stage 6 (docs/specs/01-assessment.md 2.2, 10.4, 10.5, 12.6).
 *
 * The ordering itself is `Domain\Ranking` - round first, then order, with
 * competition ties (1, 2, 2, 4). This Action decides only WHO IS IN THE ROOM,
 * which is the part that needs rows and the part v1 got wrong:
 *
 *   - the SCOPE is `framework.rank_scope` (class group, class level or stream);
 *   - the COHORT inside that scope is `framework.rank_cohort_rule`, carried on
 *     each result as `cohort_key` - ranking a student against a different
 *     elective set is arithmetically unfair, because a different basket is a
 *     different Sigma-coef, and a conseil de classe will reject it (10.4);
 *   - the CLASS GROUP is the one owning the segment covering the period's last
 *     day, so a student who transferred in November is ranked once, in the
 *     class they finished the period in (12.6 rule 1);
 *   - a student with a NULL average or an NC reason is not last. They are
 *     OUTSIDE the room: no rank, and absent from the denominator everyone
 *     else's card prints (10.2, 10.5).
 */
final class ComputeRanking
{
    /**
     * @return int the number of ranked students across every cohort in the period
     */
    public function handle(int $assessmentPeriodId, ?Actor $actor = null): int
    {
        Gate::authorize(Permission::MarksValidate->value);

        $writer = $actor ?? $this->currentActor();

        return DB::transaction(function () use ($assessmentPeriodId, $writer): int {
            $framework = $this->framework($assessmentPeriodId);
            $precision = $framework->score_precision;

            /** @var list<PeriodResult> $results */
            $results = PeriodResult::query()
                ->where('assessment_period_id', $assessmentPeriodId)
                // Ranking must be reproducible: `usort` is stable, so a fixed
                // input order is what makes a re-render of the same data
                // byte-identical rather than merely equivalent (13.3).
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            if (! $framework->uses_rank) {
                // Family F and any competency-only framework: 8.4 / T19 keep
                // rank, average and mention off the card entirely. Clearing is
                // not cosmetic - a stale rank left behind by a framework change
                // would print.
                $this->clearAll($results);

                return 0;
            }

            $scopeKeys = $this->scopeKeys($results, $framework->rank_scope);

            /** @var array<string, list<PeriodResult>> $cohorts */
            $cohorts = [];

            foreach ($results as $result) {
                // The cohort is the INTERSECTION of the two rules: the scope
                // says which students are even in view, the cohort key says
                // which of those are comparable.
                $key = $scopeKeys[(int) $result->getKey()].'|'.$result->cohort_key;
                $cohorts[$key][] = $result;
            }

            $rankedTotal = 0;

            foreach ($cohorts as $cohort) {
                $rankedTotal += $this->rankCohort($cohort, $precision);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: PeriodResult::class,
                auditableId: $assessmentPeriodId,
                after: [
                    'assessment_period_id' => $assessmentPeriodId,
                    'rank_scope' => $framework->rank_scope,
                    'rank_cohort_rule' => $framework->rank_cohort_rule,
                    'cohorts' => count($cohorts),
                    'ranked' => $rankedTotal,
                    // The two numbers a conseil argues about: how many students
                    // the ranking left out, and why the denominator is not the
                    // class size.
                    'excluded' => count($results) - $rankedTotal,
                ],
                actor: $writer,
            );

            return $rankedTotal;
        });
    }

    /**
     * @param  list<PeriodResult>  $cohort
     * @return int the number of students ranked in this cohort
     */
    private function rankCohort(array $cohort, int $precision): int
    {
        /** @var array<int, Score|null> $averages */
        $averages = [];

        foreach ($cohort as $result) {
            $rounded = $result->general_average_rounded;

            // The single exclusion expression, so "no rank", "absent from the
            // denominator" and "absent from the statistics" cannot drift apart:
            // NULL average (10.2) and NC (10.5) are different reasons with an
            // identical treatment.
            $averages[(int) $result->getKey()] = ($rounded === null || $result->nc_reason !== null)
                ? null
                : Score::of($rounded);
        }

        $table = Ranking::rank($averages, $precision);

        foreach ($cohort as $result) {
            $rank = $table->rankOf((int) $result->getKey());

            if ($rank === null) {
                $result->is_ranked = false;
                $result->rank_position = null;
                $result->rank_denominator = null;

                if ($result->nc_reason === null) {
                    // Reached only when the average is NULL, since every other
                    // exclusion above already carries a reason.
                    $result->nc_reason = PeriodResult::NC_NULL_AVERAGE;
                }

                $result->save();

                continue;
            }

            $result->is_ranked = true;
            $result->rank_position = $rank;
            // 10.2: "Rang : 5e / 62 counts only ranked students". The
            // denominator is the cohort's ranked count, never its head count.
            $result->rank_denominator = $table->denominator;
            $result->nc_reason = null;
            $result->save();
        }

        return $table->denominator;
    }

    /**
     * 10.4's `rank_scope`. The result already carries the class group of the
     * segment covering the period's last day (12.6 rule 1); the wider scopes
     * are read off the enrollment, through the query builder, because
     * `Students\Models\Enrollment` is another module's Model.
     *
     * @param  list<PeriodResult>  $results
     * @return array<int, string>  keyed by period_result id
     */
    private function scopeKeys(array $results, string $rankScope): array
    {
        if ($rankScope === 'class_group') {
            $keys = [];

            foreach ($results as $result) {
                $keys[(int) $result->getKey()] = 'group:'.$result->class_group_id;
            }

            return $keys;
        }

        $column = match ($rankScope) {
            'class_level' => 'class_level_id',
            'stream' => 'stream_id',
            default => throw ValidationException::withMessages([
                'rank_scope' => "Unknown rank_scope `{$rankScope}`.",
            ]),
        };

        $enrollmentIds = array_map(
            static fn (PeriodResult $result): int => $result->enrollment_id,
            $results,
        );

        /** @var array<int, mixed> $scopeValues */
        $scopeValues = DB::table('enrollments')
            ->whereIn('id', $enrollmentIds)
            ->pluck($column, 'id')
            ->all();

        $keys = [];

        foreach ($results as $result) {
            $value = $scopeValues[$result->enrollment_id] ?? null;

            // A NULL stream is its own scope, not everybody's: 5.1's sentinel
            // reasoning again - lumping the unstreamed together with every
            // stream would rank an unstreamed student against baskets they
            // never studied.
            $keys[(int) $result->getKey()] = $column.':'.(is_numeric($value) ? (string) (int) $value : 'none');
        }

        return $keys;
    }

    /**
     * @param  list<PeriodResult>  $results
     */
    private function clearAll(array $results): void
    {
        foreach ($results as $result) {
            $result->is_ranked = false;
            $result->rank_position = null;
            $result->rank_denominator = null;
            $result->save();
        }
    }

    private function framework(int $assessmentPeriodId): AssessmentFramework
    {
        $frameworkId = DB::table('assessment_periods')
            ->where('id', $assessmentPeriodId)
            ->value('framework_id');

        if (! is_numeric($frameworkId)) {
            throw ValidationException::withMessages([
                'framework_id' => 'This assessment period has no assessment framework; '
                    .'there is no rank scope or cohort rule to rank by.',
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
