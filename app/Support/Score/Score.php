<?php

declare(strict_types=1);

namespace App\Support\Score;

use JsonSerializable;
use Stringable;

/**
 * A mark or average, held as integer thousandths - DECIMAL(6,3) in MySQL.
 *
 * Computed in integers throughout, rounded half up to two decimal places once
 * at the end of aggregation. Rank and grade band are then derived from the
 * ROUNDED value, so the number printed on the bulletin always explains the
 * rank beside it (docs/specs/00-core.md 7.4, 01-assessment.md 2).
 */
final readonly class Score implements JsonSerializable, Stringable
{
    public const SCALE = 1_000;

    private function __construct(private int $thousandths)
    {
    }

    public static function ofThousandths(int $thousandths): self
    {
        if ($thousandths < 0) {
            throw ScoreException::negative();
        }

        return new self($thousandths);
    }

    public static function of(string $value): self
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '-')) {
            throw ScoreException::negative();
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $trimmed, $m) !== 1) {
            throw ScoreException::malformed($value);
        }

        $fraction = $m[2] ?? '';

        if (strlen($fraction) > 3) {
            throw ScoreException::tooPrecise($value);
        }

        $fraction = str_pad($fraction, 3, '0');

        return new self((int) $m[1] * self::SCALE + (int) $fraction);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Coefficient-weighted average. Returns null when nothing is assessable,
     * which the caller MUST treat as "not assessed" and never as zero.
     *
     * @param  list<array{0: Score, 1: int}>  $weighted  [score, coefficient] pairs
     */
    public static function weightedAverage(array $weighted): ?self
    {
        $numerator = 0;
        $denominator = 0;

        foreach ($weighted as [$score, $coefficient]) {
            $numerator += $score->thousandths * $coefficient;
            $denominator += $coefficient;
        }

        if ($denominator === 0) {
            return null;
        }

        return new self(self::divideHalfUp($numerator, $denominator));
    }

    public function thousandths(): int
    {
        return $this->thousandths;
    }

    public function plus(self $other): self
    {
        return new self($this->thousandths + $other->thousandths);
    }

    public function times(int $factor): self
    {
        return new self($this->thousandths * $factor);
    }

    public function dividedBy(int $divisor): self
    {
        if ($divisor === 0) {
            throw ScoreException::divisionByZero();
        }

        return new self(self::divideHalfUp($this->thousandths, $divisor));
    }

    /**
     * Round half up to two decimal places. Apply exactly once, at the end of
     * aggregation - never in an intermediate step.
     */
    public function roundedToDisplay(): self
    {
        return new self(self::divideHalfUp($this->thousandths, 10) * 10);
    }

    public function equals(self $other): bool
    {
        return $this->thousandths === $other->thousandths;
    }

    /** Compare on the rounded value, per 00-core 7.4. */
    public function equalsForRanking(self $other): bool
    {
        return $this->roundedToDisplay()->thousandths === $other->roundedToDisplay()->thousandths;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->thousandths > $other->thousandths;
    }

    public function isGreaterThanForRanking(self $other): bool
    {
        return $this->roundedToDisplay()->thousandths > $other->roundedToDisplay()->thousandths;
    }

    public function isLessThan(self $other): bool
    {
        return $this->thousandths < $other->thousandths;
    }

    /** Full stored precision, e.g. "13.333". */
    public function toString(): string
    {
        return sprintf(
            '%d.%03d',
            intdiv($this->thousandths, self::SCALE),
            $this->thousandths % self::SCALE,
        );
    }

    /** Two decimal places, as printed on a bulletin, e.g. "13.33". */
    public function toDisplayString(): string
    {
        $rounded = self::divideHalfUp($this->thousandths, 10);

        return sprintf('%d.%02d', intdiv($rounded, 100), $rounded % 100);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    private static function divideHalfUp(int $numerator, int $divisor): int
    {
        return intdiv($numerator + intdiv($divisor, 2), $divisor);
    }
}
