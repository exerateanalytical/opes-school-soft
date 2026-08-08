<?php

declare(strict_types=1);

namespace App\Support\Expression;

/**
 * The public face of the shared expression kernel: parse at SAVE time
 * against a declared variable whitelist, evaluate at RUN time against a
 * value map. One grammar, one parser, one test suite - serving both
 * posting-rule expressions (02-accounting.md §11.1) and payroll formulas
 * (05-hr-payroll.md §5.4).
 */
final readonly class Expression
{
    /**
     * @param  list<string>  $variables  every variable the AST references
     */
    private function __construct(
        public Node $ast,
        public array $variables,
    ) {
    }

    /**
     * Parses and, when a whitelist is given, validates every referenced
     * variable against it. An unknown variable is a save-time error
     * carrying the variable name and its position.
     *
     * @param  list<string>|null  $allowedVariables  null = accept any well-formed variable name
     */
    public static function parse(string $source, ?array $allowedVariables = null): self
    {
        $ast = Parser::parse($source);
        $seen = [];
        self::collectVariables($ast, $seen);

        if ($allowedVariables !== null) {
            foreach ($seen as $name => $position) {
                if (! in_array($name, $allowedVariables, true)) {
                    throw ExpressionException::unknownVariable($name, $position);
                }
            }
        }

        return new self($ast, array_keys($seen));
    }

    /**
     * Evaluates to an integer amount. A boolean result is a type error -
     * an amount_expression must produce francs.
     *
     * @param  array<string, int|bool>  $variables
     */
    public function value(array $variables): int
    {
        $result = Evaluator::evaluate($this->ast, $variables);

        if (! is_int($result)) {
            throw ExpressionException::typeMismatch('an amount expression must produce an integer, this one produced a boolean');
        }

        return $result;
    }

    /**
     * Evaluates to a boolean condition. An integer result is a type error -
     * a condition must actually compare something.
     *
     * @param  array<string, int|bool>  $variables
     */
    public function truth(array $variables): bool
    {
        $result = Evaluator::evaluate($this->ast, $variables);

        if (! is_bool($result)) {
            throw ExpressionException::typeMismatch('a condition expression must produce a boolean, this one produced an integer');
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $seen  variable name => first position
     */
    private static function collectVariables(Node $node, array &$seen): void
    {
        if ($node instanceof VariableNode) {
            if (! array_key_exists($node->name, $seen)) {
                $seen[$node->name] = $node->position;
            }

            return;
        }

        if ($node instanceof UnaryNode) {
            self::collectVariables($node->operand, $seen);

            return;
        }

        if ($node instanceof BinaryNode) {
            self::collectVariables($node->left, $seen);
            self::collectVariables($node->right, $seen);

            return;
        }

        if ($node instanceof CallNode) {
            foreach ($node->arguments as $argument) {
                self::collectVariables($argument, $seen);
            }
        }
    }
}
