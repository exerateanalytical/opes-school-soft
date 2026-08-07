<?php

declare(strict_types=1);

namespace App\Support\Money;

use JsonSerializable;
use Stringable;

/**
 * Whole FCFA (XAF). Persisted as BIGINT SIGNED.
 *
 * XAF has no subunit in practice, so the stored integer IS the franc - there
 * is no minor-unit scaling. Signed because fee adjustments, disposal gains and
 * losses, cash-desk variances and credit balances are all legitimately
 * negative (docs/specs/00-core.md 7.1).
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(private int $amount)
    {
    }

    public static function of(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * @param  iterable<Money>  $items
     */
    public static function sum(iterable $items): self
    {
        $total = self::zero();

        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function plus(self $other): self
    {
        return new self(self::guard($this->amount + $other->amount));
    }

    public function minus(self $other): self
    {
        return new self(self::guard($this->amount - $other->amount));
    }

    public function times(int $factor): self
    {
        return new self(self::guard($this->amount * $factor));
    }

    /**
     * Guarded like the arithmetic methods: at PHP_INT_MIN both -$n and abs($n)
     * promote to float, and an unguarded promotion would surface as a raw
     * TypeError instead of the MoneyException this class contracts to throw.
     * Unreachable at FCFA scale, but the contract should not have a hole in it.
     */
    public function negated(): self
    {
        return new self(self::guard(-$this->amount));
    }

    public function absolute(): self
    {
        return new self(self::guard(abs($this->amount)));
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->amount > $other->amount;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->amount >= $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        return $this->amount < $other->amount;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->amount <= $other->amount;
    }

    /**
     * Split this amount across the given ratios with no franc lost or created.
     *
     * @param  list<int>  $ratios
     * @return list<Money>
     */
    public function allocate(array $ratios): array
    {
        return Allocator::allocate($this, $ratios);
    }

    /**
     * Split into N equal parts, the earliest parts absorbing the remainder.
     *
     * @return list<Money>
     */
    public function split(int $parts): array
    {
        return $this->allocate(array_fill(0, $parts, 1));
    }

    /**
     * Thin space (U+202F) as the group separator - the francophone convention
     * and what the printed documents use.
     */
    public function format(bool $withCurrency = true): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        // Grouped from the integer directly - no (float) cast, which the
        // numeric-policy architecture test forbids in money code. Chunked from
        // the right by reversing the DIGITS only; reversing the joined string
        // would mangle the multibyte U+202F separator.
        $digits = (string) abs($this->amount);
        $chunks = array_reverse(array_map(strrev(...), str_split(strrev($digits), 3)));
        $grouped = implode("\u{202F}", $chunks);

        return $sign.$grouped.($withCurrency ? ' FCFA' : '');
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    public function jsonSerialize(): int
    {
        return $this->amount;
    }

    /**
     * PHP silently promotes integer overflow to float. Catching that here is
     * what stops a corrupted amount reaching a BIGINT column.
     */
    private static function guard(int|float $result): int
    {
        if (is_float($result)) {
            throw MoneyException::overflow();
        }

        return $result;
    }
}
