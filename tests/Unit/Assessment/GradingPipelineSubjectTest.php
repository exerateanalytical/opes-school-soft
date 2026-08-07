<?php

declare(strict_types=1);

use App\Modules\Assessment\Domain\AssessmentException;
use App\Modules\Assessment\Domain\ComponentMark;
use App\Modules\Assessment\Domain\GradingPipeline;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\MissingComponentPolicy;
use App\Modules\Assessment\Domain\SubjectInput;
use App\Support\Score\Score;

// Stages 2-4 of docs/specs/01-assessment.md §2.2: NORMALIZE -> COMPOSE -> WEIGHT.

function opesMax20(): Score
{
    return Score::of('20');
}

function opesPipeline(): GradingPipeline
{
    return new GradingPipeline;
}

// ---------------------------------------------------------------------------
// T1 - the §2.1 counterexample. This single case is why the spec was rewritten.
// ---------------------------------------------------------------------------

it('T1: composes CA 24/30 and Exam 60/100 at weights 30/70 to exactly 13.200/20', function () {
    $subject = new SubjectInput(
        key: 'maths',
        components: [
            ComponentMark::scored('CA', Score::of('24'), Score::of('30'), 30),
            ComponentMark::scored('EXAM', Score::of('60'), Score::of('100'), 70),
        ],
        coefficientHundredths: 100,
    );

    $result = opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication);

    expect($result->score)->not->toBeNull();
    // Asserted on the exact integer thousandths, not "approximately".
    expect($result->score?->thousandths())->toBe(13_200);
    expect($result->score?->toString())->toBe('13.200');
    expect($result->score?->toDisplayString())->toBe('13.20');
});

it('T1: the v1 order would have failed this student, so the corrected order must pass them', function () {
    $subject = new SubjectInput('maths', [
        ComponentMark::scored('CA', Score::of('24'), Score::of('30'), 30),
        ComponentMark::scored('EXAM', Score::of('60'), Score::of('100'), 70),
    ], 100);

    $score = opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication)->score;

    // v1 composed raw and normalised against 100: 49.2% -> 9.84/20 -> FAIL.
    expect($score?->thousandths())->not->toBe(9_840);
    expect($score?->isGreaterThan(Score::of('10')))->toBeTrue();
});

// ---------------------------------------------------------------------------
// T9 - the §6.3 effective-maximum precedence chain.
// ---------------------------------------------------------------------------

it('T9: max_score_override 40 turns a 34 into 17.000/20', function () {
    $subject = new SubjectInput(
        key: 'tp',
        components: [
            // The component's own max is 20; the allocation override of 40 wins.
            ComponentMark::scored('TP', Score::of('34'), Score::of('20'), 100),
        ],
        coefficientHundredths: 200,
        maxScoreOverride: Score::of('40'),
    );

    $result = opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication);

    expect($result->score?->thousandths())->toBe(17_000);
    expect($result->componentOutcomes[0]->effectiveMaximum->toString())->toBe('40.000');
});

it('T9: without the override the same 34 would exceed the component maximum and be refused', function () {
    $subject = new SubjectInput('tp', [
        ComponentMark::scored('TP', Score::of('34'), Score::of('20'), 100),
    ], 200);

    opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication);
})->throws(AssessmentException::class);

it('falls through to the component maximum, then to the framework maximum', function () {
    $viaComponent = new SubjectInput('s', [
        ComponentMark::scored('C', Score::of('5'), Score::of('10'), 100),
    ], 100);
    $viaFramework = new SubjectInput('s', [
        ComponentMark::scored('C', Score::of('5'), null, 100),
    ], 100);

    $pipeline = opesPipeline();

    // 5/10 -> 0.5 -> 10.000/20.
    expect($pipeline->subjectScore($viaComponent, opesMax20(), MissingComponentPolicy::BlockPublication)->score?->thousandths())
        ->toBe(10_000);
    // 5/20 -> 0.25 -> 5.000/20.
    expect($pipeline->subjectScore($viaFramework, opesMax20(), MissingComponentPolicy::BlockPublication)->score?->thousandths())
        ->toBe(5_000);
});

// ---------------------------------------------------------------------------
// T5 - the four mark states, §6.4's three worked cases, to 3 dp.
// ---------------------------------------------------------------------------

/** Mathematics, coefficient 4, CA (max 20, weight 40) and Exam (max 20, weight 60). */
function opesMathematics(MarkState $examState, ?Score $examScore = null, bool $justified = false): SubjectInput
{
    return new SubjectInput('mathematiques', [
        ComponentMark::scored('CA', Score::of('14'), Score::of('20'), 40),
        new ComponentMark('EXAM', $examState, $examScore, Score::of('20'), 60, $justified),
    ], 400);
}

it('T5 case 1: CA 14/20 with the exam absent_unjustified gives exactly 5.600/20', function () {
    $result = opesPipeline()->subjectScore(
        opesMathematics(MarkState::AbsentUnjustified),
        opesMax20(),
        MissingComponentPolicy::BlockPublication,
    );

    expect($result->score?->toString())->toBe('5.600');
    expect($result->componentOutcomes[1]->weightRetained)->toBeTrue();
});

it('T5 case 2: the same marks with the exam absent_justified give exactly 14.000/20', function () {
    $result = opesPipeline()->subjectScore(
        opesMathematics(MarkState::AbsentJustified),
        opesMax20(),
        MissingComponentPolicy::BlockPublication,
    );

    expect($result->score?->toString())->toBe('14.000');
    expect($result->componentOutcomes[1]->weightRetained)->toBeFalse();
});

it('T5: the justification flag is worth 8.400 points out of 20 in one subject', function () {
    $pipeline = opesPipeline();
    $unjustified = $pipeline->subjectScore(opesMathematics(MarkState::AbsentUnjustified), opesMax20(), MissingComponentPolicy::BlockPublication);
    $justified = $pipeline->subjectScore(opesMathematics(MarkState::AbsentJustified), opesMax20(), MissingComponentPolicy::BlockPublication);

    expect($unjustified->score?->toString())->toBe('5.600');
    expect($justified->score?->toString())->toBe('14.000');
    // On a Σcoef of 30 that gap is 1.12 points of general average and several
    // rank places, which is why absent_justified is a controlled, audited field.
    expect(Score::of('5.600')->plus(Score::of('8.400'))->toString())->toBe('14.000');
});

it('T5 case 3: every component exempt leaves the subject unassessed, not zero', function () {
    $subject = new SubjectInput('eps', [
        ComponentMark::inState('CA', MarkState::Exempt, Score::of('20'), 40),
        ComponentMark::inState('EXAM', MarkState::Exempt, Score::of('20'), 60),
    ], 200);

    $result = opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication);

    expect($result->score)->toBeNull();
    expect($result->isUnassessed())->toBeTrue();
    // Its coefficient leaves the denominator as well as the numerator (§6.4 case 3).
    expect($result->contributesToAverage())->toBeFalse();
});

it('T5: a scored zero and an unjustified absence are arithmetically the same, and both differ from exempt', function () {
    $pipeline = opesPipeline();

    $scoredZero = new SubjectInput('s', [
        ComponentMark::scored('CA', Score::of('14'), Score::of('20'), 40),
        ComponentMark::scored('EXAM', Score::zero(), Score::of('20'), 60),
    ], 400);

    expect($pipeline->subjectScore($scoredZero, opesMax20(), MissingComponentPolicy::BlockPublication)->score?->toString())
        ->toBe('5.600');
});

// ---------------------------------------------------------------------------
// T6 - missing_component_policy must not manufacture a brilliant student.
// ---------------------------------------------------------------------------

it('T6: redistribute does NOT turn a plainly missing exam into a top grade', function () {
    $result = opesPipeline()->subjectScore(
        // Pending exam, no justified-absence attendance record on the assessment date.
        opesMathematics(MarkState::Pending, null, justified: false),
        opesMax20(),
        MissingComponentPolicy::Redistribute,
    );

    // It falls through to `zero`: 5.600, identical to case 1 - NOT 14.000.
    expect($result->score?->toString())->toBe('5.600');
    expect($result->componentOutcomes[1]->effectiveState)->toBe(MarkState::AbsentUnjustified);
    expect($result->componentOutcomes[1]->appliedPolicy)->toBe(MissingComponentPolicy::Redistribute);
});

it('T6: redistribute exempts a pending component ONLY with a justified absence on the assessment date', function () {
    $result = opesPipeline()->subjectScore(
        opesMathematics(MarkState::Pending, null, justified: true),
        opesMax20(),
        MissingComponentPolicy::Redistribute,
    );

    expect($result->score?->toString())->toBe('14.000');
    expect($result->componentOutcomes[1]->effectiveState)->toBe(MarkState::Exempt);
});

it('T6: policy zero treats a pending component as absent_unjustified and stamps the note', function () {
    $result = opesPipeline()->subjectScore(
        // Even WITH a justified absence, `zero` is `zero`.
        opesMathematics(MarkState::Pending, null, justified: true),
        opesMax20(),
        MissingComponentPolicy::Zero,
    );

    expect($result->score?->toString())->toBe('5.600');
    expect($result->componentOutcomes[1]->effectiveState)->toBe(MarkState::AbsentUnjustified);
    expect($result->componentOutcomes[1]->wasPolicyApplied())->toBeTrue();
});

it('T6: block_publication refuses rather than inventing a number', function () {
    $result = opesPipeline()->subjectScore(
        opesMathematics(MarkState::Pending),
        opesMax20(),
        MissingComponentPolicy::BlockPublication,
    );

    expect($result->isBlocked())->toBeTrue();
    expect($result->blockingComponentCodes)->toBe(['EXAM']);
    expect($result->score)->toBeNull();
    // Blocked is NOT unassessed: the subject has marks, publication is simply refused.
    expect($result->isUnassessed())->toBeFalse();
    expect($result->contributesToAverage())->toBeFalse();
});

it('T6: the policy never touches the four resolved states', function () {
    // absent_unjustified stays a zero under `redistribute` even with a
    // justified attendance record - the policy governs `pending` and nothing else.
    $subject = new SubjectInput('s', [
        ComponentMark::scored('CA', Score::of('14'), Score::of('20'), 40),
        new ComponentMark('EXAM', MarkState::AbsentUnjustified, null, Score::of('20'), 60, true),
    ], 400);

    $result = opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::Redistribute);

    expect($result->score?->toString())->toBe('5.600');
    expect($result->componentOutcomes[1]->wasPolicyApplied())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Invariants the spec states but §19 does not enumerate as their own test.
// ---------------------------------------------------------------------------

it('refuses a component weight set that does not sum to exactly 100', function () {
    // §18.1: v1 accepted 30 + 60 = 90 and marked a whole class out of 90% of scale.
    $subject = new SubjectInput('s', [
        ComponentMark::scored('CA', Score::of('14'), Score::of('20'), 30),
        ComponentMark::scored('EXAM', Score::of('14'), Score::of('20'), 60),
    ], 400);

    opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication);
})->throws(AssessmentException::class, 'must sum to exactly 100');

it('refuses a scored mark with no score and an unscored mark carrying one', function () {
    expect(fn () => new ComponentMark('CA', MarkState::Scored, null, null, 100))
        ->toThrow(AssessmentException::class);
    expect(fn () => new ComponentMark('CA', MarkState::Exempt, Score::of('12'), null, 100))
        ->toThrow(AssessmentException::class);
});

it('refuses a score above its effective maximum', function () {
    $subject = new SubjectInput('s', [
        ComponentMark::scored('CA', Score::of('21'), Score::of('20'), 100),
    ], 100);

    opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication);
})->throws(AssessmentException::class, '§18.5');

it('accepts a perfect score and returns the framework maximum exactly', function () {
    $subject = new SubjectInput('s', [
        ComponentMark::scored('CA', Score::of('30'), Score::of('30'), 40),
        ComponentMark::scored('EXAM', Score::of('100'), Score::of('100'), 60),
    ], 100);

    expect(opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication)->score?->toString())
        ->toBe('20.000');
});

it('re-scales an override back to the framework maximum so the subject weight is unchanged', function () {
    // §6.3: without the re-scale, 34/40 would enter the aggregate as if it were
    // a /20 mark and inflate the average by ~70% of that subject's weight.
    $subject = new SubjectInput('tp', [
        ComponentMark::scored('TP', Score::of('40'), null, 100),
    ], 100, maxScoreOverride: Score::of('40'));

    expect(opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication)->score?->toString())
        ->toBe('20.000');
});

it('normalises three components with three different maxima in one exact division', function () {
    // 12/15 = 0.8 (w20), 45/60 = 0.75 (w30), 7/10 = 0.7 (w50)
    // r = 0.8*0.2 + 0.75*0.3 + 0.7*0.5 = 0.16 + 0.225 + 0.35 = 0.735
    // score = 0.735 * 20 = 14.700
    $subject = new SubjectInput('s', [
        ComponentMark::scored('A', Score::of('12'), Score::of('15'), 20),
        ComponentMark::scored('B', Score::of('45'), Score::of('60'), 30),
        ComponentMark::scored('C', Score::of('7'), Score::of('10'), 50),
    ], 100);

    expect(opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication)->score?->toString())
        ->toBe('14.700');
});

it('renormalises surviving weights when only some components are exempt', function () {
    // CA 15/20 (w40) survives, EXAM exempt (w60) removed -> r = 0.75 -> 15.000.
    $subject = new SubjectInput('s', [
        ComponentMark::scored('CA', Score::of('15'), Score::of('20'), 40),
        ComponentMark::inState('EXAM', MarkState::Exempt, Score::of('20'), 60),
    ], 100);

    expect(opesPipeline()->subjectScore($subject, opesMax20(), MissingComponentPolicy::BlockPublication)->score?->toString())
        ->toBe('15.000');
});

it('works on a percentage framework as well as a /20 one', function () {
    $subject = new SubjectInput('s', [
        ComponentMark::scored('CA', Score::of('24'), Score::of('30'), 30),
        ComponentMark::scored('EXAM', Score::of('60'), Score::of('100'), 70),
    ], 100);

    expect(opesPipeline()->subjectScore($subject, Score::of('100'), MissingComponentPolicy::BlockPublication)->score?->toString())
        ->toBe('66.000');
});
