<?php

declare(strict_types=1);

namespace App\Support\Expression;

final readonly class BinaryNode implements Node
{
    /**
     * @param  '+'|'-'|'*'|'/'|'<'|'<='|'>'|'>='|'=='|'!='|'and'|'or'  $op
     */
    public function __construct(public string $op, public Node $left, public Node $right)
    {
    }
}
