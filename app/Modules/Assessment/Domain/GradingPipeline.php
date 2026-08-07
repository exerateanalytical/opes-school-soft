<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * The six-stage pipeline of docs/specs/01-assessment.md §2.2, implemented ONCE.
 *
 * ```
 * 1. COLLECT    gather Mark rows for (enrollment, subject_allocation, period, component)
 * 2. NORMALIZE  each component → unit ratio in [0,1] against its OWN effective maximum
 * 3. COMPOSE    weighted mean of surviving component ratios → subject unit ratio
 * 4. WEIGHT     scale to framework.max_score; multiply by coefficient
 * 5. AGGREGATE  Σ(subject_score × coefficient) ÷ Σ(coefficient)  → general average
 * 6. RANK       round, then order within the rank cohort
 * ```
 *
 * v1's order was COLLECT → COMPOSE → NORMALIZE, which composes raw marks having
 * DIFFERENT maxima and yields a number with no defined maximum. §2.1's
 * counterexample — CA 24/30 and Exam 60/100 at weights 30/70 — comes out of the
 * wrong order as 9.84/20 FAIL and out of this one as **13.20/20 PASS**. The two
 * orders are not stylistic variants; they are different products.
 *
 * PURITY. No Laravel, no Eloquent, no facades, no `app()`, no `config()`; every
 * input is a plain value object. Report card, broadsheet, promotion engine,
 * statistics, GPA and the annual average all call THIS class, which is what
 * makes §9.4's byte-identical guarantee true rather than aspirational.
 *
 * ARITHMETIC. Stage 3 accumulates an exact rational Σ(sᵢ·wᵢ/mᵢ) — reduced by
 * its GCD at each step — so a single half-up division at stage 4 is the only
 * rounding in the whole subject computation. No intermediate ratio is ever
 * rounded to six decimal places and then multiplied back up.
 */
final class GradingPipeline
{
    /**
     * Stages 2–4 for one (enrollment, subject_allocation, period).
     *
     * @param  Score  $frameworkMax  AssessmentFramework.max_score — 20.000 for MINESEC
     */
    public function subjectScore(
        SubjectInput $subject,
        Score $frameworkMax,
        MissingComponentPolicy $missingComponentPolicy,
    ): SubjectResult {
        $this->assertWeightsSumTo100($subject);

        $outcomes = [];
        $blocking = [];

        // Exact rational accumulator for Σ (score / effectiveMax) × weight.
        $numerator = 0;
        $denominator = 1;
        $survivingWeight = 0;

        foreach ($subject->components as $component) {
            $effectiveMax = EffectiveMaximum::resolve(
                $subject->maxScoreOverride,
                $component->componentMax,
                $frameworkMax,
                $component->componentCode,
            );

            [$effectiveState, $appliedPolicy] = $this->resolveState($component, $missingComponentPolicy);

            if ($effectiveState === MarkState::Pending) {
                $blocking[] = $component->componentCode;
            }

            $outcomes[] = new ComponentOutcome(
                $component->componentCode,
                $component->state,
                $effectiveState,
                $effectiveMax,
                $component->weight,
                $effectiveState->retainsWeight(),
                $appliedPolicy,
            );

            if (! $effectiveState->retainsWeight()) {
                continue;
            }

            $survivingWeight += $component->weight;

            if ($effectiveState !== MarkState::Scored || $component->score === null) {
                // absent_unjustified contributes ratio 0.000000 with its weight
                // retained: excluding it would reward absence (§6.4).
                continue;
            }

            $this->assertWithinMaximum($component, $effectiveMax);

            // numerator/denominator += (score × weight) / effectiveMax
            $max = $effectiveMax->thousandths();
            $common = Arithmetic::greatestCommonDivisor($denominator, $max);
            $scaledTerm = Arithmetic::multiply(
                Arithmetic::multiply($component->score->thousandths(), $component->weight),
                intdiv($denominator, $common),
            );

            $numerator = Arithmetic::add(
                Arithmetic::multiply($numerator, intdiv($max, $common)),
                $scaledTerm,
            );
            $denominator = Arithmetic::multiply($denominator, intdiv($max, $common));

            $reduce = Arithmetic::greatestCommonDivisor($numerator, $denominator);
            $numerator = intdiv($numerator, $reduce);
            $denominator = intdiv($denominator, $reduce);
        }

        if ($blocking !== []) {
            return new SubjectResult(
                $subject->key,
                null,
                $subject->coefficientHundredths,
                $subject->countsTowardAverage,
                $outcomes,
                $blocking,
            );
        }

        if ($survivingWeight === 0) {
            // §6.4 case 3: every component exempt or justified-absent. The
            // subject is UNASSESSED — no numerator contribution and its
            // coefficient leaves the denominator too.
            return new SubjectResult(
                $subject->key,
                null,
                $subject->coefficientHundredths,
                $subject->countsTowardAverage,
                $outcomes,
            );
        }

        // Stage 4: scale the composed unit ratio back to framework.max_score.
        // Doing this even when max_score_override was used is what stops the
        // override silently changing the subject's real weight (§6.3).
        $score = Score::ofThousandths(Arithmetic::divideHalfUp(
            Arithmetic::multiply($frameworkMax->thousandths(), $numerator),
            Arithmetic::multiply($denominator, $survivingWeight),
        ));

        return new SubjectResult(
            $subject->key,
            $score,
            $subject->coefficientHundredths,
            $subject->countsTowardAverage,
            $outcomes,
        );
    }

    /**
     * Stage 5 (§10.1): Σ(subject_score × coefficient) ÷ Σ(coefficient).
     *
     * **Σcoef = 0 ⇒ NULL.** Not 0.00, not an exception. v1 divided by zero,
     * caught it and returned 0.00, which banded as a Fail, ranked last and
     * still counted in the class-size denominator: a student with no assessed
     * subjects was printed as the worst in the class (§10.2, C3).
     *
     * @param  list<SubjectResult>  $subjects
     */
    public function generalAverage(
        array $subjects,
        int $precision = Rounding::DEFAULT_PRECISION,
    ): GeneralAverage {
        $weighted = [];
        $keys = [];
        $sumCoefficient = 0;
        $weightedTotal = 0;

        foreach ($subjects as $subject) {
            if (! $subject->contributesToAverage() || $subject->score === null) {
                continue;
            }

            if ($subject->coefficientHundredths === 0) {
                // A zero-coefficient subject is reported but weightless; adding
                // it would change nothing yet would misstate the printed Σcoef.
                continue;
            }

            $weighted[] = [$subject->score, $subject->coefficientHundredths];
            $keys[] = $subject->key;
            $sumCoefficient += $subject->coefficientHundredths;
            $weightedTotal = Arithmetic::add(
                $weightedTotal,
                Arithmetic::multiply($subject->score->thousandths(), $subject->coefficientHundredths),
            );
        }

        $raw = Score::weightedAverage($weighted);

        if ($raw === null) {
            return GeneralAverage::notAssessed();
        }

        return new GeneralAverage(
            $raw,
            Rounding::halfUp($raw, $precision),
            $sumCoefficient,
            $weightedTotal,
            $keys,
        );
    }

    /**
     * §9.1 term composition: normalise the weights over PARTICIPATING children.
     *
     * A null child renormalises the survivors rather than entering as a zero.
     * Séquence 1 NULL and Séquence 2 = 13.50 at equal weights composes to
     * **13.500**, not `(0 + 13.50)/2 = 6.75` — a two-band drop caused entirely
     * by a date of admission. All-null ⇒ the subject is unassessed in the
     * parent and is dropped from BOTH numerator and denominator.
     *
     * @param  list<PeriodContribution>  $children
     */
    public function composeChildren(array $children): CompositionResult
    {
        $weighted = [];

        foreach ($children as $child) {
            if (! $child->participates() || $child->score === null) {
                continue;
            }

            $weighted[] = [$child->score, $child->weightTenThousandths];
        }

        $score = Score::weightedAverage($weighted);

        if ($score === null) {
            return CompositionResult::unassessed();
        }

        return new CompositionResult($score, count($weighted));
    }

    /**
     * Unweighted mean over the non-NULL values (§9.2, §9.3 `mean_of_terms`).
     *
     * @param  list<Score|null>  $values
     */
    public function meanOf(array $values): CompositionResult
    {
        $weighted = [];

        foreach ($values as $value) {
            if ($value !== null) {
                $weighted[] = [$value, 1];
            }
        }

        $score = Score::weightedAverage($weighted);

        if ($score === null) {
            return CompositionResult::unassessed();
        }

        return new CompositionResult($score, count($weighted));
    }

    /**
     * §9.2 / §9.4: the annual average, computed HERE and nowhere else.
     *
     * `mean_of_leaf_periods` (MINESEC default) is Σ(6 sequence averages) ÷ 6 —
     * NOT a weighted mean of the three trimestre averages. With a missing
     * sequence the two methods diverge by 0.145 for the spec's worked student.
     * v1 shipped promotion code that used its own unweighted mean of term
     * percentages, so the report card printed 13.13 and the promotion list said
     * 13.28 for the same student in the same run. There is one implementation,
     * so there is one number.
     *
     * @param  list<Score|null>  $leafPeriodAverages  the six sequence general averages
     * @param  list<Score|null>  $termAverages  used only by `mean_of_terms`
     * @param  list<PeriodContribution>  $weightedChildren  used only by `weighted_children`
     */
    public function annualAverage(
        AnnualComposition $composition,
        array $leafPeriodAverages = [],
        array $termAverages = [],
        array $weightedChildren = [],
    ): CompositionResult {
        return match ($composition) {
            AnnualComposition::MeanOfLeafPeriods => $this->meanOf($leafPeriodAverages),
            AnnualComposition::MeanOfTerms => $this->meanOf($termAverages),
            AnnualComposition::WeightedChildren => $this->composeChildren($weightedChildren),
        };
    }

    private function assertWeightsSumTo100(SubjectInput $subject): void
    {
        $sum = 0;

        foreach ($subject->components as $component) {
            $sum += $component->weight;
        }

        // §18.1 / §5.4. v1 had no sum invariant at all, so 30 + 60 = 90 was
        // accepted and every student in that subject was quietly marked out of
        // 90 % of the intended scale. 100 here is a percentage basis, not a
        // grading threshold; §10.3's single-source rule concerns pass marks.
        if ($sum !== 100) {
            throw AssessmentException::componentWeightsMustSumTo100($sum);
        }
    }

    private function assertWithinMaximum(ComponentMark $component, Score $effectiveMax): void
    {
        if ($component->score !== null && $component->score->isGreaterThan($effectiveMax)) {
            throw AssessmentException::scoreExceedsEffectiveMaximum(
                $component->componentCode,
                $component->score->toString(),
                $effectiveMax->toString(),
            );
        }
    }

    /**
     * §6.4. The four resolved states are never touched by
     * `missing_component_policy`; only `pending` is.
     *
     * @return array{0: MarkState, 1: MissingComponentPolicy|null}
     */
    private function resolveState(
        ComponentMark $component,
        MissingComponentPolicy $policy,
    ): array {
        if ($component->state !== MarkState::Pending) {
            return [$component->state, null];
        }

        return match ($policy) {
            MissingComponentPolicy::BlockPublication => [MarkState::Pending, $policy],
            MissingComponentPolicy::Zero => [MarkState::AbsentUnjustified, $policy],
            // `redistribute` is exempt ONLY where an absent_justified attendance
            // record covers the component's assessment date; otherwise it falls
            // through to zero. This is the whole point: a student who simply
            // missed the exam must not be handed an A from their CA alone.
            MissingComponentPolicy::Redistribute => $component->hasJustifiedAbsenceOnAssessmentDate
                ? [MarkState::Exempt, $policy]
                : [MarkState::AbsentUnjustified, $policy],
        };
    }
}
