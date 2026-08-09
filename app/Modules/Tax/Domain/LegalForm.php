<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §2.1 - the school's legal form. The exact
 * enumeration NEEDS VERIFICATION against OHADA AUSCGIE forms available to a
 * Cameroonian private school; `Other` is the escape hatch until then.
 *
 * Drives which registration number is mandatory: a commercial form requires
 * the RCCM number (§2.1).
 */
enum LegalForm: string
{
    case EtablissementPriveLaic = 'etablissement_prive_laic';
    case EtablissementConfessionnel = 'etablissement_confessionnel';
    case Sarl = 'sarl';
    case Sa = 'sa';
    case Association = 'association';
    case Fondation = 'fondation';
    case Gie = 'gie';
    case EtablissementIndividuel = 'etablissement_individuel';
    case Other = 'other';

    /**
     * Commercial forms are registered at the greffe, so the RCCM extract
     * exists and its number is mandatory on the fiscal identity.
     */
    public function requiresRccm(): bool
    {
        return match ($this) {
            self::Sarl, self::Sa, self::Gie, self::EtablissementIndividuel => true,
            default => false,
        };
    }
}
