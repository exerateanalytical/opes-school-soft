<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * Lifecycle of an assessment period (01-assessment 4.1). `open` is what marks
 * entry checks against; `closed` periods only change through Amendment.
 */
enum AssessmentPeriodStatus: string
{
    case Planned = 'planned';
    case Open = 'open';
    case Closed = 'closed';

    public function label(string $locale = 'en'): string
    {
        return __('opes.assessment_period_status.'.$this->value, [], $locale);
    }
}
