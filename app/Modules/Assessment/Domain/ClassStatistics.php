<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Rate\Rate;
use App\Support\Score\Score;

/**
 * The statistics block of docs/specs/01-assessment.md §10.7, computed over
 * RANKED, NON-NULL students only.
 *
 * It lives in the Domain because a class mean is an average, and T23 forbids an
 * average being computed anywhere else. Everything reads the ROUNDED values, so
 * the figures agree with the numbers printed on the cards they summarise.
 *
 * `stdev` is the POPULATION standard deviation, divisor `n`, and the API field
 * says so. v1 said "stdev" and half a team would have implemented the sample
 * form; at n = 62 the two differ by about 0.8 %, which is enough to make two
 * schools' published figures irreconcilable.
 */
final readonly class ClassStatistics
{
    /**
     * @param  Score|null  $median  lower median for even n, stated (§10.7)
     */
    public function __construct(
        public int $n,
        public ?Score $mean,
        public ?Score $min,
        public ?Score $max,
        public ?Score $median,
        public ?Score $stdevPopulation,
        public int $passCount,
        public ?Rate $passRate,
    ) {}

    /**
     * @param  list<Score|null>  $averages  NULL entries are excluded from every figure below
     */
    public static function of(
        array $averages,
        Score $passScore,
        int $precision = Rounding::DEFAULT_PRECISION,
    ): self {
        $values = [];

        foreach ($averages as $average) {
            if ($average !== null) {
                $values[] = Rounding::halfUp($average, $precision)->thousandths();
            }
        }

        $n = count($values);

        if ($n === 0) {
            return new self(0, null, null, null, null, null, 0, null);
        }

        sort($values);

        $sum = 0;
        $sumOfSquares = 0;

        foreach ($values as $value) {
            $sum = Arithmetic::add($sum, $value);
            $sumOfSquares = Arithmetic::add($sumOfSquares, Arithmetic::multiply($value, $value));
        }

        return new self(
            $n,
            Score::ofThousandths(Arithmetic::divideHalfUp($sum, $n)),
            Score::ofThousandths($values[0]),
            Score::ofThousandths($values[$n - 1]),
            Score::ofThousandths($values[intdiv($n - 1, 2)]),
            Score::ofThousandths(self::populationStdev($sum, $sumOfSquares, $n)),
            PassRule::countPassing($averages, $passScore, $precision),
            PassRule::passRate($averages, $passScore, $precision),
        );
    }

    /**
     * σ = √(nΣx² − (Σx)²) / n, all in thousandths.
     *
     * The computational form keeps every intermediate an exact integer — there
     * is no mean to round before subtracting it, so the figure does not drift
     * with the order of the input. The ×100 scaling recovers two extra digits
     * before the final half-up division, so the result is correct to the
     * thousandth the column stores.
     */
    private static function populationStdev(int $sum, int $sumOfSquares, int $n): int
    {
        if ($n === 1) {
            return 0;
        }

        $variance = Arithmetic::add(
            Arithmetic::multiply($n, $sumOfSquares),
            -Arithmetic::multiply($sum, $sum),
        );

        if ($variance <= 0) {
            return 0;
        }

        $scaledRoot = Arithmetic::integerSquareRoot(Arithmetic::multiply($variance, 100));

        return Arithmetic::divideHalfUp($scaledRoot, Arithmetic::multiply($n, 10));
    }
}
