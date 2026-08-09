<?php

declare(strict_types=1);

use App\Modules\Assets\Domain\DepreciationCalculator;
use App\Modules\Assets\Domain\DepreciationMethod;
use App\Modules\Assets\Domain\ProrataConvention;
use Illuminate\Support\Carbon;

/**
 * 06-assets-stores.md acceptance 2 - the completeness property, against the
 * PURE calculator (no DB): over randomised lives (1..600 months) and costs
 * (1..100 000 000 FCFA), replaying every monthly period end,
 *
 *   Σ period charges = cost − residual, EXACTLY, and
 *   no period's cumulative total exceeds the cap.
 *
 * Plus the §4.4 worked figures (the 1 000 000 / 7 months rounding proof)
 * and the declining-balance replay properties.
 */
if (! function_exists('phase9DeprSimulateLife')) {
    /**
     * Replay every month-end from the start date through end of life and
     * return the list of period charges the entitlement maths produces.
     *
     * @return list<int>
     */
    function phase9DeprSimulateLife(
        DepreciationMethod $method,
        ProrataConvention $convention,
        int $cost,
        int $residual,
        int $lifeMonths,
        ?int $decliningRateBp,
        string $startDate,
    ): array {
        $charges = [];
        $posted = 0;

        // One extra year of periods proves the tail stays at zero after
        // the cap lands on cost − residual.
        $extraMonths = 12;
        $periodEnd = Carbon::parse($startDate)->endOfMonth()->startOfDay();

        for ($i = 0; $i < $lifeMonths + $extraMonths; $i++) {
            $charge = DepreciationCalculator::charge(
                $method,
                $convention,
                $cost,
                $residual,
                $lifeMonths,
                $decliningRateBp,
                $startDate,
                $periodEnd->toDateString(),
                $posted,
            );

            $entitlement = $posted + $charge;

            // The cap: cumulative entitlement never exceeds the base.
            expect($entitlement)->toBeLessThanOrEqual($cost - $residual)
                ->and($entitlement)->toBeGreaterThanOrEqual(0);

            $charges[] = $charge;
            $posted = $entitlement;

            $periodEnd = $periodEnd->addMonthNoOverflow()->endOfMonth()->startOfDay();
        }

        return $charges;
    }
}

it('sums straight-line charges to exactly cost − residual over randomised lives and costs (acceptance 2)', function (): void {
    mt_srand(91_902); // deterministic property run

    $conventions = [
        ProrataConvention::Monthly,
        ProrataConvention::FullMonth,
        ProrataConvention::Daily,
        ProrataConvention::HalfYear,
    ];

    for ($case = 0; $case < 25; $case++) {
        $cost = mt_rand(1, 100_000_000);
        $life = mt_rand(1, 600);
        $residual = mt_rand(0, 4) === 0 ? mt_rand(0, max(0, $cost - 1)) : 0;
        $convention = $conventions[$case % count($conventions)];
        $day = $convention === ProrataConvention::Monthly ? mt_rand(1, 15) : mt_rand(1, 28);
        $start = sprintf('2430-%02d-%02d', mt_rand(1, 12), $day);

        $charges = phase9DeprSimulateLife(
            DepreciationMethod::StraightLine,
            $convention,
            $cost,
            $residual,
            $life,
            null,
            $start,
        );

        expect(array_sum($charges))->toBe($cost - $residual);

        // After the life is served, every further period charge is zero.
        $tail = array_slice($charges, -6);
        expect(array_sum($tail))->toBe(0);
    }
});

it('reproduces the §4.4 rounding proof: 1 000 000 over 7 months', function (): void {
    $charges = phase9DeprSimulateLife(
        DepreciationMethod::StraightLine,
        ProrataConvention::FullMonth,
        1_000_000,
        0,
        7,
        null,
        '2430-01-01',
    );

    expect(array_slice($charges, 0, 7))->toBe([
        142_857, 142_857, 142_857, 142_858, 142_857, 142_857, 142_857,
    ])->and(array_sum($charges))->toBe(1_000_000);
});

it('reproduces the §4.4 minibus entitlements', function (): void {
    // 35 775 000 / 120 months, monthly convention, start 2430-09-01.
    $september = DepreciationCalculator::entitlement(
        DepreciationMethod::StraightLine,
        ProrataConvention::Monthly,
        35_775_000,
        0,
        120,
        null,
        '2430-09-01',
        '2430-09-30',
    );

    $october = DepreciationCalculator::entitlement(
        DepreciationMethod::StraightLine,
        ProrataConvention::Monthly,
        35_775_000,
        0,
        120,
        null,
        '2430-09-01',
        '2430-10-31',
    );

    expect($september)->toBe(298_125)
        ->and($october)->toBe(596_250);
});

it('applies the monthly convention mid-month rule (day ≤ 15 counts the start month)', function (): void {
    $early = DepreciationCalculator::entitlement(
        DepreciationMethod::StraightLine,
        ProrataConvention::Monthly,
        1_200_000,
        0,
        12,
        null,
        '2430-03-15',
        '2430-03-31',
    );

    $late = DepreciationCalculator::entitlement(
        DepreciationMethod::StraightLine,
        ProrataConvention::Monthly,
        1_200_000,
        0,
        12,
        null,
        '2430-03-16',
        '2430-03-31',
    );

    expect($early)->toBe(100_000)
        ->and($late)->toBe(0);
});

it('replays declining balance deterministically and lands on the base at end of life (acceptance 2)', function (): void {
    mt_srand(91_903);

    for ($case = 0; $case < 10; $case++) {
        $cost = mt_rand(100_000, 100_000_000);
        $life = mt_rand(2, 120);
        $rateBp = mt_rand(1_000, 40_000); // 1%..40% per month, house scale

        $charges = phase9DeprSimulateLife(
            DepreciationMethod::DecliningBalance,
            ProrataConvention::FullMonth,
            $cost,
            0,
            $life,
            $rateBp,
            '2430-01-01',
        );

        // Completeness: the end-of-life absorption lands Σ on the base.
        expect(array_sum($charges))->toBe($cost);

        // Declining shape: while in-life and uncapped, charges never grow.
        $inLife = array_slice($charges, 0, $life - 1);
        $previous = null;

        foreach ($inLife as $charge) {
            if ($previous !== null) {
                expect($charge)->toBeLessThanOrEqual($previous);
            }

            $previous = $charge;
        }
    }
});

it('computes a signed negative charge after a prospective life extension (§5.5)', function (): void {
    // 1 000 000 over 10 months, two months already posted = 200 000.
    // The accountant corrects the life to 40 months: entitlement at month
    // three is round(1 000 000 × 3/40) = 75 000 → charge −125 000.
    $charge = DepreciationCalculator::charge(
        DepreciationMethod::StraightLine,
        ProrataConvention::FullMonth,
        1_000_000,
        0,
        40,
        null,
        '2430-01-01',
        '2430-03-31',
        200_000,
    );

    expect($charge)->toBe(-125_000);
});

it('mirrors subsidy releases onto the charge stream franc for franc when fully funded', function (): void {
    // Fully funded: granted = base. Whatever the charge stream does, the
    // release stream must equal it, period by period (§6.4 neutrality).
    $cost = 3_000_000;
    $life = 7;

    $charges = phase9DeprSimulateLife(
        DepreciationMethod::StraightLine,
        ProrataConvention::FullMonth,
        $cost,
        0,
        $life,
        null,
        '2430-01-01',
    );

    $cumulative = 0;
    $released = 0;

    foreach ($charges as $charge) {
        $cumulative += $charge;

        $release = App\Modules\Assets\Domain\SubsidyReleaseCalculator::release(
            $cost,
            $cumulative,
            $cost,
            $released,
        );

        expect($release)->toBe($charge);
        $released += $release;
    }

    expect($released)->toBe($cost);
});

it('caps partial-funding releases on the granted amount exactly (§6.4)', function (): void {
    $cost = 3_000_000;
    $granted = 1_800_000; // 60%
    $life = 5;

    $charges = phase9DeprSimulateLife(
        DepreciationMethod::StraightLine,
        ProrataConvention::FullMonth,
        $cost,
        0,
        $life,
        null,
        '2430-01-01',
    );

    $cumulative = 0;
    $released = 0;

    foreach ($charges as $charge) {
        $cumulative += $charge;
        $released += App\Modules\Assets\Domain\SubsidyReleaseCalculator::release(
            $granted,
            $cumulative,
            $cost,
            $released,
        );
    }

    expect($released)->toBe($granted);
});
