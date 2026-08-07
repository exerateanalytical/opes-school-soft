<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * The node types of the assessment-period tree (01-assessment 4.1).
 *
 * Phase 1 only creates year|term|trimestre rows; sequence, evaluation and
 * month arrive with the Assessment module. All six exist now so the DB enum
 * never needs an ALTER.
 */
enum AssessmentPeriodType: string
{
    case Year = 'year';
    case Term = 'term';
    case Trimestre = 'trimestre';
    case Sequence = 'sequence';
    case Evaluation = 'evaluation';
    case Month = 'month';

    public function label(string $locale = 'en'): string
    {
        return __('opes.assessment_period_type.'.$this->value, [], $locale);
    }

    /** Depth invariant (01-assessment 4.1): year -> (term|trimestre) -> leaves. */
    public function isTermLevel(): bool
    {
        return $this === self::Term || $this === self::Trimestre;
    }
}
