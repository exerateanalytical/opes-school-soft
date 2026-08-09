<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §7.1 - the WELL-KNOWN declaration type
 * codes the engine has behaviour for. The authoritative list lives in the
 * `tax_declaration_types` REFERENCE TABLE (extensible, not verified);
 * this enum only names the codes the generators are written against, so a
 * typo fails at analysis time instead of matching nothing.
 */
enum DeclarationTypeCode: string
{
    case TvaMonthly = 'tva_monthly';
    case WithholdingMonthly = 'withholding_monthly';
    case AcompteIs = 'acompte_is';
    case DsfAnnual = 'dsf_annual';
}
