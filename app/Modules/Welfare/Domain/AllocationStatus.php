<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Lifecycle of a welfare allocation (transport seat, hostel bed).
 *
 * Two states only, deliberately: "who holds this today" is answered by the
 * single `active` row per enrollment (schema-enforced via the NULL-unique
 * active_key column), and everything else is closed history. Suspensions or
 * transfers END one allocation and open another - they never mutate dates
 * on a live row into ambiguity.
 */
enum AllocationStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
}
