<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §2.1/§8.6 - what happens to an equipment receipt
 * below the category's capitalisation threshold.
 */
enum BelowThresholdBehaviour: string
{
    case ExpenseOnly = 'expense_only';
    case ExpenseAndTrack = 'expense_and_track';
}
