<?php

declare(strict_types=1);

namespace App\Support\Rate;

use App\Support\Money\Money;
use JsonSerializable;
use Stringable;

/**
 * A percentage held as integer basis points, where 100 000 bp = 100%.
 *
 * Floats are banned here for the same reason they are banned for money: a
 * statutory rate must produce the same franc on every run, forever, so that
 * re-rendering a payslip from a snapshot reproduces exactly
 * (docs/specs/00-core.md 7.2, 05-hr-payroll.md 7).
 */
final readonly class Rate implements JsonSerializable, Stringable
{
    public const SCALE = 100_000;

    private function __construct(private int $basisPoints)
    {
    }

    public static function ofBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw RateException::negative();
        }

        return new self($basisPoints);
    }

    /**
     * Parse a decimal percentage string. Deliberately a string, not a float -
     * (int) round(4.2 * 1000) is exactly the drift this class exists to avoid.
     */
    public static function ofPercent(string $percent): self
    {
        $trimmed = trim($percent);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $trimmed, $m) !== 1) {
            throw RateException::malformed($percent);
        }

        $fraction = $m[2] ?? '';

        if (strlen($fraction) > 2) {
            throw RateException::tooPrecise($percent);
        }

        $fraction = str_pad($fraction, 2, '0');

        return new self((int) $m[1] * 1_000 + (int) $fraction * 10);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    /**
     * Apply to an amount, rounding half up away from zero, exactly once.
     *
     * 00-core 7.3: round once, at component level. Callers must not round again
     * downstream, or the sum of the parts stops equalling the whole.
     */
    public function applyTo(Money $money): Money
    {
        $sign = $money->amount() < 0 ? -1 : 1;
        $absolute = abs($money->amount());

        // Same guard as Money's allocator: check the operands before
        // multiplying, because PHP promotes integer overflow to float at
        // runtime while the static type stays int. Without this the promotion
        // surfaces as a raw TypeError from Money::of() rather than a
        // RateException, leaving a hole in the contract Money deliberately closed.
        if ($this->basisPoints > 0 && $absolute > intdiv(PHP_INT_MAX, $this->basisPoints)) {
            throw RateException::overflow();
        }

        $numerator = $absolute * $this->basisPoints;
        $rounded = intdiv($numerator + intdiv(self::SCALE, 2), self::SCALE);

        return Money::of($sign * $rounded);
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    /**
     * Render as a percentage to two decimal places.
     *
     * intdiv throughout: `/` would return a float for any rate not a multiple
     * of 10 bp, which is exactly the type this class exists to keep out.
     */
    public function toPercentString(): string
    {
        $whole = intdiv($this->basisPoints, 1_000);
        $fraction = intdiv($this->basisPoints % 1_000, 10);

        return sprintf('%d.%02d', $whole, $fraction);
    }

    public function __toString(): string
    {
        return $this->toPercentString().'%';
    }

    public function jsonSerialize(): int
    {
        return $this->basisPoints;
    }
}
