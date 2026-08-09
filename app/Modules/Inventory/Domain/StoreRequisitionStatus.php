<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** docs/specs/06-assets-stores.md §7.8. */
enum StoreRequisitionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
