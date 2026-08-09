<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §3.1 - drives which identity fields are
 * mandatory and which withholding defaults apply (§6): an `individual`
 * without a NIU is the 10%-withholding case, a `public_body` is typically
 * outside withholding altogether.
 */
enum SupplierType: string
{
    case Company = 'company';
    case Individual = 'individual';
    case PublicBody = 'public_body';
    case Association = 'association';
}
