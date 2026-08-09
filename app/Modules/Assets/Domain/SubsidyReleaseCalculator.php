<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

use DomainException;

/**
 * docs/specs/06-assets-stores.md §6.4 - the quote-part de subvention virée
 * au résultat, mirrored off the depreciation entitlement so catch-up and
 * rounding behave identically:
 *
 *   release_entitlement(T) = min(granted,
 *                                round_half_up(granted × Σcharge(T) / base))
 *   release(T)             = release_entitlement(T) − Σ releases posted
 *
 * Partial funding falls out for free: an 18M grant on a 30M asset releases
 * 60% of every period's charge, and the min() cap lands Σ releases on the
 * granted amount exactly at end of life. Net P&L effect of a fully-funded
 * donated asset: zero, every month, for the whole life (§6.4).
 */
final class SubsidyReleaseCalculator
{
    /** Half-up rounding adds half the denominator before integer division. */
    private const HALF = 2;

    private function __construct() {}

    /**
     * Cumulative release entitlement as at the moment when the asset's
     * cumulative accounting charge is $cumulativeCharge.
     */
    public static function entitlement(
        int $grantedAmount,
        int $cumulativeCharge,
        int $depreciableBase,
    ): int {
        if ($grantedAmount <= 0) {
            throw new DomainException('A subsidy must carry a positive granted amount.');
        }

        if ($depreciableBase <= 0) {
            throw new DomainException('A subsidised asset must carry a positive depreciable base.');
        }

        if ($cumulativeCharge <= 0) {
            return 0;
        }

        return min(
            $grantedAmount,
            self::roundHalfUp($grantedAmount * $cumulativeCharge, $depreciableBase),
        );
    }

    /** The signed period release: entitlement minus history. */
    public static function release(
        int $grantedAmount,
        int $cumulativeCharge,
        int $depreciableBase,
        int $releasedToDate,
    ): int {
        return self::entitlement($grantedAmount, $cumulativeCharge, $depreciableBase)
            - $releasedToDate;
    }

    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, self::HALF), $denominator);
    }
}
