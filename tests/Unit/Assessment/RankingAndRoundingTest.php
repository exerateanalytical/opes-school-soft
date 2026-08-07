<?php

declare(strict_types=1);

use App\Modules\Assessment\Domain\AssessmentException;
use App\Modules\Assessment\Domain\GradingPipeline;
use App\Modules\Assessment\Domain\Ranking;
use App\Modules\Assessment\Domain\Rounding;
use App\Modules\Assessment\Domain\SubjectResult;
use App\Support\Score\Score;

// Stage 6 (§10.4) and the rounding rule of §10.1 / invariant §18.9.

/**
 * The §10.1 bulletin with one subject varied, so two students land on the two
 * raw values §10.4 names: 234.25/18 = 13.0138... and 234.13/18 = 13.0072...
 *
 * @return list<SubjectResult>
 */
function opesBulletinWithAnglais(string $anglais): array
{
    return [
        new SubjectResult('mathematiques', Score::of('13.00'), 400, true, []),
        new SubjectResult('physique', Score::of('11.50'), 300, true, []),
        new SubjectResult('svt', Score::of('14.25'), 300, true, []),
        new SubjectResult('francais', Score::of('12.00'), 400, true, []),
        new SubjectResult('anglais', Score::of($anglais), 200, true, []),
        new SubjectResult('histoire-geo', Score::of('13.50'), 200, true, []),
    ];
}

// ---------------------------------------------------------------------------
// T11 - round first, then order. This is where ties are born.
// ---------------------------------------------------------------------------

it('T11: raw 13.0138 and raw 13.0072 both print 13.01 and share rank 4', function () {
    $pipeline = new GradingPipeline;

    // Atangana: Σ(M×Coef) = 234.25 over Σcoef 18 -> 13.013888...
    $atangana = $pipeline->generalAverage(opesBulletinWithAnglais('15.00'));
    // Tabi:     Σ(M×Coef) = 234.13 over Σcoef 18 -> 13.007222...
    $tabi = $pipeline->generalAverage(opesBulletinWithAnglais('14.94'));

    expect($atangana->raw?->toString())->toBe('13.014');
    expect($tabi->raw?->toString())->toBe('13.007');

    // They differ at the third decimal and are identical at the second.
    expect($atangana->rounded?->toDisplayString())->toBe('13.01');
    expect($tabi->rounded?->toDisplayString())->toBe('13.01');

    $table = Ranking::rank([
        'ngu' => Score::of('15.20'),
        'fotso' => Score::of('14.05'),
        'mbah' => Score::of('13.60'),
        'atangana' => $atangana->rounded,
        'tabi' => $tabi->rounded,
        'njoya' => Score::of('11.40'),
    ]);

    expect($table->rankOf('ngu'))->toBe(1);
    expect($table->rankOf('fotso'))->toBe(2);
    expect($table->rankOf('mbah'))->toBe(3);
    expect($table->rankOf('atangana'))->toBe(4);
    expect($table->rankOf('tabi'))->toBe(4);
    // Competition ranking: rank 5 is skipped.
    expect($table->rankOf('njoya'))->toBe(6);
    expect($table->denominator)->toBe(6);
});

it('T11: ordering on the raw value would have split the tie, and must not', function () {
    // Guarding the failure mode directly: 13.014 > 13.007, so an implementation
    // that sorted before rounding would rank them 4 and 5.
    expect(Score::of('13.014')->isGreaterThan(Score::of('13.007')))->toBeTrue();
    expect(Score::of('13.014')->isGreaterThanForRanking(Score::of('13.007')))->toBeFalse();

    $table = Ranking::rank(['a' => Score::of('13.014'), 'b' => Score::of('13.007')]);

    expect($table->rankOf('a'))->toBe(1);
    expect($table->rankOf('b'))->toBe(1);
});

it('T11: NULL students receive no rank and are absent from the denominator', function () {
    // §10.2 / §10.4: the cohort has 8 students, two of them NULL, and the card
    // prints "/ 6" - v1 printed the NULL students as 0.00, last, and counted them.
    $table = Ranking::rank([
        'ngu' => Score::of('15.20'),
        'fotso' => Score::of('14.05'),
        'mbah' => Score::of('13.60'),
        'atangana' => Score::of('13.01'),
        'tabi' => Score::of('13.01'),
        'njoya' => Score::of('11.40'),
        'nc-late-arrival' => null,
        'nc-no-subjects' => null,
    ]);

    expect($table->denominator)->toBe(6);
    expect($table->rankOf('nc-late-arrival'))->toBeNull();
    expect($table->rankOf('nc-no-subjects'))->toBeNull();
    expect($table->rankedRows())->toHaveCount(6);
});

it('applies competition ranking 1, 2, 2, 4 rather than dense ranking 1, 2, 2, 3', function () {
    $table = Ranking::rank([
        'a' => Score::of('15.00'),
        'b' => Score::of('14.00'),
        'c' => Score::of('14.00'),
        'd' => Score::of('13.00'),
    ]);

    expect(array_map(fn ($row) => $row->rank, $table->rankedRows()))->toBe([1, 2, 2, 4]);
});

it('ranks a three-way tie at the top and resumes at 4', function () {
    $table = Ranking::rank([
        'a' => Score::of('15.00'),
        'b' => Score::of('15.00'),
        'c' => Score::of('15.00'),
        'd' => Score::of('14.00'),
    ]);

    expect(array_map(fn ($row) => $row->rank, $table->rankedRows()))->toBe([1, 1, 1, 4]);
});

it('ranks an empty cohort without dividing by anything', function () {
    $table = Ranking::rank([]);

    expect($table->denominator)->toBe(0);
    expect($table->rows)->toBe([]);
});

it('keeps tied students in input order so a re-render of a snapshot is byte-identical', function () {
    $table = Ranking::rank(['tabi' => Score::of('13.01'), 'atangana' => Score::of('13.01')]);

    expect(array_map(fn ($row) => $row->key, $table->rankedRows()))->toBe(['tabi', 'atangana']);
});

// ---------------------------------------------------------------------------
// Rounding (§10.1, invariant §18.9).
// ---------------------------------------------------------------------------

it('rounds half UP, not half to even', function () {
    expect(Rounding::halfUp(Score::of('13.005'))->toDisplayString())->toBe('13.01');
    expect(Rounding::halfUp(Score::of('13.015'))->toDisplayString())->toBe('13.02');
    expect(Rounding::halfUp(Score::of('13.004'))->toDisplayString())->toBe('13.00');
});

it('honours a framework score_precision other than 2', function () {
    expect(Rounding::halfUp(Score::of('13.014'), 0)->toString())->toBe('13.000');
    expect(Rounding::halfUp(Score::of('13.514'), 0)->toString())->toBe('14.000');
    expect(Rounding::halfUp(Score::of('13.014'), 1)->toString())->toBe('13.000');
    expect(Rounding::halfUp(Score::of('13.064'), 1)->toString())->toBe('13.100');
    expect(Rounding::halfUp(Score::of('13.014'), 3)->toString())->toBe('13.014');
});

it('is idempotent, so a value that crosses the pipeline twice never drifts', function () {
    foreach (['13.014', '13.005', '13.995', '0.001', '20.000'] as $value) {
        $once = Rounding::halfUp(Score::of($value));

        expect(Rounding::halfUp($once)->toString())->toBe($once->toString());
    }
});

it('refuses a precision Score cannot represent', function () {
    Rounding::halfUp(Score::of('13.014'), 4);
})->throws(AssessmentException::class);

it('passes NULL through untouched, because a NULL average has nothing to round', function () {
    expect(Rounding::halfUpOrNull(null))->toBeNull();
    expect(Rounding::halfUpOrNull(Score::of('13.014'))?->toDisplayString())->toBe('13.01');
});
