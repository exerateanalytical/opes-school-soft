<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

use App\Support\Rate\Rate;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §4.3 - the entitlement/catch-up engine.
 * Pure functions of scalars: no Eloquent, no queries, no clock. All money
 * is integer FCFA; rates live on the house scale (Rate::SCALE basis
 * points = 100%). Every domain number is a named constant - no naked
 * numeric policy literals.
 *
 *   entitlement(a, T) = min(base, round_half_up(base × elapsed / total))
 *   charge(a, T)      = entitlement(a, T) − Σ charges already posted
 *
 * Consequences (§4.3, all deliberate):
 *  - re-running a posted period yields charge = 0 - idempotent by the
 *    arithmetic, not by no-op guards;
 *  - a late-capitalised asset receives its full arrears as one catch-up;
 *  - the min() cap makes the final period absorb the rounding residual, so
 *    Σ charges = cost − residual EXACTLY (00-core §7.3);
 *  - a §5.5 estimate change is absorbed prospectively - entitlement uses
 *    current parameters while Σ posted is historical fact, and the charge
 *    may legitimately be NEGATIVE.
 *
 * Declining balance has no closed form: the period sequence is REPLAYED
 * deterministically month by month (§4.3) and differenced against posted.
 */
final class DepreciationCalculator
{
    /** §5.2 - a 360-day commercial year: 30 days per month for `daily`. */
    private const COMMERCIAL_DAYS_PER_MONTH = 30;

    /** §5.2 `monthly` - the start month counts iff day(start) ≤ 15. */
    private const MID_MONTH_DAY = 15;

    /** §5.2 `half_year` - six months in the commissioning year. */
    private const HALF_YEAR_MONTHS = 6;

    private const MONTHS_PER_YEAR = 12;

    /** Half-up rounding adds half the denominator before integer division. */
    private const HALF = 2;

    private function __construct() {}

    /**
     * Cumulative depreciation entitlement for the asset as at $asOfDate
     * (inclusive), under its own snapshotted policy.
     */
    public static function entitlement(
        DepreciationMethod $method,
        ProrataConvention $convention,
        int $acquisitionCost,
        int $residualValue,
        int $usefulLifeMonths,
        ?int $decliningRateBp,
        string $depreciationStartDate,
        string $asOfDate,
    ): int {
        if ($method === DepreciationMethod::None) {
            return 0;
        }

        if ($usefulLifeMonths <= 0) {
            throw new DomainException('A depreciating asset must carry a positive useful life.');
        }

        $base = $acquisitionCost - $residualValue;

        if ($base <= 0) {
            return 0;
        }

        $start = Carbon::parse($depreciationStartDate)->startOfDay();
        $asOf = Carbon::parse($asOfDate)->startOfDay();

        if ($asOf->lessThan($start)) {
            return 0;
        }

        if ($method === DepreciationMethod::DecliningBalance) {
            if ($decliningRateBp === null || $decliningRateBp <= 0) {
                throw new DomainException('Declining balance requires a positive declining_rate_bp (A2).');
            }

            return self::decliningEntitlement(
                $base,
                $usefulLifeMonths,
                $decliningRateBp,
                self::monthsElapsed($convention, $start, $asOf, $usefulLifeMonths),
            );
        }

        [$elapsed, $total] = self::straightLineUnits($convention, $start, $asOf, $usefulLifeMonths);

        if ($elapsed >= $total) {
            return $base;
        }

        return min($base, self::roundHalfUp($base * $elapsed, $total));
    }

    /**
     * §4.3 - the signed period charge: entitlement minus what history has
     * already posted. Negative after a §5.5 estimate reduction, and that
     * is correct (credit to 681x).
     */
    public static function charge(
        DepreciationMethod $method,
        ProrataConvention $convention,
        int $acquisitionCost,
        int $residualValue,
        int $usefulLifeMonths,
        ?int $decliningRateBp,
        string $depreciationStartDate,
        string $asOfDate,
        int $postedToDate,
    ): int {
        return self::entitlement(
            $method,
            $convention,
            $acquisitionCost,
            $residualValue,
            $usefulLifeMonths,
            $decliningRateBp,
            $depreciationStartDate,
            $asOfDate,
        ) - $postedToDate;
    }

    /**
     * Whole months elapsed for the schedule row's `months_elapsed` column
     * and for the declining-balance replay. §5.2 semantics; `daily` has no
     * sub-month meaning in a month-replayed sequence, so its replay counts
     * the start month in full (documented approximation, deterministic).
     */
    public static function monthsElapsed(
        ProrataConvention $convention,
        Carbon $start,
        Carbon $asOf,
        int $usefulLifeMonths,
    ): int {
        if ($asOf->lessThan($start)) {
            return 0;
        }

        $wholeMonths = ($asOf->year - $start->year) * self::MONTHS_PER_YEAR
            + ($asOf->month - $start->month)
            + 1; // both end months inclusive

        $elapsed = match ($convention) {
            ProrataConvention::Daily,
            ProrataConvention::FullMonth => $wholeMonths,
            ProrataConvention::Monthly => $start->day <= self::MID_MONTH_DAY
                ? $wholeMonths
                : $wholeMonths - 1,
            ProrataConvention::HalfYear => $asOf->year === $start->year
                ? self::HALF_YEAR_MONTHS
                : self::HALF_YEAR_MONTHS
                    + self::MONTHS_PER_YEAR * ($asOf->year - $start->year - 1)
                    + $asOf->month,
        };

        return max(0, min($elapsed, $usefulLifeMonths));
    }

    /**
     * §5.2 - (elapsed_units, total_units) for the straight-line formula.
     *
     * @return array{int, int}
     */
    private static function straightLineUnits(
        ProrataConvention $convention,
        Carbon $start,
        Carbon $asOf,
        int $usefulLifeMonths,
    ): array {
        if ($convention === ProrataConvention::Daily) {
            // Days from start to T inclusive over a 360-day commercial year.
            $elapsed = (int) $start->diffInDays($asOf) + 1;

            return [
                min($elapsed, $usefulLifeMonths * self::COMMERCIAL_DAYS_PER_MONTH),
                $usefulLifeMonths * self::COMMERCIAL_DAYS_PER_MONTH,
            ];
        }

        return [
            self::monthsElapsed($convention, $start, $asOf, $usefulLifeMonths),
            $usefulLifeMonths,
        ];
    }

    /**
     * §4.3 declining balance: replay the monthly sequence from the start
     * date, charge = round_half_up(NBV_opening × rate / SCALE), floored so
     * closing ≤ base; once the life is fully elapsed the entitlement is
     * the whole base (the cap absorbs the tail exactly).
     */
    private static function decliningEntitlement(
        int $base,
        int $usefulLifeMonths,
        int $decliningRateBp,
        int $monthsElapsed,
    ): int {
        if ($monthsElapsed >= $usefulLifeMonths) {
            return $base;
        }

        $accumulated = 0;

        for ($month = 1; $month <= $monthsElapsed; $month++) {
            $opening = $base - $accumulated;
            $charge = min($opening, self::roundHalfUp($opening * $decliningRateBp, Rate::SCALE));
            $accumulated += $charge;
        }

        return min($base, $accumulated);
    }

    /**
     * 00-core §7.3 - round half up, exactly once, in pure integers.
     * Operands here are bounded (cost ≤ ~10^10, units ≤ 18 000), far from
     * PHP_INT_MAX; the guard keeps the invariant honest anyway.
     */
    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new DomainException('Rounding denominator must be positive.');
        }

        if ($numerator < 0) {
            throw new DomainException('Entitlement arithmetic never produces a negative numerator.');
        }

        return intdiv($numerator + intdiv($denominator, self::HALF), $denominator);
    }
}
