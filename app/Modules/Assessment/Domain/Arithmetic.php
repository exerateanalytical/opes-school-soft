<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * Integer helpers for the pipeline. No float anywhere: a report card recomputed
 * in 2029 must reproduce the 2026 bulletin bit for bit (00-core §7.1).
 *
 * Every multiplication is bounds-checked BEFORE it happens rather than after,
 * because PHP promotes integer overflow to float silently and a float that has
 * lost its last digits still looks like a perfectly good mark.
 *
 * @internal to Assessment\Domain
 */
final class Arithmetic
{
    /**
     * Half-up division for NON-NEGATIVE numerators and POSITIVE divisors, which
     * is all this module ever produces — scores and weights are non-negative by
     * construction and every zero denominator is a NULL case handled earlier.
     */
    public static function divideHalfUp(int $numerator, int $divisor): int
    {
        return intdiv($numerator + intdiv($divisor, 2), $divisor);
    }

    /** Multiply, refusing to silently lose precision to a float promotion. */
    public static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        if (abs($b) > intdiv(PHP_INT_MAX, abs($a))) {
            throw AssessmentException::overflow();
        }

        return $a * $b;
    }

    public static function add(int $a, int $b): int
    {
        if ($b > 0 && $a > PHP_INT_MAX - $b) {
            throw AssessmentException::overflow();
        }

        if ($b < 0 && $a < PHP_INT_MIN - $b) {
            throw AssessmentException::overflow();
        }

        return $a + $b;
    }

    public static function greatestCommonDivisor(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }

    /**
     * Floor integer square root by Newton's method — used only by the
     * population standard deviation of §10.7, and only on non-negative input.
     */
    public static function integerSquareRoot(int $value): int
    {
        if ($value < 2) {
            return max($value, 0);
        }

        $guess = $value;
        $next = intdiv($guess + 1, 2);

        while ($next < $guess) {
            $guess = $next;
            $next = intdiv($guess + intdiv($value, $guess), 2);
        }

        return $guess;
    }
}
