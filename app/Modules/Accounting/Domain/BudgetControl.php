<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/** `ChartOfAccount.budget_control` (02-accounting.md 2.1, 16). */
enum BudgetControl: string
{
    case None = 'none';
    case Warn = 'warn';
    case Block = 'block';
}
