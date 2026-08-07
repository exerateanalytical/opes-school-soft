<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use RuntimeException;

/**
 * Every way the grading arithmetic can be handed something it must refuse.
 *
 * The pipeline never guesses. A defect in configuration or in a caller's data
 * surfaces here, loudly, rather than as a plausible-looking number on a child's
 * report card (docs/specs/01-assessment.md §18).
 */
final class AssessmentException extends RuntimeException
{
    public static function componentWeightsMustSumTo100(int $actual): self
    {
        return new self(
            "Component weights for a subject must sum to exactly 100; got {$actual}. "
            .'docs/specs/01-assessment.md §5.4 and invariant §18.1.',
        );
    }

    public static function negativeComponentWeight(string $componentCode): self
    {
        return new self("Component '{$componentCode}' has a negative weight.");
    }

    public static function scoredMarkNeedsScore(string $componentCode): self
    {
        return new self(
            "Component '{$componentCode}' is 'scored' but carries no score. "
            .'docs/specs/01-assessment.md §18.4: (state = scored) ⇔ (score IS NOT NULL).',
        );
    }

    public static function unscoredMarkCarriesScore(string $componentCode): self
    {
        return new self(
            "Component '{$componentCode}' is not 'scored' yet carries a score. "
            .'docs/specs/01-assessment.md §18.4: (state = scored) ⇔ (score IS NOT NULL).',
        );
    }

    public static function scoreExceedsEffectiveMaximum(string $componentCode, string $score, string $max): self
    {
        return new self(
            "Component '{$componentCode}' scored {$score} against an effective maximum of {$max}. "
            .'docs/specs/01-assessment.md §18.5: 0 ≤ score ≤ effectiveMax(mark).',
        );
    }

    public static function zeroEffectiveMaximum(string $componentCode): self
    {
        return new self(
            "Component '{$componentCode}' resolves to an effective maximum of zero, "
            .'so no unit ratio exists. docs/specs/01-assessment.md §6.3.',
        );
    }

    public static function nonPositivePeriodWeight(): self
    {
        return new self(
            'AssessmentPeriod.weight is arbitrary POSITIVE. docs/specs/01-assessment.md §4.1.',
        );
    }

    public static function negativeCoefficient(): self
    {
        return new self('SubjectAllocation.coefficient must be >= 0. docs/specs/01-assessment.md §5.1.');
    }

    public static function unsupportedPrecision(int $precision): self
    {
        return new self(
            "score_precision {$precision} is out of range; Score stores thousandths, so 0..3 only.",
        );
    }

    public static function overflow(): self
    {
        return new self('Integer overflow in the grading arithmetic; the inputs are out of range.');
    }
}
