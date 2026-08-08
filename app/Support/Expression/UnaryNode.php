<?php

declare(strict_types=1);

namespace App\Support\Expression;

final readonly class UnaryNode implements Node
{
    /**
     * @param  'neg'|'not'  $op
     */
    public function __construct(public string $op, public Node $operand)
    {
    }
}
