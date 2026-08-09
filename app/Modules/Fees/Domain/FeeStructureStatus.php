<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.5. Only `active` structures participate in resolution;
 * `draft` is freely editable, `archived` is history.
 */
enum FeeStructureStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
