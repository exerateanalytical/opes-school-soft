<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * 07-students 4.1. `FeeItem.applies_to new|returning` (04-fees) reads THIS,
 * never a person-level flag - a returning student who was withdrawn and
 * re-admitted is `re_admission` for that year and `returning` for the next.
 */
enum EnrollmentType: string
{
    case New = 'new';
    case Returning = 'returning';
    case TransferIn = 'transfer_in';
    case ReAdmission = 're_admission';
}
