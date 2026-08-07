<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Rate\Rate;
use App\Support\Rate\RateException;

it('constructs from basis points', function () {
    expect(Rate::ofBasisPoints(4_200)->basisPoints())->toBe(4_200);
});

it('constructs from a percentage string without float drift', function () {
    expect(Rate::ofPercent('4.2')->basisPoints())->toBe(4_200);
    expect(Rate::ofPercent('19.25')->basisPoints())->toBe(19_250);
    expect(Rate::ofPercent('1')->basisPoints())->toBe(1_000);
    expect(Rate::ofPercent('0.5')->basisPoints())->toBe(500);
    expect(Rate::ofPercent('100')->basisPoints())->toBe(100_000);
});

it('rejects a percentage with more than two decimal places', function () {
    Rate::ofPercent('4.205');
})->throws(RateException::class, 'two decimal places');

it('rejects a malformed percentage', function () {
    Rate::ofPercent('abc');
})->throws(RateException::class);

it('rejects a negative rate', function () {
    Rate::ofBasisPoints(-1);
})->throws(RateException::class, 'negative');

it('applies to money with half-up rounding, once', function () {
    // CNPS PVID employee share: 4.2% of the 750,000 ceiling = 31,500 exactly.
    expect(Rate::ofPercent('4.2')->applyTo(Money::of(750_000))->amount())->toBe(31_500);
});

it('rounds half up', function () {
    expect(Rate::ofPercent('1')->applyTo(Money::of(1_050))->amount())->toBe(11);
    expect(Rate::ofPercent('1')->applyTo(Money::of(1_049))->amount())->toBe(10);
});

it('rounds half up away from zero for negative amounts', function () {
    expect(Rate::ofPercent('1')->applyTo(Money::of(-1_050))->amount())->toBe(-11);
});

it('renders back to a percentage string', function () {
    expect(Rate::ofBasisPoints(19_250)->toPercentString())->toBe('19.25');
    expect(Rate::ofBasisPoints(4_200)->toPercentString())->toBe('4.20');
    expect(Rate::ofBasisPoints(1_000)->toPercentString())->toBe('1.00');
    expect(Rate::ofBasisPoints(100_000)->toPercentString())->toBe('100.00');
});

it('is comparable and has a zero constructor', function () {
    expect(Rate::ofPercent('4.2')->equals(Rate::ofBasisPoints(4_200)))->toBeTrue();
    expect(Rate::zero()->isZero())->toBeTrue();
    expect(Rate::zero()->applyTo(Money::of(999_999))->amount())->toBe(0);
});

it('never returns a float from applyTo', function () {
    $result = Rate::ofPercent('19.25')->applyTo(Money::of(350_000));

    expect($result)->toBeInstanceOf(Money::class);
    expect($result->amount())->toBeInt();
});

it('stringifies with a percent sign', function () {
    expect((string) Rate::ofPercent('19.25'))->toBe('19.25%');
});
