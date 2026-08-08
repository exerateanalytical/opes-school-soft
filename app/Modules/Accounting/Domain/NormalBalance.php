<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/** `ChartOfAccount.normal_balance` (02-accounting.md 2.1). */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
