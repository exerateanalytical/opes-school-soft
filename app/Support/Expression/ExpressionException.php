<?php

declare(strict_types=1);

namespace App\Support\Expression;

use RuntimeException;

/**
 * Every failure mode of the whitelisted expression grammar
 * (docs/specs/02-accounting.md §11.1, docs/specs/05-hr-payroll.md §5.4).
 *
 * Each constructor carries a legible, position-bearing message because a
 * parse failure "rejects the save with the offending token position" - the
 * message IS the UI surface.
 */
final class ExpressionException extends RuntimeException
{
    public static function unexpectedCharacter(string $char, int $position): self
    {
        return new self(sprintf(
            "Unexpected character '%s' at position %d; only numbers, variables, + - * /, min/max/abs, parentheses and comparison operators are allowed.",
            $char,
            $position,
        ));
    }

    public static function unexpectedToken(string $found, int $position, string $expected): self
    {
        return new self(sprintf(
            "Unexpected %s at position %d; expected %s.",
            $found,
            $position,
            $expected,
        ));
    }

    public static function stringLiteral(int $position): self
    {
        return new self(sprintf(
            'String literals are not allowed in expressions (position %d).',
            $position,
        ));
    }

    public static function functionNotAllowed(string $name, int $position): self
    {
        return new self(sprintf(
            "Function '%s' is not allowed at position %d; only min, max and abs exist.",
            $name,
            $position,
        ));
    }

    public static function wrongArity(string $name, int $expected, int $got, int $position): self
    {
        return new self(sprintf(
            "Function '%s' takes exactly %d argument%s, got %d (position %d).",
            $name,
            $expected,
            $expected === 1 ? '' : 's',
            $got,
            $position,
        ));
    }

    public static function unknownVariable(string $name, int $position): self
    {
        return new self(sprintf(
            "Unknown variable '%s' at position %d; it is not part of the declared payload schema.",
            $name,
            $position,
        ));
    }

    public static function divisionByLiteralZero(int $position): self
    {
        return new self(sprintf(
            'Division by the literal zero at position %d is rejected at parse time.',
            $position,
        ));
    }

    public static function divisionByZero(): self
    {
        return new self('Division by zero while evaluating the expression.');
    }

    public static function overflow(): self
    {
        return new self('Integer overflow while evaluating the expression.');
    }

    public static function tooDeep(int $limit): self
    {
        return new self(sprintf(
            'Expression nests deeper than the permitted %d levels.',
            $limit,
        ));
    }

    public static function emptyExpression(): self
    {
        return new self('The expression is empty.');
    }

    public static function missingValue(string $name): self
    {
        return new self(sprintf(
            "No value supplied for variable '%s' at evaluation time.",
            $name,
        ));
    }

    public static function notAnInteger(string $name): self
    {
        return new self(sprintf(
            "Variable '%s' did not resolve to an integer.",
            $name,
        ));
    }

    public static function typeMismatch(string $detail): self
    {
        return new self(sprintf('Type error in expression: %s.', $detail));
    }
}
