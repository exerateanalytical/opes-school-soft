<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * Lifecycle of an academic year (00-core 8).
 *
 * `is_current` is a separate flag, not a status: a school closing last year's
 * books works IN the active year while REPORTING against the closed one, so
 * "which year is current for the UI" and "which years accept writes" are
 * different questions.
 */
enum AcademicYearStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Closed = 'closed';

    public function label(string $locale = 'en'): string
    {
        return __('opes.academic_year_status.'.$this->value, [], $locale);
    }
}
