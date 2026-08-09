<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Support\Expression\BinaryNode;
use App\Support\Expression\CallNode;
use App\Support\Expression\Expression;
use App\Support\Expression\ExpressionException;
use App\Support\Expression\Node;
use App\Support\Expression\UnaryNode;

/**
 * The Payroll face of the shared expression kernel (docs/specs/05-hr-payroll.md
 * 5.4, fixing H4): parse at SAVE time against the fixed variable set, walk
 * the AST to reject everything the payroll grammar does not admit - the
 * kernel also serves posting-rule CONDITIONS, so it accepts comparisons,
 * and/or/not and abs(), none of which is a payroll formula.
 *
 * What survives: + - * / min(,) max(,) parentheses, unary minus, integer
 * literals, and the whitelisted variables (plus known component codes).
 * Nothing else parses; nothing is ever eval()'d.
 */
final readonly class PayrollFormula
{
    /**
     * The fixed 5.4 variable set. `total_employee_deductions` is the
     * engine-materialised aggregate every PayrollItem carries (10.2); it is
     * what lets the shipped NET formula state the 7.2 rule 4 invariant
     * - gross minus employee deductions, exactly - without enumerating a
     * dynamic set of deduction codes.
     */
    public const VARIABLES = [
        'basic',
        'gross',
        'sbt',
        'taxable',
        'cnps_capped',
        'cnps_uncapped',
        'irpp_amount',
        'days_worked',
        'days_in_period',
        'hours_taught',
        'ytd_sbt',
        'ytd_irpp_withheld',
        'total_employee_deductions',
    ];

    private function __construct(
        public Expression $expression,
        public string $source,
    ) {
    }

    /**
     * Parse-at-save. An unknown identifier, a comparison, a boolean
     * operator, abs(), a string, a call to anything else - each rejects the
     * save with the offending token position (ExpressionException).
     *
     * @param  list<string>  $componentCodes  component codes referenceable as variables (5.4 `<component_code>`)
     */
    public static function parse(string $source, array $componentCodes = []): self
    {
        $allowed = array_values(array_unique([...self::VARIABLES, ...$componentCodes]));

        $expression = Expression::parse($source, $allowed);

        self::assertArithmetic($expression->ast);

        return new self($expression, $source);
    }

    /**
     * Evaluate to integer FCFA. Division by a runtime zero surfaces as
     * ExpressionException and fails the run - never a silent zero.
     *
     * @param  array<string, int>  $variables
     */
    public function evaluate(array $variables): int
    {
        return $this->expression->value($variables);
    }

    /**
     * The variables this formula actually references - what the settings
     * screen's dry-run preview resolves and displays.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return $this->expression->variables;
    }

    /**
     * 5.4 admits ONLY arithmetic: no function calls beyond min/max, no
     * comparisons, no boolean algebra. The shared kernel parses those for
     * posting-rule conditions, so the payroll wrapper walks the AST and
     * rejects them by name.
     */
    private static function assertArithmetic(Node $node): void
    {
        if ($node instanceof UnaryNode) {
            if ($node->op === 'not') {
                throw ExpressionException::typeMismatch(
                    "a payroll formula is arithmetic only; 'not' is not part of the 5.4 grammar"
                );
            }

            self::assertArithmetic($node->operand);

            return;
        }

        if ($node instanceof CallNode) {
            if (! in_array($node->name, ['min', 'max'], true)) {
                throw ExpressionException::typeMismatch(
                    "a payroll formula may call only min and max; '{$node->name}' is not part of the 5.4 grammar"
                );
            }

            foreach ($node->arguments as $argument) {
                self::assertArithmetic($argument);
            }

            return;
        }

        if ($node instanceof BinaryNode) {
            if (! in_array($node->op, ['+', '-', '*', '/'], true)) {
                throw ExpressionException::typeMismatch(
                    "a payroll formula is arithmetic only; '{$node->op}' is not part of the 5.4 grammar"
                );
            }

            self::assertArithmetic($node->left);
            self::assertArithmetic($node->right);
        }
    }
}
