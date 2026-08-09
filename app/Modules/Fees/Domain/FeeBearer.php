<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §2.4 / §15.6 - who absorbs the operator or bank
 * commission on a payment. When the SCHOOL bears it, the commission enters
 * the books (Dr 6317) and treasury receives the net; when the PAYER bears
 * it, the commission never entered the school's books at all.
 */
enum FeeBearer: string
{
    case School = 'school';
    case Payer = 'payer';
    case None = 'none';
}
