<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §10.2/§10.3.
 *
 * `Full` requires Σdebit = Σcredit (LT-2, backed by `ck_lettering_full`).
 * `Partial` is not a defect - it is the normal state of a part payment
 * (LT-3) and is auto-promoted to `Full` the moment the running totals meet
 * (LT-4).
 */
enum LetteringStatus: string
{
    case Partial = 'partial';
    case Full = 'full';
}
