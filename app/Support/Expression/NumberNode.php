<?php

declare(strict_types=1);

namespace App\Support\Expression;

final readonly class NumberNode implements Node
{
    public function __construct(public int $value)
    {
    }
}
