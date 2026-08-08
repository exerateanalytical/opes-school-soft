<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * 02-accounting §6. `planned` before it accepts postings, `open` for the
 * ordinary posting window, `closing` while the year-end sequence (§17,
 * outside this phase's scope) runs, `closed` once the à-nouveaux and
 * clôture entries are posted.
 */
enum FiscalYearStatus: string
{
    case Planned = 'planned';
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';

    public function label(string $locale = 'en'): string
    {
        return __('opes.fiscal_year_status.'.$this->value, [], $locale);
    }
}
