<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §2.4 accounting rule - the operator's EXPLICIT
 * choice at close. Never inferred from amount.
 */
enum MaintenanceResolution: string
{
    case Expense = 'expense';
    case Capitalise = 'capitalise';
}
