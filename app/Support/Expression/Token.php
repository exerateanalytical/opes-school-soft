<?php

declare(strict_types=1);

namespace App\Support\Expression;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $position,
    ) {
    }

    public function describe(): string
    {
        return match ($this->type) {
            TokenType::Number => "number '{$this->value}'",
            TokenType::Identifier => "identifier '{$this->value}'",
            default => $this->type->value,
        };
    }
}
