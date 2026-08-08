<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * 02-accounting §5.2, the two-stage lock (C8). `open` accepts every write.
 * `soft_locked` blocks operational modules and ordinary manual entries but
 * still admits year-end Actions and, with the elevated permission, manual
 * entries. `hard_locked` is the AUDCIF Art. 22 clôture informatique and
 * accepts nothing - a correction there is a reversal in a later open period.
 */
enum AccountingPeriodStatus: string
{
    case Open = 'open';
    case SoftLocked = 'soft_locked';
    case HardLocked = 'hard_locked';

    public function label(string $locale = 'en'): string
    {
        return __('opes.accounting_period_status.'.$this->value, [], $locale);
    }
}
