<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

use InvalidArgumentException;

/**
 * docs/specs/03-tax-procurement.md §4.2 invariant 1:
 *
 *   amount_ht = round_half_up(quantity x unit_price_ht x (1 - discount/10000))
 *
 * rounded ONCE to whole FCFA per line - headers only ever sum lines
 * (00-core §7.3, never independently rounded).
 *
 * All arithmetic is integer: DECIMAL(12,3) quantities become millis
 * (x1000), so the product quantity x price x (10000 - discount) and its
 * single half-up division by 10^7 are exact 64-bit operations - no float
 * enters at any point.
 */
final class LineAmount
{
    private const DENOMINATOR = 10_000_000; // 1000 (millis) x 10000 (bp)

    private function __construct() {}

    /**
     * @param  numeric-string|string  $quantity  a DECIMAL(12,3) literal, e.g. "40" or "12.500"
     */
    public static function compute(string $quantity, int $unitPriceHt, int $discountRateBp = 0): int
    {
        if ($unitPriceHt < 0) {
            throw new InvalidArgumentException('A unit price cannot be negative.');
        }

        if ($discountRateBp < 0 || $discountRateBp > 10_000) {
            throw new InvalidArgumentException('A discount must lie between 0 and 10000 basis points.');
        }

        $millis = self::toMillis($quantity);

        $numerator = $millis * $unitPriceHt * (10_000 - $discountRateBp);

        return intdiv($numerator + intdiv(self::DENOMINATOR, 2), self::DENOMINATOR);
    }

    /**
     * Exact integer millis from a decimal-string quantity - never through a
     * float, whose base-2 representation of "0.145" would round wrong.
     */
    public static function toMillis(string $quantity): int
    {
        $trimmed = trim($quantity);

        if ($trimmed === '' || preg_match('/^\d{1,12}(\.\d{1,3})?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Quantity [%s] is not a positive DECIMAL(12,3) literal.',
                $quantity,
            ));
        }

        [$whole, $fraction] = array_pad(explode('.', $trimmed, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        return ((int) $whole) * 1000 + (int) $fraction;
    }
}
