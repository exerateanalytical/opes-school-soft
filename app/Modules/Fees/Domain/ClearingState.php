<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §11.1/§11.4. All three v1 methods (cash, mobile
 * money, bank) are `immediate` and insert as Cleared; the state machine
 * exists because §5's balance formula must exclude `bounced` even before
 * deferred instruments (cheques) arrive in a later phase, and because a
 * column added later to a financial table is a migration on live money.
 */
enum ClearingState: string
{
    case Cleared = 'cleared';
    case Pending = 'pending';
    case Bounced = 'bounced';
}
