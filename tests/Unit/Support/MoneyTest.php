<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Money\MoneyException;

it('constructs from whole francs', function () {
    expect(Money::of(350_000)->amount())->toBe(350_000);
});

it('supports negative amounts', function () {
    expect(Money::of(-1_500)->amount())->toBe(-1_500);
    expect(Money::of(-1_500)->isNegative())->toBeTrue();
});

it('has a zero constructor', function () {
    expect(Money::zero()->amount())->toBe(0);
    expect(Money::zero()->isZero())->toBeTrue();
});

it('adds and subtracts without loss', function () {
    $a = Money::of(350_000);
    $b = Money::of(120_000);

    expect($a->plus($b)->amount())->toBe(470_000);
    expect($a->minus($b)->amount())->toBe(230_000);
});

it('goes negative on subtraction rather than throwing', function () {
    expect(Money::of(100)->minus(Money::of(250))->amount())->toBe(-150);
});

it('multiplies by an integer factor', function () {
    expect(Money::of(1_250)->times(4)->amount())->toBe(5_000);
});

it('negates and takes absolute value', function () {
    expect(Money::of(700)->negated()->amount())->toBe(-700);
    expect(Money::of(-700)->absolute()->amount())->toBe(700);
});

it('compares', function () {
    expect(Money::of(500)->isGreaterThan(Money::of(400)))->toBeTrue();
    expect(Money::of(500)->equals(Money::of(500)))->toBeTrue();
    expect(Money::of(500)->isLessThan(Money::of(500)))->toBeFalse();
    expect(Money::of(500)->isGreaterThanOrEqualTo(Money::of(500)))->toBeTrue();
    expect(Money::of(500)->isLessThanOrEqualTo(Money::of(500)))->toBeTrue();
});

it('rejects a float amount', function () {
    // Invoked through a callable so the static analyser cannot pre-empt the
    // runtime check. strict_types applies at THIS call site, so PHP raises a
    // TypeError rather than silently truncating 1500.75 to 1500.
    /** @var callable $construct */
    $construct = [Money::class, 'of'];

    $construct(1_500.75);
})->throws(TypeError::class);

it('sums a list', function () {
    expect(Money::sum([Money::of(100), Money::of(250), Money::of(-50)])->amount())->toBe(300);
});

it('sums an empty list to zero', function () {
    expect(Money::sum([])->amount())->toBe(0);
});

it('formats for display with a thin space group separator', function () {
    expect(Money::of(1_250_000)->format())->toBe("1\u{202F}250\u{202F}000 FCFA");
    expect(Money::of(-4_500)->format())->toBe("-4\u{202F}500 FCFA");
    expect(Money::of(1_250)->format(false))->toBe("1\u{202F}250");
});

it('is immutable', function () {
    $a = Money::of(100);
    $a->plus(Money::of(50));

    expect($a->amount())->toBe(100);
});

it('rejects overflow past the BIGINT SIGNED ceiling', function () {
    Money::of(PHP_INT_MAX)->plus(Money::of(1));
})->throws(MoneyException::class, 'overflow');

it('serialises to an integer for JSON and a string for casting', function () {
    expect(json_encode(Money::of(350_000)))->toBe('350000');
    expect((string) Money::of(350_000))->toBe('350000');
});
