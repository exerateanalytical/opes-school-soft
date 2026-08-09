<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The complete parameter set of the IRPP formula (docs/specs/05-hr-payroll.md
 * 6.2): the 30% professional abatement rate and its annual cap, the fixed
 * annual abatement, the number of months an annualisation spans, and the
 * annual brackets. ALL of it is passed in - the engine itself contains no
 * statutory number (4.3 architecture rule), so a Legislature changing any
 * of these touches configuration, never this module's Domain code.
 */
final readonly class IrppParameters
{
    /**
     * @param  list<IrppBracket>  $brackets  ascending, contiguous, open top
     */
    public function __construct(
        public int $abatementRateBp,
        public int $abatementAnnualCap,
        public int $fixedAbatementAnnual,
        public int $monthsPerYear,
        public array $brackets,
    ) {
    }
}
