<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §2.2.
 */
enum AcquisitionType: string
{
    case Purchase = 'purchase';
    case Donation = 'donation';
    case GrantFunded = 'grant_funded';
    case SelfConstructed = 'self_constructed';
    case TransferIn = 'transfer_in';
    case OpeningBalance = 'opening_balance';
}
