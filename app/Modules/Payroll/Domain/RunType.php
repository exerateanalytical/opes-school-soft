<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * Payroll run types (docs/specs/05-hr-payroll.md 8.1, 8.7). `regularisation`
 * is the December run settling the YTD-cumulative IRPP residual (6.5 step 9);
 * `reversal` is the contrepassation run mirroring the Fees void pattern (H9) -
 * an approved run is never mutated, it is reversed by a new run.
 */
enum RunType: string
{
    case Regular = 'regular';
    case ThirteenthMonth = 'thirteenth_month';
    case FinalSettlement = 'final_settlement';
    case Regularisation = 'regularisation';
    case Reversal = 'reversal';
}
