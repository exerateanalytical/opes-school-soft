<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §3.3 retenue de garantie lifecycle.
 * `withheld`: sitting on 4817 pending final acceptance; `released`:
 * reclassified Dr 4817 / Cr 401, payable again; `cancelled`: the reclass
 * was undone by a payment void - a later payment may withhold anew.
 */
enum SupplierRetentionStatus: string
{
    case Withheld = 'withheld';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
