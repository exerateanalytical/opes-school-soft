<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Domain\IrppBracket;
use App\Modules\Payroll\Domain\IrppParameters;
use App\Modules\Payroll\Models\StatutoryRate;
use Illuminate\Support\Collection;

/**
 * The STRUCTURE of the IRPP formula (docs/specs/05-hr-payroll.md 6.2,
 * written literally): the 30% professional abatement with its 4,800,000/yr
 * cap (LF 2024), the 500,000/yr fixed abatement, and the 12-month
 * annualisation span.
 *
 * Why these constants may exist while every RATE ships empty (05 §0): they
 * are the published shape of the computation itself - the same standing as
 * `gross - deductions = net` - not a bracket, band or rate the school
 * transcribes from a notice. The values that DO vary by notice (the annual
 * brackets) ship as NULL-amount StatutoryRate shells, remain invisible
 * until verified, and BLOCK the run at preflight - so no IRPP is ever
 * computed from these constants alone. They live here in Support, outside
 * app/Modules/Payroll/Domain, because the Domain namespace carries no
 * numeric literal at all (4.3, architecture-tested).
 */
final class IrppFormula
{
    /** 30% professional abatement, at Rate::SCALE. */
    public const ABATEMENT_RATE_BP = 30_000;

    /** The abatement cap: 4,800,000 FCFA/year (400,000/month), LF 2024. */
    public const ABATEMENT_ANNUAL_CAP = 4_800_000;

    /** The fixed abatement: 500,000 FCFA per year. */
    public const FIXED_ABATEMENT_ANNUAL = 500_000;

    /** OHADA fixes the fiscal year at 1 Jan - 31 Dec: twelve months. */
    public const MONTHS_PER_YEAR = 12;

    /** The order-200 bases barrier of the 5.3 component seed (7.2 rule 3). */
    public const BASES_BARRIER_ORDER = 200;

    /**
     * Builds the engine's parameter object from the VERIFIED IRPP bracket
     * rows preflight check 6 has already validated (contiguous, from zero,
     * open top).
     *
     * @param  Collection<int, StatutoryRate>  $bracketRows
     */
    public static function parameters(Collection $bracketRows): IrppParameters
    {
        $brackets = array_values($bracketRows
            ->sortBy(fn (StatutoryRate $row): int => (int) $row->band_from)
            ->values()
            ->map(fn (StatutoryRate $row): IrppBracket => new IrppBracket(
                lowerInclusive: (int) $row->band_from,
                upperExclusive: $row->band_to,
                rateBp: (int) $row->employee_rate_bp,
                statutoryRateId: (int) $row->getKey(),
            ))
            ->all());

        return new IrppParameters(
            abatementRateBp: self::ABATEMENT_RATE_BP,
            abatementAnnualCap: self::ABATEMENT_ANNUAL_CAP,
            fixedAbatementAnnual: self::FIXED_ABATEMENT_ANNUAL,
            monthsPerYear: self::MONTHS_PER_YEAR,
            brackets: $brackets,
        );
    }

    private function __construct()
    {
    }
}
