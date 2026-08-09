<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The engine's answer for one employee for one month.
 *
 * `irppMonth` is the withheld amount - the ONLY rounded figure in the
 * chain (6.3 step 7), and the basis CAC is computed on. `taxableBaseMonthly`
 * is NC_annual / months, rounded for DISPLAY on the payslip only (10.2
 * `taxable_base`); it never feeds further arithmetic. `bracketDetail` is
 * the per-bracket breakdown the payslip and PayrollLine.bracket_detail
 * carry.
 */
final readonly class IrppResult
{
    /**
     * @param  list<array{lower: int, upper: int|null, rate_bp: int, slice: int, tax: int}>  $bracketDetail
     */
    public function __construct(
        public int $irppMonth,
        public int $taxableBaseMonthly,
        public array $bracketDetail,
    ) {
    }
}
