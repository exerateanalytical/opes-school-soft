<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/** docs/specs/04-fees.md §8. Corrections are new signed rows, never edits. */
enum FeeAdjustmentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reversed = 'reversed';
}
