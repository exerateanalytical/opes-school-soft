<?php

declare(strict_types=1);

require_once __DIR__.'/PromotionTestHelpers.php';

// docs/specs/07-students.md §10.2/§10.4/§10.5 — EvaluatePromotionRun: the
// persisted evaluation, its per-criterion explanations, the annual average
// coming from THE assessment service, and the one-run-per-(group, year)
// constraint.

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\EvaluatePromotionRun;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\PromotionRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('evaluates a class group into persisted, explained decisions', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);

    // 12 and 13 compose (MINESEC mean of leaf periods) to 12.500 — pass.
    $passing = phase8F4Student($fixture, ['12.000', '13.000']);
    // 8 and 9 compose to 8.500 — a blocking fail => repeat.
    $failing = phase8F4Student($fixture, ['8.000', '9.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    expect($run->status)->toBe(PromotionRunStatus::Evaluated)
        ->and($run->inputs_hash)->toHaveLength(64)
        ->and($run->evaluated_at)->not->toBeNull()
        ->and($run->evaluated_by)->not->toBeNull();

    /** @var PromotionDecision $passDecision */
    $passDecision = PromotionDecision::query()
        ->where('enrollment_id', $passing->getKey())->firstOrFail();

    // The annual average is the composed value of the SAME service the
    // report card uses — 12.500, not any locally invented mean.
    expect($passDecision->outcome)->toBe(PromotionOutcome::Promote)
        ->and($passDecision->computed_outcome)->toBe('promote')
        ->and($passDecision->annual_average)->toBe('12.500')
        ->and($passDecision->decision)->toBe(PromotionDecision::DECISION_PROMOTED)
        ->and($passDecision->target_class_level_id)->toBe((int) $fixture['level2']->getKey())
        ->and($passDecision->overridden)->toBeFalse();

    // criteria_results explains itself: value, threshold, comparator, verdict.
    $results = $passDecision->criteria_results['criteria'] ?? [];
    expect($results)->toHaveCount(1)
        ->and($results[0]['type'])->toBe('annual_average')
        ->and($results[0]['comparator'])->toBe('gte')
        ->and($results[0]['threshold'])->toBe('10.000')
        ->and($results[0]['value'])->toBe('12.500')
        ->and($results[0]['verdict'])->toBe('pass');

    /** @var PromotionDecision $failDecision */
    $failDecision = PromotionDecision::query()
        ->where('enrollment_id', $failing->getKey())->firstOrFail();

    expect($failDecision->outcome)->toBe(PromotionOutcome::Repeat)
        ->and($failDecision->decision)->toBe(PromotionDecision::DECISION_REPEAT)
        ->and($failDecision->annual_average)->toBe('8.500')
        ->and($failDecision->target_class_level_id)->toBe((int) $fixture['level1']->getKey());
});

it('graduates a passing student in an exam class instead of promoting', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $finalist = phase8F4Student($fixture, ['14.000', '15.000'], $fixture['exam_group']);

    app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['exam_group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    /** @var PromotionDecision $decision */
    $decision = PromotionDecision::query()
        ->where('enrollment_id', $finalist->getKey())->firstOrFail();

    expect($decision->outcome)->toBe(PromotionOutcome::Graduate)
        ->and($decision->decision)->toBe(PromotionDecision::DECISION_GRADUATED)
        ->and($decision->target_class_level_id)->toBeNull();
});

it('keeps one run per (class group, year): re-evaluation updates in place', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    phase8F4Student($fixture, ['12.000', '13.000']);

    $first = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    $second = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    expect($second->getKey())->toBe($first->getKey())
        ->and(PromotionRun::query()->count())->toBe(1)
        // Same inputs => same canonical hash, byte for byte.
        ->and($second->inputs_hash)->toBe($first->inputs_hash);
});

it('refuses to evaluate an empty class group', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);

    app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );
})->throws(ValidationException::class);

it('refuses a criteria set from another year or section', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    phase8F4Student($fixture);

    $foreignSet = \App\Modules\Students\Models\PromotionCriteriaSet::factory()->create([
        'academic_year_id' => $fixture['target_year']->getKey(),
        'school_section_id' => $fixture['section']->getKey(),
    ]);

    app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $foreignSet->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );
})->throws(ValidationException::class);

it('records fee_clearance as advisory: a balance never blocks by default', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture, [
        ['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => '10.000', 'is_blocking' => true],
        // Advisory: is_blocking = false is §10.4's default for this type.
        ['type' => 'fee_clearance', 'comparator' => 'lte', 'threshold' => '0.000', 'is_blocking' => false],
    ]);

    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    // An outstanding invoice: 50,000 XAF issued, nothing allocated.
    $invoice = \Database\Factories\InvoiceFactory::new()->createOne([
        'enrollment_id' => $student->getKey(),
        'student_id' => $student->student_id,
        'academic_year_id' => $fixture['year']->getKey(),
        'status' => 'issued',
    ]);
    \Illuminate\Support\Facades\DB::table('invoice_lines')->insert([
        'invoice_id' => $invoice->getKey(),
        'line_no' => 1,
        'description' => 'Tuition',
        'collection_basis' => 'own_revenue',
        'quantity' => 1,
        'unit_amount' => 50_000,
        'amount' => 50_000,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    /** @var PromotionDecision $decision */
    $decision = PromotionDecision::query()
        ->where('enrollment_id', $student->getKey())->firstOrFail();

    // The unpaid balance FAILED its criterion — visibly, in the explanation —
    // but the outcome is still promote because the criterion is advisory.
    $feeResult = phase8F4CriterionResult($decision, 'fee_clearance');

    expect($feeResult['verdict'])->toBe('fail')
        ->and((float) $feeResult['value'])->toBeGreaterThan(0)
        ->and($decision->outcome)->toBe(PromotionOutcome::Promote);
});

it('forbids a teacher from evaluating', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Teacher));

    $set = phase8F4CriteriaSet($fixture);
    phase8F4Student($fixture);

    app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );
})->throws(AuthorizationException::class);
