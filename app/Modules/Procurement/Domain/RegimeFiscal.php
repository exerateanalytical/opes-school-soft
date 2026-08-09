<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §3.1 - the supplier's own tax regime,
 * as declared on their documents. Feeds the §6.4 withholding resolution
 * (a liberatoire trader is withheld differently from a reel company).
 */
enum RegimeFiscal: string
{
    case Reel = 'reel';
    case Simplifie = 'simplifie';
    case Liberatoire = 'liberatoire';
    case NonProfessionnel = 'non_professionnel';
    case Unknown = 'unknown';
}
