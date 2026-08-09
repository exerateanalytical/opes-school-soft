<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §6.1 - how disposal proceeds settle. `receivable`
 * lands on 485 Créances sur cessions d'immobilisations (verified); the
 * treasury settlements require an explicit treasury account.
 */
enum DisposalSettlement: string
{
    case Receivable = 'receivable';
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileMoney = 'mobile_money';
}
