<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use DomainException;
use InvalidArgumentException;

/**
 * docs/specs/06-assets-stores.md §7.1 - CUMP maintained as a VALUE TOTAL.
 *
 * The average is never stored as a unit price. Each (item, location) balance
 * carries `quantity_on_hand` DECIMAL(14,3) and `value_on_hand` BIGINT
 * (authoritative, whole FCFA); the unit cost is derived at the moment of use:
 *
 *   issue_cost = round_half_up( issue_qty x value_on_hand / quantity_on_hand )
 *
 * with ONE override: if issue_qty == quantity_on_hand, issue_cost =
 * value_on_hand EXACTLY, so emptying a bin always leaves value zero (I8) -
 * the "last instalment absorbs the residual" rule of 00-core §7.3 applied to
 * stock. Because the average is recomputed from totals every time rather
 * than carried forward as a rounded scalar, a single issue's rounding never
 * compounds - over 10,000 issues the stock account still ties to the count
 * (StockLedgerTieTest).
 *
 * Pure functions of scalars: no Eloquent, no queries. Quantities travel as
 * DECIMAL(14,3) strings; the arithmetic runs on integer millis (bcmath for
 * the one product that can exceed 64 bits).
 */
final class WeightedAverageCost
{
    /**
     * The cost consumed by taking `issueQuantity` out of a bin holding
     * `quantityOnHand` / `valueOnHand`.
     */
    public static function issueCost(string $issueQuantity, string $quantityOnHand, int $valueOnHand): int
    {
        $issueMillis = self::millis($issueQuantity);
        $onHandMillis = self::millis($quantityOnHand);

        if ($issueMillis <= 0) {
            throw new InvalidArgumentException('An issue quantity must be strictly positive.');
        }

        if ($issueMillis > $onHandMillis) {
            throw new DomainException(
                "Cannot cost an issue of {$issueQuantity} from a bin holding {$quantityOnHand} (I6: negative stock is rejected)."
            );
        }

        // The empty-bin override - exact, not round(q x v / q), which happens
        // to agree only when nothing was ever rounded.
        if ($issueMillis === $onHandMillis) {
            return $valueOnHand;
        }

        return self::roundHalfUpRatio($issueMillis, $valueOnHand, $onHandMillis);
    }

    /**
     * §8.4 - a stock-take variance priced at the FROZEN derived cost.
     * Positive variance (overage) is priced at the same derived unit cost
     * (there is no purchase document to price it from); a shortage that
     * empties the bin absorbs the whole residual value. Returns a SIGNED
     * value carrying the variance's own sign. A variance found on an empty
     * system position has no cost basis and is valued at zero.
     */
    public static function varianceValue(string $varianceQuantity, string $systemQuantity, int $systemValue): int
    {
        $varianceMillis = self::millis($varianceQuantity);
        $systemMillis = self::millis($systemQuantity);

        if ($varianceMillis === 0) {
            return 0;
        }

        if ($systemMillis <= 0) {
            return 0; // No derived cost exists to price it with.
        }

        // A shortage of the entire system quantity takes the entire value.
        if (-$varianceMillis === $systemMillis) {
            return -$systemValue;
        }

        $magnitude = self::roundHalfUpRatio(abs($varianceMillis), $systemValue, $systemMillis);

        return $varianceMillis > 0 ? $magnitude : -$magnitude;
    }

    /**
     * I12's descriptive `unit_cost` snapshot: round(abs(total)/abs(qty)).
     * Never an input to anything - `total_cost` is authoritative.
     */
    public static function descriptiveUnitCost(string $quantity, int $totalCost): int
    {
        $millis = abs(self::millis($quantity));

        if ($millis === 0) {
            return 0;
        }

        // unit = total / qty = total x 1000 / millis, on the same half-up.
        return self::roundHalfUpRatio(1000, abs($totalCost), $millis);
    }

    /** DECIMAL(14,3) string arithmetic, exposed for balance updates. */
    public static function add(string $a, string $b): string
    {
        return bcadd(self::decimal($a), self::decimal($b), 3);
    }

    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::decimal($a), self::decimal($b), 3);
    }

    /** -1 / 0 / +1 at the storage precision. */
    public static function compare(string $a, string $b): int
    {
        return bccomp(self::decimal($a), self::decimal($b), 3);
    }

    public static function isZero(string $quantity): bool
    {
        return bccomp(self::decimal($quantity), '0', 3) === 0;
    }

    /**
     * Validates a quantity literal for the bc functions.
     *
     * @return numeric-string
     */
    private static function decimal(string $quantity): string
    {
        $trimmed = trim($quantity);

        if (! is_numeric($trimmed)) {
            throw new InvalidArgumentException("Quantity '{$quantity}' is not numeric.");
        }

        return $trimmed;
    }

    /**
     * round_half_up(numeratorMillis x value / denominatorMillis) in exact
     * integer arithmetic. numeratorMillis x value can exceed 2^63 (10^14
     * quantities x 10^3 millis x large FCFA values), so the product runs
     * through bcmath.
     */
    private static function roundHalfUpRatio(int $numeratorMillis, int $value, int $denominatorMillis): int
    {
        if ($denominatorMillis <= 0) {
            throw new InvalidArgumentException('The denominator quantity must be strictly positive.');
        }

        $product = bcmul((string) $numeratorMillis, (string) $value, 0);
        $quotient = bcdiv($product, (string) $denominatorMillis, 0); // truncates
        $remainder = bcmod($product, (string) $denominatorMillis, 0);

        // half-up: bump when 2 x remainder >= denominator.
        if (bccomp(bcmul($remainder, '2', 0), (string) $denominatorMillis, 0) >= 0) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return (int) $quotient;
    }

    /** DECIMAL(14,3) -> integer millis, exactly. */
    private static function millis(string $quantity): int
    {
        $trimmed = trim($quantity);

        if (preg_match('/^-?\d{1,14}(\.\d{1,3})?$/', $trimmed) !== 1 || ! is_numeric($trimmed)) {
            throw new InvalidArgumentException(
                "Quantity '{$quantity}' is not a DECIMAL(14,3) literal."
            );
        }

        return (int) bcmul($trimmed, '1000', 0);
    }
}
