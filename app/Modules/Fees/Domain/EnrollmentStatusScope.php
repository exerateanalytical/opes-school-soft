<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.5 - enrollment-status discriminator of a fee structure.
 * Contributes 1 to the resolution specificity score.
 */
enum EnrollmentStatusScope: string
{
    case Any = 'any';
    case New = 'new';
    case Returning = 'returning';
    case Repeating = 'repeating';
}
