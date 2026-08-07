<?php

declare(strict_types=1);

namespace App\Support\Rate;

use RuntimeException;

final class RateException extends RuntimeException
{
    public static function negative(): self
    {
        return new self('A Rate cannot be negative.');
    }

    public static function tooPrecise(string $percent): self
    {
        return new self(
            "Rate percentage \"{$percent}\" has more than two decimal places; "
            .'basis points carry exactly two.',
        );
    }

    public static function malformed(string $percent): self
    {
        return new self("Rate percentage \"{$percent}\" is not a valid decimal number.");
    }

    public static function overflow(): self
    {
        return new self('Rate application overflow: amount times rate exceeds BIGINT SIGNED range.');
    }
}
