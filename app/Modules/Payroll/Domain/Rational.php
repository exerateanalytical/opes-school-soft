<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use InvalidArgumentException;

/**
 * Exact rational arithmetic for the bracket engine (docs/specs/05-hr-payroll.md
 * 6.3): steps 1-6 of the IRPP path are computed exactly, and the ONLY
 * rounding in the whole chain is the single half-up at step 7. Floats are
 * banned for the same reason they are banned for Money - a payslip must
 * reproduce to the franc from its snapshot forever.
 *
 * THIS FILE IS THE BRACKET-ARITHMETIC HELPER 4.3 EXEMPTS: it is the only
 * file under app/Modules/Payroll/Domain permitted to contain numeric
 * literals (they are the algebraic identities 0, 1, 2 - not rates), and the
 * architecture test in tests/Architecture/PayrollDomainPolicyTest.php
 * enforces exactly that boundary.
 */
final readonly class Rational
{
    private function __construct(
        public int $numerator,
        public int $denominator,
    ) {
    }

    public static function ofInt(int $value): self
    {
        return new self($value, 1);
    }

    public static function of(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('A rational cannot have a zero denominator.');
        }

        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        $gcd = self::gcd(abs($numerator), $denominator);

        return new self(intdiv($numerator, $gcd), intdiv($denominator, $gcd));
    }

    public static function zero(): self
    {
        return new self(0, 1);
    }

    /**
     * Parse a non-negative decimal string (MySQL DECIMAL columns arrive as
     * strings - "21.50" - and floats are banned) into an exact rational.
     */
    public static function fromDecimalString(string $value): self
    {
        $trimmed = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $trimmed, $m) !== 1) {
            throw new InvalidArgumentException("Not a non-negative decimal string: '{$value}'.");
        }

        $fraction = $m[2] ?? '';
        $denominator = (int) str_pad('1', strlen($fraction) + 1, '0');

        return self::of((int) ($m[1].$fraction), $denominator);
    }

    public function plus(self $other): self
    {
        return self::of(
            $this->numerator * $other->denominator + $other->numerator * $this->denominator,
            $this->denominator * $other->denominator,
        );
    }

    public function minus(self $other): self
    {
        return self::of(
            $this->numerator * $other->denominator - $other->numerator * $this->denominator,
            $this->denominator * $other->denominator,
        );
    }

    public function times(self $other): self
    {
        return self::of(
            $this->numerator * $other->numerator,
            $this->denominator * $other->denominator,
        );
    }

    public function dividedBy(self $other): self
    {
        if ($other->numerator === 0) {
            throw new InvalidArgumentException('Division of a rational by zero.');
        }

        return self::of(
            $this->numerator * $other->denominator,
            $this->denominator * $other->numerator,
        );
    }

    public function min(self $other): self
    {
        return $this->isLessThan($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->isLessThan($other) ? $other : $this;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isNegative(): bool
    {
        return $this->numerator < 0;
    }

    public function isZero(): bool
    {
        return $this->numerator === 0;
    }

    public function compare(self $other): int
    {
        return ($this->numerator * $other->denominator) <=> ($other->numerator * $this->denominator);
    }

    /**
     * The single rounding of the chain: half-up, away from zero, to a whole
     * franc (00-core 7.3 "round once, at component level").
     */
    public function roundHalfUp(): int
    {
        $sign = $this->numerator < 0 ? -1 : 1;
        $abs = abs($this->numerator);

        $quotient = intdiv($abs, $this->denominator);
        $remainder = $abs % $this->denominator;

        if ($remainder * 2 >= $this->denominator) {
            $quotient += 1;
        }

        return $sign * $quotient;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }
}
