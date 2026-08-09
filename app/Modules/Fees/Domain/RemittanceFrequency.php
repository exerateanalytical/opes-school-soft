<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.3 - how often a third-party fund is remitted.
 */
enum RemittanceFrequency: string
{
    case OnDemand = 'on_demand';
    case Monthly = 'monthly';
    case Termly = 'termly';
    case Annual = 'annual';
}
