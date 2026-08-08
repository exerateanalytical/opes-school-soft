<?php

declare(strict_types=1);

namespace App\Support\Expression;

use App\Support\Money\Money;
use App\Support\Money\MoneyException;

/**
 * Walks the closed AST. All arithmetic passes through Money's overflow
 * guards (docs/specs/02-accounting.md §11.1: "integer arithmetic through
 * Money"); division is integer division truncating toward zero, with the
 * runtime-zero and PHP_INT_MIN/-1 edge cases surfaced as clear errors.
 */
final class Evaluator
{
    /**
     * @param  array<string, int|bool>  $variables
     */
    public static function evaluate(Node $node, array $variables): int|bool
    {
        try {
            return self::walk($node, $variables);
        } catch (MoneyException) {
            throw ExpressionException::overflow();
        }
    }

    /**
     * @param  array<string, int|bool>  $variables
     */
    private static function walk(Node $node, array $variables): int|bool
    {
        if ($node instanceof NumberNode) {
            return $node->value;
        }

        if ($node instanceof VariableNode) {
            if (! array_key_exists($node->name, $variables)) {
                throw ExpressionException::missingValue($node->name);
            }

            return $variables[$node->name];
        }

        if ($node instanceof UnaryNode) {
            $value = self::walk($node->operand, $variables);

            if ($node->op === 'neg') {
                return Money::of(self::int($value, 'unary minus'))->negated()->amount();
            }

            return ! self::bool($value, "'not'");
        }

        if ($node instanceof CallNode) {
            $arguments = array_map(
                fn (Node $argument): int => self::int(self::walk($argument, $variables), $node->name),
                $node->arguments,
            );

            return match ($node->name) {
                'min' => min($arguments[0], $arguments[1]),
                'max' => max($arguments[0], $arguments[1]),
                'abs' => Money::of($arguments[0])->absolute()->amount(),
            };
        }

        /** @var BinaryNode $node */
        if ($node->op === 'and') {
            return self::bool(self::walk($node->left, $variables), "'and'")
                && self::bool(self::walk($node->right, $variables), "'and'");
        }

        if ($node->op === 'or') {
            return self::bool(self::walk($node->left, $variables), "'or'")
                || self::bool(self::walk($node->right, $variables), "'or'");
        }

        $left = self::int(self::walk($node->left, $variables), "'{$node->op}'");
        $right = self::int(self::walk($node->right, $variables), "'{$node->op}'");

        return match ($node->op) {
            '+' => Money::of($left)->plus(Money::of($right))->amount(),
            '-' => Money::of($left)->minus(Money::of($right))->amount(),
            '*' => Money::of($left)->times($right)->amount(),
            '/' => self::divide($left, $right),
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '==' => $left === $right,
            '!=' => $left !== $right,
        };
    }

    private static function divide(int $left, int $right): int
    {
        if ($right === 0) {
            throw ExpressionException::divisionByZero();
        }

        if ($left === PHP_INT_MIN && $right === -1) {
            throw ExpressionException::overflow();
        }

        return intdiv($left, $right);
    }

    private static function int(int|bool $value, string $context): int
    {
        if (! is_int($value)) {
            throw ExpressionException::typeMismatch("{$context} requires integer operands, got a boolean");
        }

        return $value;
    }

    private static function bool(int|bool $value, string $context): bool
    {
        if (! is_bool($value)) {
            throw ExpressionException::typeMismatch("{$context} requires boolean operands, got an integer");
        }

        return $value;
    }
}
