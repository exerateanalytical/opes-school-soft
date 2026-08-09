<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §6.1. A sale requires the buyer partner; every type
 * posts the same gross 81/82 shape, differing only in proceeds.
 */
enum DisposalType: string
{
    case Sale = 'sale';
    case Scrap = 'scrap';
    case DonationOut = 'donation_out';
    case Loss = 'loss';
    case Theft = 'theft';
    case TradeIn = 'trade_in';
}
