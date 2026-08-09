<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §8.2 - governs only the derived instalment
 * satisfaction, never the ledger.
 */
enum AdjustmentApplicationMethod: string
{
    case ProRata = 'pro_rata';
    case EarliestFirst = 'earliest_first';
    case LatestFirst = 'latest_first';
    case SpecificInstalment = 'specific_instalment';
}
