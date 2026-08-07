<?php

declare(strict_types=1);

namespace App\Support\Money;

use RuntimeException;

final class MoneyException extends RuntimeException
{
    public static function overflow(): self
    {
        return new self('Money arithmetic overflow: result exceeds BIGINT SIGNED range.');
    }

    public static function emptyRatios(): self
    {
        return new self('allocate() requires at least one ratio.');
    }

    public static function negativeRatio(): self
    {
        return new self('allocate() ratios must be non-negative.');
    }

    public static function zeroRatioSum(): self
    {
        return new self('allocate() ratios must sum to more than zero.');
    }
}
