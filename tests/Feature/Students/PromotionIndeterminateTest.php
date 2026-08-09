<?php

declare(strict_types=1);

require_once __DIR__.'/PromotionTestHelpers.php';

// docs/specs/07-students.md §10.4 + C5 (§9.6) end-to-end: a missing input is
// INDETERMINATE — never a silent pass, never a zero. A student with NO
// attendance registers has a NULL rate (not 0%), the criterion cannot be
// computed, and the run refuses to apply under on_indeterminate='block'.

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\ApplyPromotionRun;
use App\Modules\Students\Actions\EvaluatePromotionRun;
use App\Modules\Students\Actions\OverridePromotionDecision;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\PromotionDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('treats a NULL attendance rate as indeterminate, never as 0% (C5)', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    // attendance_rate >= 80 blocking. If the engine coerced "no register" to
    // 0%, this student would FAIL and repeat; C5 requires indeterminate.
    $set = phase8F4CriteriaSet($fixture, [
        ['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => '10.000', 'is_blocking' => true],
        ['type' => 'attendance_rate', 'comparator' => 'gte', 'threshold' => '80.000', 'is_blocking' => true],
    ]);

    // Good marks, zero attendance registers all year.
    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    /** @var PromotionDecision $decision */
    $decision = PromotionDecision::query()
        ->where('enrollment_id', $student->getKey())->firstOrFail();

    $attendance = phase8F4CriterionResult($decision, 'attendance_rate');

    expect($decision->outcome)->toBe(PromotionOutcome::Indeterminate)
        // NOT repeat — 0% would have failed the comparator; NULL refuses to answer.
        ->and($decision->outcome)->not->toBe(PromotionOutcome::Repeat)
        ->and($decision->attendance_rate)->toBeNull()
        ->and($attendance['value'])->toBeNull()
        ->and($attendance['verdict'])->toBe('indeterminate')
        // The undecided outcome maps to NO legacy decision, so the rollover
        // wizard's "undecided students" guard fires for it too.
        ->and($decision->decision)->toBeNull();
});

it('blocks apply under on_indeterminate=block, naming the students', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);

    // Never assessed: NULL annual average => indeterminate.
    $ghost = phase8F4Student($fixture, null);
    $solid = phase8F4Student($fixture, ['12.000', '13.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
        onIndeterminate: 'block',
    );

    $refused = false;

    try {
        app(ApplyPromotionRun::class)->handle((int) $run->getKey());
    } catch (ValidationException $exception) {
        $refused = true;
        $message = json_encode($exception->errors());

        expect($message)->toContain((string) $ghost->getKey())
            ->and($message)->toContain('block');
    }

    // Apply must refuse while a decision is indeterminate under block.
    expect($refused)->toBeTrue();

    // Nobody moved — including the students whose inputs were complete.
    expect(Enrollment::query()->findOrFail((int) $solid->getKey())->status)
        ->toBe(EnrollmentStatus::Active);
    expect(Enrollment::query()->where('academic_year_id', $fixture['target_year']->getKey())->count())
        ->toBe(0);
});

it('routes to manual review under on_indeterminate=manual_review and applies once every row is overridden', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);

    $ghost = phase8F4Student($fixture, null);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
        onIndeterminate: 'manual_review',
    );

    /** @var PromotionDecision $decision */
    $decision = PromotionDecision::query()
        ->where('enrollment_id', $ghost->getKey())->firstOrFail();

    expect($decision->outcome)->toBe(PromotionOutcome::ManualReview)
        ->and($decision->decision)->toBeNull();

    // Still refuses — manual review is a queue, not a verdict.
    expect(fn () => app(ApplyPromotionRun::class)->handle((int) $run->getKey()))
        ->toThrow(ValidationException::class);

    // The human decides, on the record; the computed outcome stays visible.
    $overridden = app(OverridePromotionDecision::class)->handle(
        promotionRunId: (int) $run->getKey(),
        enrollmentId: (int) $ghost->getKey(),
        outcome: PromotionOutcome::Repeat,
        reason: 'Conseil: no assessable record this year; the student repeats.',
    );

    expect($overridden->overridden)->toBeTrue()
        ->and($overridden->computed_outcome)->toBe('manual_review')
        ->and($overridden->outcome)->toBe(PromotionOutcome::Repeat)
        ->and($overridden->override_reason)->not->toBeNull()
        ->and($overridden->overridden_by)->not->toBeNull();

    $applied = app(ApplyPromotionRun::class)->handle((int) $run->getKey());

    expect($applied->status)->toBe(PromotionRunStatus::Applied);

    $next = Enrollment::query()
        ->where('student_id', $ghost->student_id)
        ->where('academic_year_id', $fixture['target_year']->getKey())
        ->firstOrFail();

    expect($next->is_repeat)->toBeTrue();
});

it('requires an override to carry a reason and a decisive outcome', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    expect(fn () => app(OverridePromotionDecision::class)->handle(
        promotionRunId: (int) $run->getKey(),
        enrollmentId: (int) $student->getKey(),
        outcome: PromotionOutcome::Repeat,
        reason: '   ',
    ))->toThrow(ValidationException::class);

    expect(fn () => app(OverridePromotionDecision::class)->handle(
        promotionRunId: (int) $run->getKey(),
        enrollmentId: (int) $student->getKey(),
        outcome: PromotionOutcome::Indeterminate,
        reason: 'Cannot decide.',
    ))->toThrow(ValidationException::class);
});
