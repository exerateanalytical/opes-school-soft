<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * What an insurance policy covers (design doc §14): the SAME
 * insurance_policies table carries student group cover (a per-head premium
 * billed through a FeeItem) and asset cover (a Phase 9 register item,
 * linked by bare asset_id until the follow-up FK lands).
 */
enum InsuranceCoverType: string
{
    case Student = 'student';
    case Asset = 'asset';
}
