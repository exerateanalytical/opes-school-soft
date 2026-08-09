<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.2 - `default_recurrence` on a fee item.
 */
enum FeeRecurrence: string
{
    case PerYear = 'per_year';
    case PerTerm = 'per_term';
    case PerMonth = 'per_month';
    case OneOff = 'one_off';
}
