<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\IrppBracket;
use App\Modules\Payroll\Domain\IrppEngine;
use App\Modules\Payroll\Domain\IrppParameters;
use App\Modules\Payroll\Support\IrppFormula;

/*
 * docs/specs/05-hr-payroll.md 6.5 (H10): for PERFECTLY FLAT pay, the
 * YTD-cumulative default mode must reproduce the 6.3 annualisation exactly,
 * month by month, for m = 1..12. Both paths share the SAME reference
 * bracket table (@statutory-reference, 2.3) as IrppGoldenTest.
 */

if (! function_exists('p11ytdReferenceEngine')) {
    function p11ytdReferenceEngine(): IrppEngine
    {
        return new IrppEngine(new IrppParameters(
            abatementRateBp: IrppFormula::ABATEMENT_RATE_BP,
            abatementAnnualCap: IrppFormula::ABATEMENT_ANNUAL_CAP,
            fixedAbatementAnnual: IrppFormula::FIXED_ABATEMENT_ANNUAL,
            monthsPerYear: IrppFormula::MONTHS_PER_YEAR,
            brackets: [
                new IrppBracket(lowerInclusive: 0, upperExclusive: 2_000_000, rateBp: 10_000),
                new IrppBracket(lowerInclusive: 2_000_000, upperExclusive: 3_000_000, rateBp: 15_000),
                new IrppBracket(lowerInclusive: 3_000_000, upperExclusive: 5_000_000, rateBp: 25_000),
                new IrppBracket(lowerInclusive: 5_000_000, upperExclusive: null, rateBp: 35_000),
            ],
        ));
    }
}

// Examples A-D's flat monthly SBT/PVID_EE pairs, property-tested for m = 1..12
// (05-hr-payroll 6.5's own instruction: "Property-tested at Examples A-D").
$flatPayCases = [
    'Example A (80,000/month, below every ceiling)' => [80_000, 3_360],
    'Example B (250,000/month, the N4 salary)' => [250_000, 10_500],
    'Example C (900,000/month, above the CNPS ceiling)' => [900_000, 31_500],
    'Example D (1,500,000/month, the abatement cap binds)' => [1_500_000, 31_500],
];

foreach ($flatPayCases as $label => [$sbtMonth, $pvidEeMonth]) {
    it("YTD-cumulative reproduces 6.3 annualisation every month for flat pay: {$label}", function () use ($sbtMonth, $pvidEeMonth): void {
        $engine = p11ytdReferenceEngine();

        $expectedMonthly = $engine->monthlyAnnualised($sbtMonth, $pvidEeMonth)->irppMonth;

        $priorWithheld = 0;

        for ($m = 1; $m <= 12; $m++) {
            $result = $engine->monthlyYtdCumulative(
                ytdSbt: $sbtMonth * $m,
                ytdPvidEe: $pvidEeMonth * $m,
                monthIndex: $m,
                priorWithheld: $priorWithheld,
            );

            // YTD-cumulative must equal annualisation exactly, every month,
            // for perfectly flat pay (05-hr-payroll 6.5, the H10 property).
            expect($result->irppMonth)->toBe($expectedMonthly);

            $priorWithheld += $result->irppMonth;
        }

        // Flat pay leaves no December regularisation residual (8.7): the
        // sum of twelve equal monthly withholdings IS the annual tax the
        // one-shot annualised path would have produced directly.
        expect($priorWithheld)->toBe($expectedMonthly * 12);
    });
}

it('never withholds a negative amount even when an over-withheld month would otherwise go negative', function (): void {
    $engine = p11ytdReferenceEngine();

    // A large month-1 bonus followed by nothing: month 2's projection
    // collapses, but step 9 clamps at zero rather than issuing a refund
    // through the withholding line (05-hr-payroll 6.5 step 9).
    $m1 = $engine->monthlyYtdCumulative(ytdSbt: 5_000_000, ytdPvidEe: 210_000, monthIndex: 1, priorWithheld: 0);

    expect($m1->irppMonth)->toBeGreaterThan(0);

    $m2 = $engine->monthlyYtdCumulative(
        ytdSbt: 5_000_000, // no additional SBT in month 2
        ytdPvidEe: 210_000,
        monthIndex: 2,
        priorWithheld: $m1->irppMonth,
    );

    expect($m2->irppMonth)->toBeGreaterThanOrEqual(0);
});
