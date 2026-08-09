<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md §10.4 — how a criterion's observed value meets
 * its threshold. Comparison happens on strings via bccomp-free integer
 * milli-units so 13.333 vs 13.333 is equality, not float noise.
 */
enum Comparator: string
{
    case Gte = 'gte';
    case Gt = 'gt';
    case Lte = 'lte';
    case Lt = 'lt';
    case Eq = 'eq';

    /**
     * Both sides are decimal strings (e.g. "13.500"); non-numeric noise
     * milli-parses to its numeric prefix.
     */
    public function compare(string $value, string $threshold): bool
    {
        $left = self::milli($value);
        $right = self::milli($threshold);

        return match ($this) {
            self::Gte => $left >= $right,
            self::Gt => $left > $right,
            self::Lte => $left <= $right,
            self::Lt => $left < $right,
            self::Eq => $left === $right,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Gte => '≥',
            self::Gt => '>',
            self::Lte => '≤',
            self::Lt => '<',
            self::Eq => '=',
        };
    }

    /**
     * Decimal string → integer thousandths, no float in the middle.
     */
    private static function milli(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $bare = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $bare, 2), 2, '');

        $fraction = substr(str_pad($fraction, 3, '0'), 0, 3);

        $milli = ((int) $whole) * 1000 + (int) $fraction;

        return $negative ? -$milli : $milli;
    }
}
