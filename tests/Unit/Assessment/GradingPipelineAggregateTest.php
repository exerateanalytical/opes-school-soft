<?php

declare(strict_types=1);

use App\Modules\Assessment\Domain\AnnualComposition;
use App\Modules\Assessment\Domain\ComponentMark;
use App\Modules\Assessment\Domain\GradingPipeline;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\MissingComponentPolicy;
use App\Modules\Assessment\Domain\PeriodContribution;
use App\Modules\Assessment\Domain\SubjectInput;
use App\Modules\Assessment\Domain\SubjectResult;
use App\Support\Score\Score;

// Stage 5 (§10.1) and the term/annual composition of §9.

/** A ready-made stage-4 result, so the aggregate tests are not hostage to stages 2-4. */
function opesSubject(string $key, ?string $score, int $coefficient, bool $countsTowardAverage = true): SubjectResult
{
    return new SubjectResult(
        $key,
        $score === null ? null : Score::of($score),
        $coefficient * 100,
        $countsTowardAverage,
        [],
    );
}

/**
 * The §10.1 / §13.6 worked table.
 *
 * @return list<SubjectResult>
 */
function opesReferenceBulletin(string $anglais = '15.00'): array
{
    return [
        opesSubject('mathematiques', '13.00', 4),
        opesSubject('physique', '11.50', 3),
        opesSubject('svt', '14.25', 3),
        opesSubject('francais', '12.00', 4),
        opesSubject('anglais', $anglais, 2),
        opesSubject('histoire-geo', '13.50', 2),
        opesSubject('eps', null, 2), // exempt in every component (§6.4 case 3)
    ];
}

// ---------------------------------------------------------------------------
// §10.1 general average and the totals row.
// ---------------------------------------------------------------------------

it('computes the §10.1 worked bulletin as 13.01/20 over a Σcoef of 18, not 20', function () {
    $average = (new GradingPipeline)->generalAverage(opesReferenceBulletin());

    expect($average->raw?->toString())->toBe('13.014');       // 234.25 / 18 = 13.013888...
    expect($average->rounded?->toDisplayString())->toBe('13.01');
    // EPS is unassessed, so it leaves BOTH columns: the printed Σcoef is 18.
    expect($average->sumCoefficientHundredths)->toBe(1_800);
    expect($average->weightedTotal()->toString())->toBe('234.250');
    expect($average->contributingSubjectKeys)->not->toContain('eps');
});

it('drops a subject flagged counts_toward_average = 0 from both columns', function () {
    $subjects = [
        opesSubject('mathematiques', '13.00', 4),
        opesSubject('conduite', '20.00', 4, countsTowardAverage: false),
    ];

    $average = (new GradingPipeline)->generalAverage($subjects);

    expect($average->rounded?->toDisplayString())->toBe('13.00');
    expect($average->sumCoefficientHundredths)->toBe(400);
});

it('supports a fractional coefficient, because SubjectAllocation.coefficient is DECIMAL(5,2)', function () {
    // 12.00 at coef 1.50 and 14.00 at coef 2.50 -> (18 + 35) / 4 = 13.250
    $subjects = [
        new SubjectResult('a', Score::of('12'), 150, true, []),
        new SubjectResult('b', Score::of('14'), 250, true, []),
    ];

    $average = (new GradingPipeline)->generalAverage($subjects);

    expect($average->raw?->toString())->toBe('13.250');
    expect($average->sumCoefficientHundredths)->toBe(400);
});

// ---------------------------------------------------------------------------
// T4 - Σcoefficient = 0 must be NULL, not zero.
// ---------------------------------------------------------------------------

it('T4: Σcoef = 0 yields a NULL average, never 0.00 and never an exception', function () {
    // Every subject unassessed (§6.4 case 3), so no coefficient survives.
    $subjects = [
        opesSubject('mathematiques', null, 4),
        opesSubject('francais', null, 4),
    ];

    $average = (new GradingPipeline)->generalAverage($subjects);

    expect($average->raw)->toBeNull();
    expect($average->rounded)->toBeNull();
    expect($average->isNotAssessed())->toBeTrue();
    expect($average->sumCoefficientHundredths)->toBe(0);
    expect($average->contributingSubjectKeys)->toBe([]);
});

it('T4: a student with no subjects at all is NULL, not zero', function () {
    $average = (new GradingPipeline)->generalAverage([]);

    expect($average->rounded)->toBeNull();
    expect($average->isNotAssessed())->toBeTrue();
});

it('T4: subjects that all carry coefficient 0.00 also yield NULL', function () {
    $subjects = [
        opesSubject('atelier', '17.00', 0),
        opesSubject('club', '11.00', 0),
    ];

    $average = (new GradingPipeline)->generalAverage($subjects);

    expect($average->rounded)->toBeNull();
    expect($average->sumCoefficientHundredths)->toBe(0);
});

it('T4: a blocked subject cannot be quietly averaged over', function () {
    $blocked = new SubjectResult('maths', null, 400, true, [], ['EXAM']);

    expect($blocked->isBlocked())->toBeTrue();
    expect($blocked->contributesToAverage())->toBeFalse();
    expect((new GradingPipeline)->generalAverage([$blocked])->rounded)->toBeNull();
});

// ---------------------------------------------------------------------------
// T7 - term composition: skip nulls and renormalise (§9.1).
// ---------------------------------------------------------------------------

it('T7: a null child renormalises to 13.500 rather than averaging in a zero for 6.75', function () {
    $composed = (new GradingPipeline)->composeChildren([
        new PeriodContribution('seq1', null, 10_000),
        new PeriodContribution('seq2', Score::of('13.50'), 10_000),
    ]);

    expect($composed->score?->toString())->toBe('13.500');
    expect($composed->score?->toString())->not->toBe('6.750');
    expect($composed->participatingCount)->toBe(1);
});

it('T7: all-null children drop the subject entirely from the parent period', function () {
    $composed = (new GradingPipeline)->composeChildren([
        new PeriodContribution('seq1', null, 10_000),
        new PeriodContribution('seq2', null, 10_000),
    ]);

    expect($composed->score)->toBeNull();
    expect($composed->isUnassessed())->toBeTrue();
    expect($composed->participatingCount)->toBe(0);
});

it('T7: unequal weights compose to 13.000, and renormalise to 14.000 when the first is null', function () {
    $pipeline = new GradingPipeline;

    $both = $pipeline->composeChildren([
        new PeriodContribution('seq3', Score::of('11.00'), 10_000),
        new PeriodContribution('seq4', Score::of('14.00'), 20_000),
    ]);
    $renormalised = $pipeline->composeChildren([
        new PeriodContribution('seq3', null, 10_000),
        new PeriodContribution('seq4', Score::of('14.00'), 20_000),
    ]);

    expect($both->score?->toString())->toBe('13.000');
    // The surviving weight is renormalised, not carried as a smaller denominator.
    expect($renormalised->score?->toString())->toBe('14.000');
});

it('T7: a mock exam with counts_toward_parent = 0 is excluded even though it has a score', function () {
    // §16.5: a Bac blanc exists as a period without polluting the term average.
    $composed = (new GradingPipeline)->composeChildren([
        new PeriodContribution('seq1', Score::of('12.00'), 10_000),
        new PeriodContribution('bac-blanc', Score::of('4.00'), 10_000, countsTowardParent: false),
    ]);

    expect($composed->score?->toString())->toBe('12.000');
    expect($composed->participatingCount)->toBe(1);
});

it('rejects a zero or negative period weight rather than normalising by nothing', function () {
    expect(fn () => new PeriodContribution('seq1', Score::of('12'), 0))->toThrow(RuntimeException::class);
    expect(fn () => new PeriodContribution('seq1', Score::of('12'), -1))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// T8 - annual = Σ(6 sequences) ÷ 6, computed in exactly one place (§9.2, §9.4).
// ---------------------------------------------------------------------------

/** @return list<Score|null> */
function opesSixSequences(bool $seq3Missing = false): array
{
    return [
        Score::of('12.40'),
        Score::of('13.10'),
        $seq3Missing ? null : Score::of('11.75'),
        Score::of('14.00'),
        Score::of('12.85'),
        Score::of('13.30'),
    ];
}

it('T8: the annual average is the unweighted mean of the six sequences, 12.900', function () {
    $annual = (new GradingPipeline)->annualAverage(
        AnnualComposition::MeanOfLeafPeriods,
        leafPeriodAverages: opesSixSequences(),
    );

    expect($annual->score?->toString())->toBe('12.900');   // 77.40 / 6
    expect($annual->participatingCount)->toBe(6);
});

it('T8: a missing sequence divides by 5 and prints the divisor, 13.130', function () {
    $annual = (new GradingPipeline)->annualAverage(
        AnnualComposition::MeanOfLeafPeriods,
        leafPeriodAverages: opesSixSequences(seq3Missing: true),
    );

    expect($annual->score?->toString())->toBe('13.130');   // 65.65 / 5
    // The card prints "Moyenne annuelle (5 séq.)" so the reader sees the divisor.
    expect($annual->participatingCount)->toBe(5);
});

it('T8: the report card and the promotion engine return byte-identical values', function () {
    // §9.4: v1's promotion engine had its own arithmetic and decided 13.28 while
    // the bulletin printed 13.13 for the same student in the same run. There is
    // exactly ONE implementation, so both callers are literally this call.
    $pipeline = new GradingPipeline;
    $sequences = opesSixSequences(seq3Missing: true);

    $reportCard = $pipeline->annualAverage(AnnualComposition::MeanOfLeafPeriods, leafPeriodAverages: $sequences);
    $promotionEngine = $pipeline->annualAverage(AnnualComposition::MeanOfLeafPeriods, leafPeriodAverages: $sequences);

    expect($reportCard->score?->toString())->toBe($promotionEngine->score?->toString());
    expect($reportCard->score?->toString())->toBe('13.130');
    // And it is NOT the discredited mean-of-trimestres figure.
    expect($reportCard->score?->toString())->not->toBe('13.275');
});

it('T8: mean_of_terms diverges from mean_of_leaf_periods, which is why the default is not negotiable', function () {
    $pipeline = new GradingPipeline;

    // T1 = mean(12.40, 13.10) = 12.75; T2 = 14.00 (séq 3 missing); T3 = 13.075
    $terms = [Score::of('12.75'), Score::of('14.00'), Score::of('13.075')];

    $meanOfTerms = $pipeline->annualAverage(AnnualComposition::MeanOfTerms, termAverages: $terms);
    $meanOfLeaves = $pipeline->annualAverage(
        AnnualComposition::MeanOfLeafPeriods,
        leafPeriodAverages: opesSixSequences(seq3Missing: true),
    );

    expect($meanOfTerms->score?->toString())->toBe('13.275');
    expect($meanOfLeaves->score?->toString())->toBe('13.130');
    // A 0.145 divergence - enough to change a rank and, at a band boundary, a mention.
    expect(Score::of('13.130')->plus(Score::of('0.145'))->toString())->toBe('13.275');
});

it('T8: weighted_children applies §9.1 recursively at the year level', function () {
    $annual = (new GradingPipeline)->annualAverage(
        AnnualComposition::WeightedChildren,
        weightedChildren: [
            new PeriodContribution('t1', Score::of('12.75'), 10_000),
            new PeriodContribution('t2', Score::of('14.00'), 20_000),
        ],
    );

    expect($annual->score?->toString())->toBe('13.583');    // (12.75 + 28.00) / 3
});

it('an entirely unassessed year is NULL, so the student is absent from every downstream denominator', function () {
    $annual = (new GradingPipeline)->annualAverage(
        AnnualComposition::MeanOfLeafPeriods,
        leafPeriodAverages: [null, null, null, null, null, null],
    );

    expect($annual->score)->toBeNull();
    expect($annual->participatingCount)->toBe(0);
});

// ---------------------------------------------------------------------------
// Stages 2-5 end to end, so the seam between them is exercised too.
// ---------------------------------------------------------------------------

it('runs collect → normalize → compose → weight → aggregate for one student', function () {
    $pipeline = new GradingPipeline;

    $subjects = [
        // T1's subject at coefficient 4, plus an exempt subject at coefficient 2.
        $pipeline->subjectScore(new SubjectInput('maths', [
            ComponentMark::scored('CA', Score::of('24'), Score::of('30'), 30),
            ComponentMark::scored('EXAM', Score::of('60'), Score::of('100'), 70),
        ], 400), Score::of('20'), MissingComponentPolicy::BlockPublication),
        $pipeline->subjectScore(new SubjectInput('eps', [
            ComponentMark::inState('TP', MarkState::Exempt, Score::of('20'), 100),
        ], 200), Score::of('20'), MissingComponentPolicy::BlockPublication),
    ];

    $average = $pipeline->generalAverage($subjects);

    expect($average->rounded?->toDisplayString())->toBe('13.20');
    expect($average->sumCoefficientHundredths)->toBe(400);
});
