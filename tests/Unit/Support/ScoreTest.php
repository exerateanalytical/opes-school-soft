<?php

declare(strict_types=1);

use App\Support\Score\Score;
use App\Support\Score\ScoreException;

it('constructs from thousandths', function () {
    expect(Score::ofThousandths(13_200)->thousandths())->toBe(13_200);
});

it('constructs from a decimal string', function () {
    expect(Score::of('13.2')->thousandths())->toBe(13_200);
    expect(Score::of('13.200')->thousandths())->toBe(13_200);
    expect(Score::of('9')->thousandths())->toBe(9_000);
});

it('rejects more than three decimal places', function () {
    Score::of('13.2005');
})->throws(ScoreException::class, 'three decimal places');

it('rejects a negative score', function () {
    Score::of('-1');
})->throws(ScoreException::class);

it('rejects a malformed score', function () {
    Score::of('twelve');
})->throws(ScoreException::class);

it('adds and multiplies without float drift', function () {
    expect(Score::of('13.333')->plus(Score::of('0.667'))->toString())->toBe('14.000');
    expect(Score::of('4.5')->times(3)->toString())->toBe('13.500');
});

it('divides with half-up rounding at three decimal places', function () {
    expect(Score::of('40')->dividedBy(3)->toString())->toBe('13.333');
    expect(Score::of('20')->dividedBy(3)->toString())->toBe('6.667');
});

it('rejects division by zero', function () {
    Score::of('10')->dividedBy(0);
})->throws(ScoreException::class);

it('rounds to two decimal places half up, once, for display and ranking', function () {
    expect(Score::of('12.345')->roundedToDisplay()->toString())->toBe('12.350');
    expect(Score::of('12.344')->roundedToDisplay()->toString())->toBe('12.340');
    expect(Score::of('9.995')->roundedToDisplay()->toDisplayString())->toBe('10.00');
});

it('formats for display at two decimal places', function () {
    expect(Score::of('13.2')->toDisplayString())->toBe('13.20');
    expect(Score::of('7')->toDisplayString())->toBe('7.00');
});

it('ranks on the rounded value so equal printed marks rank equally', function () {
    $a = Score::of('12.3449');
    $b = Score::of('12.3441');
})->throws(ScoreException::class);

it('treats scores printing the same as equal for ranking', function () {
    $a = Score::of('12.344');
    $b = Score::of('12.341');

    expect($a->toDisplayString())->toBe($b->toDisplayString());
    expect($a->equalsForRanking($b))->toBeTrue();
    expect($a->isGreaterThanForRanking($b))->toBeFalse();
});

it('computes a weighted average conserving precision', function () {
    // Mathematics 14/20 coefficient 4, English 12/20 coefficient 2.
    // (14*4 + 12*2) / 6 = 80/6 = 13.333
    $average = Score::weightedAverage([
        [Score::of('14'), 4],
        [Score::of('12'), 2],
    ]);

    expect($average?->toString())->toBe('13.333');
});

it('returns null for a weighted average with no assessed subjects', function () {
    expect(Score::weightedAverage([]))->toBeNull();
});

it('returns null when every coefficient is zero', function () {
    expect(Score::weightedAverage([[Score::of('14'), 0]]))->toBeNull();
});

it('compares and stringifies', function () {
    expect(Score::of('14')->isGreaterThan(Score::of('12')))->toBeTrue();
    expect(Score::of('12')->isLessThan(Score::of('14')))->toBeTrue();
    expect(Score::of('12')->equals(Score::of('12.000')))->toBeTrue();
    expect((string) Score::of('13.5'))->toBe('13.500');
    expect(Score::zero()->toString())->toBe('0.000');
});
