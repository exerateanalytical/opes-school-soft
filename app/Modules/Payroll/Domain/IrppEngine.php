<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Support\Rate\Rate;

/**
 * The IRPP engine, written literally from docs/specs/05-hr-payroll.md 6.
 *
 * Two load-bearing corrections from v1 live here:
 *
 *  - N4: the 30% abatement applies to SBT; PVID is deducted SEPARATELY at
 *    100%. It is NOT 0.70 x (SBT - PVID) - that reading over-withholds on
 *    every employee every month (6.4 Example B: 315 FCFA/month at 250,000).
 *  - N3: there is NO dependants relief in Cameroonian salary IRPP. No
 *    dependants identifier exists anywhere in this path - an architecture
 *    test asserts the absence of the word itself.
 *
 * Arithmetic is EXACT RATIONAL end to end; the single rounding of the
 * chain is the monthly withholding (6.3 step 7, 00-core 7.3 "round once").
 * No statutory number lives here: brackets, abatement rate, caps and the
 * months-per-year span all arrive via IrppParameters (4.3).
 *
 * YTD-cumulative mode (6.5, the DEFAULT - H10): the projected annual tax
 * is rounded to a MONTHLY figure once and the cumulative due is that
 * monthly figure times m, so for perfectly flat pay the mode is EXACTLY
 * equivalent to 6.3 annualisation in every month m = 1..12 - the H10
 * equivalence property the tests assert. Any drift a varying year leaves
 * behind at m = 12 is the December regularisation residual (8.7).
 */
final readonly class IrppEngine
{
    public function __construct(private IrppParameters $parameters)
    {
    }

    /**
     * 6.3 - ANNUALISE -> ANNUAL BRACKETS -> DIVIDE -> ROUND ONCE.
     * Exact for flat pay; the general case is monthlyYtdCumulative().
     */
    public function monthlyAnnualised(int $sbtMonth, int $pvidEeMonth): IrppResult
    {
        $months = Rational::ofInt($this->parameters->monthsPerYear);

        $sbtAnnual = Rational::ofInt($sbtMonth)->times($months);
        $pvidAnnual = Rational::ofInt($pvidEeMonth)->times($months);

        $ncAnnual = $this->netCategorielAnnual($sbtAnnual, $pvidAnnual);

        [$taxAnnual, $detail] = $this->annualTax($ncAnnual);

        return new IrppResult(
            irppMonth: $taxAnnual->dividedBy($months)->roundHalfUp(),
            taxableBaseMonthly: $ncAnnual->dividedBy($months)->roundHalfUp(),
            bracketDetail: $detail,
        );
    }

    /**
     * 6.5 - the YTD-cumulative default. All Σ inputs INCLUDE the current
     * month; `priorWithheld` is Σ IRPP withheld in months 1..m-1, read from
     * snapshots and never recomputed (10).
     */
    public function monthlyYtdCumulative(
        int $ytdSbt,
        int $ytdPvidEe,
        int $monthIndex,
        int $priorWithheld,
    ): IrppResult {
        $months = Rational::ofInt($this->parameters->monthsPerYear);
        $m = Rational::ofInt($monthIndex);

        $sbtYtd = Rational::ofInt($ytdSbt);
        $pvidYtd = Rational::ofInt($ytdPvidEe);

        // Step 3: the abatement cap accrues month by month - cap x m / 12.
        $abatement = Rate::ofBasisPoints($this->parameters->abatementRateBp);
        $a30 = $sbtYtd
            ->times(Rational::of($abatement->basisPoints(), Rate::SCALE))
            ->min(Rational::ofInt($this->parameters->abatementAnnualCap)->times($m)->dividedBy($months));

        // Step 4: the fixed abatement accrues the same way.
        $ncYtd = $sbtYtd
            ->minus($a30)
            ->minus($pvidYtd)
            ->minus(Rational::ofInt($this->parameters->fixedAbatementAnnual)->times($m)->dividedBy($months));

        // Step 5: project to a full year; clamp at zero (step 6 input).
        $ncProjected = $ncYtd->times($months)->dividedBy($m)->max(Rational::zero());

        [$taxProjected, $detail] = $this->annualTax($ncProjected);

        // Steps 7-8: ONE rounding - the projected monthly withholding -
        // then the cumulative due is that monthly figure x m. This is what
        // makes flat pay reproduce 6.3 exactly, month by month (H10).
        $monthlyProjected = $taxProjected->dividedBy($months)->roundHalfUp();
        $dueYtd = Rational::ofInt($monthlyProjected)->times($m)->roundHalfUp();

        // Step 9: never a negative withholding line - an over-withheld
        // employee is corrected by reduced FUTURE withholding, and any
        // December residual belongs to the regularisation run.
        $irppMonth = Rational::ofInt($dueYtd)
            ->minus(Rational::ofInt($priorWithheld))
            ->max(Rational::zero())
            ->roundHalfUp();

        return new IrppResult(
            irppMonth: $irppMonth,
            taxableBaseMonthly: $ncProjected->dividedBy($months)->roundHalfUp(),
            bracketDetail: $detail,
        );
    }

    /**
     * 6.2 expanded, with the cap explicit:
     *
     *   A30 = min( rate x SBT , cap )
     *   NC  = SBT - A30 - PVID - fixed        -- N4: PVID at 100%, separately
     *   NC  = max( NC , 0 )
     */
    public function netCategorielAnnual(Rational $sbtAnnual, Rational $pvidAnnual): Rational
    {
        $abatement = Rational::of($this->parameters->abatementRateBp, Rate::SCALE);

        $a30 = $sbtAnnual
            ->times($abatement)
            ->min(Rational::ofInt($this->parameters->abatementAnnualCap));

        return $sbtAnnual
            ->minus($a30)
            ->minus($pvidAnnual)
            ->minus(Rational::ofInt($this->parameters->fixedAbatementAnnual))
            ->max(Rational::zero());
    }

    /**
     * 6.3 step 6: Σ over brackets of rate x (min(NC, upper) - lower), for
     * NC above the bracket floor. Exact; the caller rounds once.
     *
     * @return array{0: Rational, 1: list<array{lower: int, upper: int|null, rate_bp: int, slice: int, tax: int}>}
     */
    public function annualTax(Rational $ncAnnual): array
    {
        $total = Rational::zero();
        $detail = [];

        foreach ($this->parameters->brackets as $bracket) {
            $lower = Rational::ofInt($bracket->lowerInclusive);

            $ceiling = $bracket->upperExclusive === null
                ? $ncAnnual
                : $ncAnnual->min(Rational::ofInt($bracket->upperExclusive));

            $slice = $ceiling->minus($lower)->max(Rational::zero());
            $tax = $slice->times(Rational::of($bracket->rateBp, Rate::SCALE));

            $total = $total->plus($tax);

            if (! $slice->isZero()) {
                $detail[] = [
                    'lower' => $bracket->lowerInclusive,
                    'upper' => $bracket->upperExclusive,
                    'rate_bp' => $bracket->rateBp,
                    // Display figures only - the running total stays exact.
                    'slice' => $slice->roundHalfUp(),
                    'tax' => $tax->roundHalfUp(),
                ];
            }
        }

        return [$total, $detail];
    }
}
