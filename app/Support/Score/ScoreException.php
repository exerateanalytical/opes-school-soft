<?php

declare(strict_types=1);

namespace App\Support\Score;

use RuntimeException;

final class ScoreException extends RuntimeException
{
    public static function negative(): self
    {
        return new self('A Score cannot be negative.');
    }

    public static function tooPrecise(string $value): self
    {
        return new self(
            "Score \"{$value}\" has more than three decimal places; "
            .'scores are stored as DECIMAL(6,3).',
        );
    }

    public static function malformed(string $value): self
    {
        return new self("Score \"{$value}\" is not a valid decimal number.");
    }

    public static function divisionByZero(): self
    {
        return new self('Cannot divide a Score by zero.');
    }
}
