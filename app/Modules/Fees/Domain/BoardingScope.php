<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.5 - boarding discriminator of a fee structure. Contributes 2
 * to the resolution specificity score.
 */
enum BoardingScope: string
{
    case Any = 'any';
    case Day = 'day';
    case Boarding = 'boarding';
}
