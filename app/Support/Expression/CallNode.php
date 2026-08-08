<?php

declare(strict_types=1);

namespace App\Support\Expression;

final readonly class CallNode implements Node
{
    /**
     * @param  'min'|'max'|'abs'  $name
     * @param  list<Node>  $arguments
     */
    public function __construct(public string $name, public array $arguments)
    {
    }
}
