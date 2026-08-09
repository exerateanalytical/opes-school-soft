<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §6.2 - the kind of withholding a rule
 * implements. Rates are NEVER seeded (§6.1); the type is just vocabulary.
 */
enum WithholdingType: string
{
    case Air = 'air';
    case PrecompteAchats = 'precompte_achats';
    case PrecompteStationService = 'precompte_station_service';
    case NoContributorCard = 'no_contributor_card';
    case NiuInactive = 'niu_inactive';
    case Other = 'other';
}
