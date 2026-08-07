<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Money\MoneyException;

it('splits evenly when it divides cleanly', function () {
    $parts = Money::of(300_000)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amount(), $parts))
        ->toBe([100_000, 100_000, 100_000]);
});

it('gives the residual to the earliest parts, never losing a franc', function () {
    $parts = Money::of(350_000)->allocate([1, 1, 1]);
    $amounts = array_map(fn (Money $m) => $m->amount(), $parts);

    expect($amounts)->toBe([116_667, 116_667, 116_666]);
    expect(array_sum($amounts))->toBe(350_000);
});

it('respects weighted ratios', function () {
    $parts = Money::of(100_000)->allocate([50, 30, 20]);

    expect(array_map(fn (Money $m) => $m->amount(), $parts))
        ->toBe([50_000, 30_000, 20_000]);
});

it('conserves the total for negative amounts', function () {
    $parts = Money::of(-350_000)->allocate([1, 1, 1]);
    $amounts = array_map(fn (Money $m) => $m->amount(), $parts);

    expect(array_sum($amounts))->toBe(-350_000);
});

it('tolerates a zero ratio', function () {
    $parts = Money::of(1_000)->allocate([1, 0, 1]);

    expect(array_map(fn (Money $m) => $m->amount(), $parts))
        ->toBe([500, 0, 500]);
});

it('splits into equal parts', function () {
    $amounts = array_map(fn (Money $m) => $m->amount(), Money::of(10)->split(3));

    expect($amounts)->toBe([4, 3, 3]);
    expect(array_sum($amounts))->toBe(10);
});

it('rejects an empty ratio list', function () {
    Money::of(100)->allocate([]);
})->throws(MoneyException::class, 'at least one ratio');

it('rejects a negative ratio', function () {
    Money::of(100)->allocate([1, -1]);
})->throws(MoneyException::class, 'non-negative');

it('rejects ratios summing to zero', function () {
    Money::of(100)->allocate([0, 0]);
})->throws(MoneyException::class, 'more than zero');

it('conserves the total across a wide sweep of amounts and ratios', function () {
    $ratioSets = [[1, 1, 1], [50, 30, 20], [1, 2, 3, 4], [7, 11], [1], [1, 0, 0, 1]];

    foreach ($ratioSets as $ratios) {
        foreach ([1, 2, 7, 99, 100, 333, 1_000, 12_345, 350_000, 999_999] as $amount) {
            $parts = Money::of($amount)->allocate($ratios);
            $sum = array_sum(array_map(fn (Money $m) => $m->amount(), $parts));

            expect($sum)->toBe($amount, "amount {$amount} with ratios ".implode(',', $ratios));
            expect($parts)->toHaveCount(count($ratios));
        }
    }
});
