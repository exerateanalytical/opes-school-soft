<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.3 - who a third-party fund is held for.
 */
enum BeneficiaryType: string
{
    case Apee = 'apee';
    case ExamBoard = 'exam_board';
    case Ministry = 'ministry';
    case Insurer = 'insurer';
    case Other = 'other';
}
