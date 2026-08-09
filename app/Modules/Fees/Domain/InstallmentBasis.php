<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.6. Percentage plans must sum to exactly 1 000 000 bp (100%);
 * fixed plans are validated against the invoice total at application time.
 */
enum InstallmentBasis: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
