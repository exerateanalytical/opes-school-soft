<?php

declare(strict_types=1);

use App\Modules\Assessment\Domain\ClassStatistics;
use App\Modules\Assessment\Domain\Gpa;
use App\Modules\Assessment\Domain\PassRule;
use App\Support\Score\Score;

// §10.3 the single source of "pass", §10.7 class statistics, §11 GPA.

// ---------------------------------------------------------------------------
// T23 - every pass threshold lives in PassRule and nowhere else.
// ---------------------------------------------------------------------------

it('T23: pass is score >= framework.pass_score, taken from configuration and never a literal', function () {
    $passScore = Score::of('10.00');   // framework.pass_score, supplied by the caller

    expect(PassRule::passes(Score::of('10.00'), $passScore))->toBeTrue();
    expect(PassRule::passes(Score::of('9.99'), $passScore))->toBeFalse();
    expect(PassRule::passes(Score::of('20.00'), $passScore))->toBeTrue();
});

it('T23: a framework with a different pass score is obeyed without touching this class', function () {
    // A percentage-basis framework passing at 50, and a stricter school at 12/20.
    expect(PassRule::passes(Score::of('50.00'), Score::of('50.00')))->toBeTrue();
    expect(PassRule::passes(Score::of('11.99'), Score::of('12.00')))->toBeFalse();
});

it('T23: T1 s student passes, which is the whole point of the §2.1 correction', function () {
    expect(PassRule::passes(Score::of('13.20'), Score::of('10.00')))->toBeTrue();
    // What v1 s wrong pipeline order would have produced for the same marks.
    expect(PassRule::passes(Score::of('9.84'), Score::of('10.00')))->toBeFalse();
});

it('compares on the ROUNDED value, so a card printing the pass mark is a pass', function () {
    // Raw 9.995 prints 10.00; a raw comparison would fail a student whose card says PASS.
    expect(PassRule::passes(Score::of('9.995'), Score::of('10.00')))->toBeTrue();
    expect(PassRule::passes(Score::of('9.994'), Score::of('10.00')))->toBeFalse();
});

it('treats a NULL average as not assessed rather than as a fail', function () {
    expect(PassRule::passesOrNull(null, Score::of('10.00')))->toBeFalse();
    // And it is absent from both sides of the pass rate.
    expect(PassRule::countPassing([null, null], Score::of('10.00')))->toBe(0);
    expect(PassRule::passRate([null, null], Score::of('10.00')))->toBeNull();
});

it('computes a pass rate over non-NULL averages only', function () {
    $scores = [
        Score::of('15.20'), Score::of('14.05'), Score::of('13.60'),
        Score::of('9.50'), null, null,
    ];

    $rate = PassRule::passRate($scores, Score::of('10.00'));

    // 3 of 4 assessed students, not 3 of 6.
    expect(PassRule::countPassing($scores, Score::of('10.00')))->toBe(3);
    expect($rate?->basisPoints())->toBe(75_000);
});

// ---------------------------------------------------------------------------
// §10.7 class statistics, over ranked non-NULL students only.
// ---------------------------------------------------------------------------

it('computes the statistics block over non-NULL students only', function () {
    $stats = ClassStatistics::of(
        [Score::of('15.20'), Score::of('14.05'), Score::of('13.60'), Score::of('13.01'), Score::of('13.01'), Score::of('11.40'), null, null],
        Score::of('10.00'),
    );

    expect($stats->n)->toBe(6);                       // not 8
    expect($stats->min?->toDisplayString())->toBe('11.40');
    expect($stats->max?->toDisplayString())->toBe('15.20');
    // (11.40 + 13.01 + 13.01 + 13.60 + 14.05 + 15.20) / 6 = 80.27 / 6 = 13.378333
    expect($stats->mean?->toString())->toBe('13.378');
    // Lower median for even n, stated explicitly by §10.7.
    expect($stats->median?->toDisplayString())->toBe('13.01');
    expect($stats->passCount)->toBe(6);
    expect($stats->passRate?->basisPoints())->toBe(100_000);
});

it('reports the POPULATION standard deviation, divisor n', function () {
    // 10, 12, 14, 16: mean 13, population variance 5, sigma = 2.236067...
    $stats = ClassStatistics::of(
        [Score::of('10'), Score::of('12'), Score::of('14'), Score::of('16')],
        Score::of('10.00'),
    );

    expect($stats->mean?->toString())->toBe('13.000');
    expect($stats->stdevPopulation?->toString())->toBe('2.236');
    // The SAMPLE deviation for the same data is 2.582 - the figure §10.7 forbids.
    expect($stats->stdevPopulation?->toString())->not->toBe('2.582');
});

it('has no statistics at all for a cohort of NULLs', function () {
    $stats = ClassStatistics::of([null, null], Score::of('10.00'));

    expect($stats->n)->toBe(0);
    expect($stats->mean)->toBeNull();
    expect($stats->stdevPopulation)->toBeNull();
    expect($stats->passRate)->toBeNull();
});

it('gives a single-student cohort a zero deviation rather than a division by n-1', function () {
    $stats = ClassStatistics::of([Score::of('13.01')], Score::of('10.00'));

    expect($stats->n)->toBe(1);
    expect($stats->stdevPopulation?->toString())->toBe('0.000');
    expect($stats->mean?->toDisplayString())->toBe('13.01');
});

// ---------------------------------------------------------------------------
// §11 GPA - coefficient-weighted, over BANDED points.
// ---------------------------------------------------------------------------

it('computes the §11 worked GPA as 3.11 over Σcoef 18', function () {
    $gpa = Gpa::compute([
        [Score::of('3.00'), 400],   // Maths, Assez Bien
        [Score::of('2.00'), 300],   // Physique, Passable
        [Score::of('4.00'), 300],   // SVT, Bien
        [Score::of('3.00'), 400],   // Français, Assez Bien
        [Score::of('4.00'), 200],   // Anglais, Bien
        [Score::of('3.00'), 200],   // Hist-Géo, Assez Bien
    ]);

    expect($gpa?->toDisplayString())->toBe('3.11');   // 56.00 / 18 = 3.111...
});

it('returns NULL when any banded subject has no grade point, rather than averaging a subset', function () {
    $gpa = Gpa::compute([
        [Score::of('3.00'), 400],
        [null, 300],
        [Score::of('4.00'), 300],
    ]);

    expect($gpa)->toBeNull();
});

it('returns NULL when nothing carries a coefficient', function () {
    expect(Gpa::compute([]))->toBeNull();
    expect(Gpa::compute([[Score::of('3.00'), 0]]))->toBeNull();
});

it('does not track the general average linearly, because it is computed from bands', function () {
    // §11: both are printed and neither is derived from the other. 13.01/20 is
    // 65% of scale; the GPA of 3.11/5.00 is 62% - deliberately coarser.
    $gpa = Gpa::compute([[Score::of('3.00'), 100]]);

    expect($gpa?->toDisplayString())->toBe('3.00');
});
