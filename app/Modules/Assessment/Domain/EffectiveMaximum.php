<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * The precedence chain of docs/specs/01-assessment.md §6.3.
 *
 * ```
 * 1. SubjectAllocation.max_score_override   (if NOT NULL)
 * 2. AssessmentComponent.max_score
 * 3. AssessmentFramework.max_score
 * ```
 *
 * In v1 `max_score_override` was read by no pipeline stage at all. It is what
 * stage 2 divides by; stage 4 then re-scales back to `framework.max_score`, so
 * an override changes the marking convention and never the subject's weight.
 */
final class EffectiveMaximum
{
    public static function resolve(
        ?Score $allocationOverride,
        ?Score $componentMax,
        Score $frameworkMax,
        string $componentCode = '',
    ): Score {
        $resolved = $allocationOverride ?? $componentMax ?? $frameworkMax;

        if ($resolved->thousandths() === 0) {
            throw AssessmentException::zeroEffectiveMaximum($componentCode);
        }

        return $resolved;
    }
}
