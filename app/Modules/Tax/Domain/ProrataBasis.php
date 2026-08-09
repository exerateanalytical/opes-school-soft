<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §5.4 - a VatProrata row is either the
 * provisional prorata applied during the year (normally N−1's definitive)
 * or the definitive prorata computed from actual year-end turnover.
 */
enum ProrataBasis: string
{
    case Provisional = 'provisional';
    case Definitive = 'definitive';
}
