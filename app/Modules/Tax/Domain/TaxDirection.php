<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §5.3 - which side of the ledger a tax
 * code operates on. `Both` exists for codes usable on either side (the
 * stored TaxCode.direction is a plain string for historical reasons; this
 * enum is the typed vocabulary Actions use).
 */
enum TaxDirection: string
{
    case Output = 'output';
    case Input = 'input';
    case Both = 'both';
}
