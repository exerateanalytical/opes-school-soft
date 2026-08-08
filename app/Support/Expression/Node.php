<?php

declare(strict_types=1);

namespace App\Support\Expression;

/**
 * Closed AST for the whitelisted grammar. Five node shapes and nothing else -
 * there is no node for a function call outside min/max/abs, no node for
 * property access, no node for assignment, because the parser cannot build
 * one. The evaluator therefore cannot execute one.
 */
interface Node
{
}
