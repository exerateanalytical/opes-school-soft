<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Largest-remainder allocation.
 *
 * Floor each share, then hand the leftover francs one at a time to the shares
 * with the largest fractional remainders. Ties break on the earlier index, so
 * the result is deterministic and an instalment plan always reproduces.
 *
 * Guarantee: array_sum(allocate($m, $r)) === $m->amount(), always.
 */
final class Allocator
{
    /**
     * @param  list<int>  $ratios
     * @return list<Money>
     */
    public static function allocate(Money $money, array $ratios): array
    {
        if ($ratios === []) {
            throw MoneyException::emptyRatios();
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw MoneyException::negativeRatio();
            }
        }

        $ratioTotal = array_sum($ratios);

        if ($ratioTotal === 0) {
            throw MoneyException::zeroRatioSum();
        }

        // Work on the magnitude so intdiv() floors consistently; PHP's intdiv
        // truncates toward zero, which would round negatives the wrong way.
        $sign = $money->amount() < 0 ? -1 : 1;
        $absolute = abs($money->amount());

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($ratios as $index => $ratio) {
            $numerator = $absolute * $ratio;
            $share = intdiv($numerator, $ratioTotal);

            $shares[$index] = $share;
            $remainders[$index] = $numerator % $ratioTotal;
            $allocated += $share;
        }

        $leftover = $absolute - $allocated;

        // Largest remainder first; earlier index wins a tie.
        $order = array_keys($remainders);
        usort(
            $order,
            static fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b),
        );

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i]]++;
        }

        ksort($shares);

        return array_values(array_map(
            static fn (int $share): Money => Money::of($sign * $share),
            $shares,
        ));
    }
}
