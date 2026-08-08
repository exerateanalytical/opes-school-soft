<?php

declare(strict_types=1);

namespace App\Support\Expression;

final readonly class VariableNode implements Node
{
    public function __construct(public string $name, public int $position)
    {
    }
}
