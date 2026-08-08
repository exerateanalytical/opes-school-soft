<?php

declare(strict_types=1);

use App\Support\Expression\Expression;
use App\Support\Expression\ExpressionException;
use App\Support\Expression\LabelTemplate;
use App\Support\Expression\Payload;

// ---------------------------------------------------------------------------
// The happy grammar
// ---------------------------------------------------------------------------

it('evaluates integer arithmetic with precedence', function (): void {
    expect(Expression::parse('2 + 3 * 4')->value([]))->toBe(14)
        ->and(Expression::parse('(2 + 3) * 4')->value([]))->toBe(20)
        ->and(Expression::parse('10 - 2 - 3')->value([]))->toBe(5)
        ->and(Expression::parse('-5 + 2')->value([]))->toBe(-3);
});

it('divides as integer division truncating toward zero', function (): void {
    expect(Expression::parse('7 / 2')->value([]))->toBe(3)
        ->and(Expression::parse('-7 / 2')->value([]))->toBe(-3)
        ->and(Expression::parse('100 * 15 / 1000')->value([]))->toBe(1);
});

it('resolves named variables from the payload map', function (): void {
    $expr = Expression::parse('payment.amount - payment.commission', ['payment.amount', 'payment.commission']);

    expect($expr->value(['payment.amount' => 350_000, 'payment.commission' => 5_250]))->toBe(344_750)
        ->and($expr->variables)->toBe(['payment.amount', 'payment.commission']);
});

it('supports min, max and abs', function (): void {
    expect(Expression::parse('min(3, 7)')->value([]))->toBe(3)
        ->and(Expression::parse('max(3, 7)')->value([]))->toBe(7)
        ->and(Expression::parse('abs(0 - 9)')->value([]))->toBe(9)
        ->and(Expression::parse('max(min(a, b), 10)', ['a', 'b'])->value(['a' => 4, 'b' => 2]))->toBe(10);
});

it('evaluates comparison and boolean operators in conditions', function (): void {
    expect(Expression::parse('a > 100 and b == 5', ['a', 'b'])->truth(['a' => 200, 'b' => 5]))->toBeTrue()
        ->and(Expression::parse('a > 100 && b != 5', ['a', 'b'])->truth(['a' => 200, 'b' => 5]))->toBeFalse()
        ->and(Expression::parse('a < 1 or not (b >= 10)', ['a', 'b'])->truth(['a' => 5, 'b' => 3]))->toBeTrue()
        ->and(Expression::parse('flag and a > 0', ['flag', 'a'])->truth(['flag' => true, 'a' => 1]))->toBeTrue();
});

it('handles deeply nested parentheses within the depth limit', function (): void {
    $depth = 40;
    $source = str_repeat('(', $depth).'1'.str_repeat(')', $depth).' + 1';

    expect(Expression::parse($source)->value([]))->toBe(2);
});

// ---------------------------------------------------------------------------
// The injection surface, tested like an attacker
// ---------------------------------------------------------------------------

it('rejects attempted function calls by name', function (string $source): void {
    Expression::parse($source);
})->with([
    "system('x')",
    'exec(1)',
    'eval(1)',
    'pow(2, 10)',
    'sqrt(4)',
    'payment.amount()',
])->throws(ExpressionException::class);

it('names the forbidden function in the rejection', function (): void {
    expect(fn (): mixed => Expression::parse('system(1)'))
        ->toThrow(ExpressionException::class, "Function 'system' is not allowed");
});

it('rejects property traversal outside the declared schema', function (): void {
    expect(fn (): mixed => Expression::parse('a.b.c', ['a.b']))
        ->toThrow(ExpressionException::class, "Unknown variable 'a.b.c'")
        ->and(fn (): mixed => Expression::parse('payment.amount', []))
        ->toThrow(ExpressionException::class, "Unknown variable 'payment.amount'");
});

it('rejects quotes and injection strings at the lexer', function (string $source): void {
    Expression::parse($source);
})->with([
    "'; DROP TABLE journal_entries; --",
    '"quoted"',
    '`backtick`',
    'a; b',
    '$var',
    'a = 5',
    '1 + {x}',
    'a[0]',
    'a->b',
    '#comment',
    '1 \\ 2',
])->throws(ExpressionException::class);

it('rejects division by a literal zero at parse time', function (): void {
    expect(fn (): mixed => Expression::parse('100 / 0'))
        ->toThrow(ExpressionException::class, 'literal zero');
});

it('raises a clear error on runtime division by zero', function (): void {
    $expr = Expression::parse('a / b', ['a', 'b']);

    expect(fn (): mixed => $expr->value(['a' => 10, 'b' => 0]))
        ->toThrow(ExpressionException::class, 'Division by zero');
});

it('surfaces integer overflow through the Money guards', function (): void {
    $expr = Expression::parse('a * b', ['a', 'b']);

    expect(fn (): mixed => $expr->value(['a' => PHP_INT_MAX, 'b' => 2]))
        ->toThrow(ExpressionException::class, 'overflow')
        ->and(fn (): mixed => Expression::parse('99999999999999999999999999'))
        ->toThrow(ExpressionException::class, 'overflow');
});

it('rejects expressions nested beyond the depth limit', function (): void {
    $source = str_repeat('(', 100).'1'.str_repeat(')', 100);

    expect(fn (): mixed => Expression::parse($source))
        ->toThrow(ExpressionException::class, 'nests deeper');
});

it('rejects malformed input with a legible message', function (string $source): void {
    Expression::parse($source);
})->with([
    '',
    '   ',
    '1 +',
    '+ 1 1',
    '(1 + 2',
    '1 + 2)',
    'min(1)',
    'min(1, 2, 3)',
    'abs(1, 2)',
    'a..b',
    'a.',
    '1.5',
    '12abc',
    'and and',
    '* 3',
])->throws(ExpressionException::class);

it('reports the offending position', function (): void {
    expect(fn (): mixed => Expression::parse('1 + $x'))
        ->toThrow(ExpressionException::class, 'position 4');
});

it('refuses a boolean where an amount is required and vice versa', function (): void {
    expect(fn (): mixed => Expression::parse('1 > 0')->value([]))
        ->toThrow(ExpressionException::class, 'produced a boolean')
        ->and(fn (): mixed => Expression::parse('1 + 1')->truth([]))
        ->toThrow(ExpressionException::class, 'produced an integer')
        ->and(fn (): mixed => Expression::parse('(1 > 0) + 1')->value([]))
        ->toThrow(ExpressionException::class, 'integer operands')
        ->and(fn (): mixed => Expression::parse('1 and 2')->truth([]))
        ->toThrow(ExpressionException::class, 'boolean operands');
});

it('errors when a referenced variable has no value at evaluation', function (): void {
    expect(fn (): mixed => Expression::parse('a + 1', ['a'])->value([]))
        ->toThrow(ExpressionException::class, "No value supplied for variable 'a'");
});

// ---------------------------------------------------------------------------
// Label templates
// ---------------------------------------------------------------------------

it('validates and renders label templates against the schema', function (): void {
    $used = LabelTemplate::validate('Encaissement MoMo réf. {payment.reference}', ['payment.reference']);

    expect($used)->toBe(['payment.reference'])
        ->and(LabelTemplate::render('Réf. {payment.reference} — {n}', [
            'payment.reference' => 'MM-88421',
            'n' => 3,
        ]))->toBe('Réf. MM-88421 — 3');
});

it('rejects label placeholders outside the schema and malformed braces', function (): void {
    expect(fn (): mixed => LabelTemplate::validate('x {evil.path}', ['payment.reference']))
        ->toThrow(ExpressionException::class, "Unknown variable 'evil.path'")
        ->and(fn (): mixed => LabelTemplate::validate('x {bad syntax}', []))
        ->toThrow(ExpressionException::class, 'malformed placeholder');
});

// ---------------------------------------------------------------------------
// Payload flattening
// ---------------------------------------------------------------------------

it('flattens nested payloads, preserving lists and partner tuples', function (): void {
    $flat = Payload::flatten([
        'payment' => [
            'amount' => 350_000,
            'partner' => ['type' => 'student', 'id' => 4412],
            'method' => ['fee_bearer_is_school' => true],
        ],
        'items' => [['net' => 100], ['net' => 200]],
    ]);

    expect($flat['payment.amount'])->toBe(350_000)
        ->and($flat['payment.partner'])->toBe(['type' => 'student', 'id' => 4412])
        ->and($flat['payment.method.fee_bearer_is_school'])->toBeTrue()
        ->and($flat['items'])->toHaveCount(2)
        ->and(Payload::scalars($flat))->toBe([
            'payment.amount' => 350_000,
            'payment.method.fee_bearer_is_school' => true,
        ]);
});
