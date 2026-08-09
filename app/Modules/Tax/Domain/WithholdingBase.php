<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §6.2 - what a withholding rate applies
 * to. HT vs TTC differs by 19.25% of the base, and the legally correct
 * answer is NEEDS VERIFICATION per withholding type - which is why
 * WithholdingRule.base ships NULL and an unset base blocks confirmation.
 */
enum WithholdingBase: string
{
    case AmountHt = 'amount_ht';
    case AmountTtc = 'amount_ttc';
}
