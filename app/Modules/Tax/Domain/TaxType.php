<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §5.3. Withholding logic belongs to
 * Phase 5's WithholdingRule; a TaxCode of a withholding type exists in this
 * phase only so an invoice line can name it.
 */
enum TaxType: string
{
    case Tva = 'tva';
    case WithholdingAir = 'withholding_air';
    case WithholdingPrecompte = 'withholding_precompte';
    case Other = 'other';
}
