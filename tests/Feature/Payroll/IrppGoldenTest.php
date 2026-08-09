<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\IrppBracket;
use App\Modules\Payroll\Domain\IrppEngine;
use App\Modules\Payroll\Domain\IrppParameters;
use App\Modules\Payroll\Domain\Rational;
use App\Modules\Payroll\Support\IrppFormula;

/*
 * docs/specs/05-hr-payroll.md 6.3-6.4: the IRPP engine exercised in pure
 * isolation - no database, no run, no fixture staff - against the exact
 * CLEISS 2024 reference bracket table (@statutory-reference, 2.3). These
 * VALUES exist only here as test data, never in a seeder or migration
 * (SeederRefusalTest asserts that).
 */

if (! function_exists('p11irppReferenceEngine')) {
    /**
     * @statutory-reference the annual brackets 10/15/25/35% at
     * 0-2M/2-3M/3-5M/>5M (docs/specs/05-hr-payroll.md 6.3), the 30%
     * abatement capped at 4,800,000/yr, the 500,000/yr fixed abatement.
     */
    function p11irppReferenceEngine(): IrppEngine
    {
        $brackets = [
            new IrppBracket(lowerInclusive: 0, upperExclusive: 2_000_000, rateBp: 10_000),
            new IrppBracket(lowerInclusive: 2_000_000, upperExclusive: 3_000_000, rateBp: 15_000),
            new IrppBracket(lowerInclusive: 3_000_000, upperExclusive: 5_000_000, rateBp: 25_000),
            new IrppBracket(lowerInclusive: 5_000_000, upperExclusive: null, rateBp: 35_000),
        ];

        return new IrppEngine(new IrppParameters(
            abatementRateBp: IrppFormula::ABATEMENT_RATE_BP,
            abatementAnnualCap: IrppFormula::ABATEMENT_ANNUAL_CAP,
            fixedAbatementAnnual: IrppFormula::FIXED_ABATEMENT_ANNUAL,
            monthsPerYear: IrppFormula::MONTHS_PER_YEAR,
            brackets: $brackets,
        ));
    }
}

describe('6.3 bracket boundaries, both directions', function () {
    // NC_annual -> expected annual tax expressed in CENTIMES (x100) so the
    // sub-franc slice at each boundary (e.g. 0.15 FCFA on the first franc
    // into a higher bracket) is asserted exactly, not lost to rounding.
    $cases = [
        1_999_999 => 19_999_990,  // wholly in the 10% band
        2_000_000 => 20_000_000,  // exactly the 10% band's top
        2_000_001 => 20_000_015,  // one franc into the 15% band
        2_999_999 => 34_999_985,  // wholly through the 15% band
        3_000_000 => 35_000_000,  // exactly the 15% band's top
        3_000_001 => 35_000_025,  // one franc into the 25% band
        4_999_999 => 84_999_975,  // wholly through the 25% band
        5_000_000 => 85_000_000,  // exactly the 25% band's top
        5_000_001 => 85_000_035,  // one franc into the open 35% band
    ];

    foreach ($cases as $ncAnnual => $expectedCentimes) {
        it("computes annual tax exactly at NC_annual = {$ncAnnual}", function () use ($ncAnnual, $expectedCentimes): void {
            $engine = p11irppReferenceEngine();

            [$tax, $detail] = $engine->annualTax(Rational::ofInt($ncAnnual));

            $centimes = $tax->times(Rational::ofInt(100))->roundHalfUp();

            expect($centimes)->toBe($expectedCentimes)
                ->and($detail)->not->toBeEmpty();
        });
    }
});

describe('6.4 worked examples, to the franc', function () {
    it('reproduces Example A - SBT 80,000/month', function (): void {
        $engine = p11irppReferenceEngine();

        $result = $engine->monthlyAnnualised(80_000, 3_360);

        expect($result->irppMonth)->toBe(1_097);
    });

    it('reproduces Example B - SBT 250,000/month', function (): void {
        $engine = p11irppReferenceEngine();

        $result = $engine->monthlyAnnualised(250_000, 10_500);

        expect($result->irppMonth)->toBe(12_283);
    });

    it('reproduces Example C - SBT 900,000/month (above the CNPS ceiling)', function (): void {
        $engine = p11irppReferenceEngine();

        $result = $engine->monthlyAnnualised(900_000, 31_500);

        expect($result->irppMonth)->toBe(119_892);
    });

    it('reproduces Example D - SBT 1,500,000/month (the abatement cap binds)', function (): void {
        $engine = p11irppReferenceEngine();

        $result = $engine->monthlyAnnualised(1_500_000, 31_500);

        expect($result->irppMonth)->toBe(284_392);
    });
});

describe('N4 regression - the 30% abatement applies to SBT, not to (SBT - PVID)', function () {
    it('does not over-withhold by applying the abatement after PVID is removed', function (): void {
        $engine = p11irppReferenceEngine();

        // Example B's annual figures.
        $sbtAnnual = Rational::ofInt(3_000_000);
        $pvidAnnual = Rational::ofInt(126_000);

        $correct = $engine->netCategorielAnnual($sbtAnnual, $pvidAnnual);

        expect($correct->roundHalfUp())->toBe(1_474_000);

        // The N4 counterexample, computed by hand exactly as 05-hr-payroll
        // 6.4 states it: 0.70 x (SBT_annual - PVID_annual) - 500,000.
        $wrong = Rational::of(70_000, 100_000)
            ->times($sbtAnnual->minus($pvidAnnual))
            ->minus(Rational::ofInt(500_000));

        expect($wrong->roundHalfUp())->toBe(1_511_800)
            ->and($wrong->minus($correct)->roundHalfUp())->toBe(37_800);
    });
});

describe('A30 cap regression - the 4,800,000/yr abatement cap (LF 2024)', function () {
    it('binds at Example D and would under-withhold by 210,000/yr if it did not', function (): void {
        $capped = p11irppReferenceEngine();

        $sbtAnnual = Rational::ofInt(18_000_000);
        $pvidAnnual = Rational::ofInt(378_000);

        $ncCapped = $capped->netCategorielAnnual($sbtAnnual, $pvidAnnual);

        expect($ncCapped->roundHalfUp())->toBe(12_322_000);

        [$taxCapped] = $capped->annualTax($ncCapped);
        expect($taxCapped->roundHalfUp())->toBe(3_412_700);

        // The same engine, with the cap raised so it never binds - the
        // regression this test exists to catch (05-hr-payroll 6.4).
        $uncapped = new IrppEngine(new IrppParameters(
            abatementRateBp: IrppFormula::ABATEMENT_RATE_BP,
            abatementAnnualCap: 999_999_999_999,
            fixedAbatementAnnual: IrppFormula::FIXED_ABATEMENT_ANNUAL,
            monthsPerYear: IrppFormula::MONTHS_PER_YEAR,
            brackets: [
                new IrppBracket(lowerInclusive: 0, upperExclusive: 2_000_000, rateBp: 10_000),
                new IrppBracket(lowerInclusive: 2_000_000, upperExclusive: 3_000_000, rateBp: 15_000),
                new IrppBracket(lowerInclusive: 3_000_000, upperExclusive: 5_000_000, rateBp: 25_000),
                new IrppBracket(lowerInclusive: 5_000_000, upperExclusive: null, rateBp: 35_000),
            ],
        ));

        $ncUncapped = $uncapped->netCategorielAnnual($sbtAnnual, $pvidAnnual);
        expect($ncUncapped->roundHalfUp())->toBe(11_722_000);

        [$taxUncapped] = $uncapped->annualTax($ncUncapped);
        expect($taxUncapped->roundHalfUp())->toBe(3_202_700)
            ->and($taxCapped->minus($taxUncapped)->roundHalfUp())->toBe(210_000);
    });
});
