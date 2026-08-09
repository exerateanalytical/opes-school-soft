<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.2.1 - set membership test of a criterion row.
 */
enum CriterionOperator: string
{
    case In = 'in';
    case NotIn = 'not_in';
}
