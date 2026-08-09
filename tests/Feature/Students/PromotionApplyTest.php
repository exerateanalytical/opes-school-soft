<?php

declare(strict_types=1);

require_once __DIR__.'/PromotionTestHelpers.php';

// docs/specs/07-students.md §10.6 — ApplyPromotionRun, steps 1-8: one
// transaction, segment close, year-end completion, next-year enrollments,
// graduate path, the UNIQUE backstop against double application, events
// after commit.

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\ApplyPromotionRun;
use App\Modules\Students\Actions\EvaluatePromotionRun;
use App\Modules\Students\Actions\OverridePromotionDecision;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\PromotionRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('applies a run: closes the year, creates next-year enrollments, emits events after commit', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $promoted = phase8F4Student($fixture, ['12.000', '13.000']);
    $repeater = phase8F4Student($fixture, ['8.000', '9.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    $events = [];
    Event::listen('student.promoted', function (array $payload) use (&$events): void {
        $events['promoted'][] = $payload;
    });
    Event::listen('student.repeated', function (array $payload) use (&$events): void {
        $events['repeated'][] = $payload;
    });

    $applied = app(ApplyPromotionRun::class)->handle((int) $run->getKey());

    expect($applied->status)->toBe(PromotionRunStatus::Applied)
        ->and($applied->applied_at)->not->toBeNull()
        ->and($applied->applied_by)->not->toBeNull();

    // Step 4: the outgoing enrollments are completed on the year's last day
    // and their segments closed — draft->lines->post of the student world.
    foreach ([$promoted, $repeater] as $enrollment) {
        $fresh = Enrollment::query()->findOrFail((int) $enrollment->getKey());

        expect($fresh->status)->toBe(EnrollmentStatus::Completed)
            ->and($fresh->left_on?->toDateString())->toBe('2027-06-30');

        expect(DB::table('enrollment_segments')
            ->where('enrollment_id', $enrollment->getKey())
            ->whereNull('ends_on')
            ->count())->toBe(0);
    }

    // Step 5: next-year enrollments — deferred class group => PENDING with
    // no segment, left for the rollover wizard; is_repeat marks the repeater.
    $promotedNext = Enrollment::query()
        ->where('student_id', $promoted->student_id)
        ->where('academic_year_id', $fixture['target_year']->getKey())
        ->firstOrFail();

    expect($promotedNext->status)->toBe(EnrollmentStatus::Pending)
        ->and($promotedNext->is_repeat)->toBeFalse()
        ->and($promotedNext->enrollment_type->value)->toBe('returning')
        ->and($promotedNext->class_level_id)->toBe((int) $fixture['level2']->getKey())
        ->and(DB::table('enrollment_segments')->where('enrollment_id', $promotedNext->getKey())->count())->toBe(0);

    $repeaterNext = Enrollment::query()
        ->where('student_id', $repeater->student_id)
        ->where('academic_year_id', $fixture['target_year']->getKey())
        ->firstOrFail();

    expect($repeaterNext->is_repeat)->toBeTrue()
        ->and($repeaterNext->class_level_id)->toBe((int) $fixture['level1']->getKey());

    // Decisions point at what they created.
    expect(PromotionDecision::query()->where('enrollment_id', $promoted->getKey())->value('applied_enrollment_id'))
        ->toBe((int) $promotedNext->getKey());

    // Step 8: events fired after commit.
    expect($events['promoted'] ?? [])->toHaveCount(1)
        ->and($events['repeated'] ?? [])->toHaveCount(1)
        ->and($events['promoted'][0]['student_id'])->toBe($promoted->student_id);
});

it('activates the next-year enrollment when an override names a target class group', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    app(OverridePromotionDecision::class)->handle(
        promotionRunId: (int) $run->getKey(),
        enrollmentId: (int) $student->getKey(),
        outcome: PromotionOutcome::Promote,
        reason: 'Conseil placed the student directly into the target group.',
        targetClassGroupId: (int) $fixture['target_group']->getKey(),
    );

    // The override moved the run to under_review; §10.6's conditional UPDATE
    // accepts a reviewed list.
    app(ApplyPromotionRun::class)->handle((int) $run->getKey());

    $next = Enrollment::query()
        ->where('student_id', $student->student_id)
        ->where('academic_year_id', $fixture['target_year']->getKey())
        ->firstOrFail();

    expect($next->status)->toBe(EnrollmentStatus::Active);

    $segment = DB::table('enrollment_segments')->where('enrollment_id', $next->getKey())->first();

    expect($segment)->not->toBeNull();

    if ($segment !== null) {
        expect((int) $segment->class_group_id)->toBe((int) $fixture['target_group']->getKey())
            ->and((string) $segment->starts_on)->toBe('2027-09-01')
            ->and($segment->ends_on)->toBeNull();
    }
});

it('drives a graduate to Student.status graduated with no next-year enrollment', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $finalist = phase8F4Student($fixture, ['14.000', '15.000'], $fixture['exam_group']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['exam_group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    app(ApplyPromotionRun::class)->handle((int) $run->getKey());

    // Step 6: no new enrollment; §3.2's derivation says graduated.
    expect(Enrollment::query()
        ->where('student_id', $finalist->student_id)
        ->where('academic_year_id', $fixture['target_year']->getKey())
        ->count())->toBe(0);

    expect(DB::table('students')->where('id', $finalist->student_id)->value('status'))
        ->toBe('graduated');
});

it('aborts a second apply at the conditional status flip', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    phase8F4Student($fixture, ['12.000', '13.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    app(ApplyPromotionRun::class)->handle((int) $run->getKey());

    // §10.6 step 2: the conditional UPDATE matches 0 rows on the replay and
    // the Action aborts — before touching any enrollment.
    $aborted = false;

    try {
        app(ApplyPromotionRun::class)->handle((int) $run->getKey());
    } catch (ValidationException $exception) {
        $aborted = true;
        expect(json_encode($exception->errors()))->toContain('cannot be applied');
    }

    expect($aborted)->toBeTrue();

    // Still exactly one next-year enrollment per student.
    expect(Enrollment::query()->where('academic_year_id', $fixture['target_year']->getKey())->count())->toBe(1);
});

it('hits the enrollment UNIQUE backstop and rolls back whole when a student already holds a target-year enrollment', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    // Someone manually enrolled the student into the target year between
    // review and apply. §4.3's uq_enrollment_active_year is the backstop.
    Enrollment::factory()->create([
        'student_id' => $student->student_id,
        'academic_year_id' => $fixture['target_year']->getKey(),
        'class_level_id' => $fixture['level2']->getKey(),
        'school_section_id' => $fixture['section']->getKey(),
        'enrolled_on' => '2027-09-01',
    ]);

    expect(fn () => app(ApplyPromotionRun::class)->handle((int) $run->getKey()))
        ->toThrow(ValidationException::class);

    // The WHOLE transaction rolled back: the outgoing enrollment is still
    // active and the run is not applied — no partial application (§10.1).
    expect(Enrollment::query()->findOrFail((int) $student->getKey())->status)
        ->toBe(EnrollmentStatus::Active);
    expect(PromotionRun::query()->findOrFail((int) $run->getKey())->status)
        ->not->toBe(PromotionRunStatus::Applied);
});

it('forbids apply without promotion.apply and evaluation of an applied run', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    phase8F4Student($fixture, ['12.000', '13.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    // A Registrar holds neither promotion permission.
    actingAs(phase8F4UserAs(Role::Registrar));

    expect(fn () => app(ApplyPromotionRun::class)->handle((int) $run->getKey()))
        ->toThrow(AuthorizationException::class);

    actingAs(phase8F4UserAs(Role::Principal));
    app(ApplyPromotionRun::class)->handle((int) $run->getKey());

    // §10.6: an applied run is never silently re-evaluated.
    expect(fn () => app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    ))->toThrow(ValidationException::class);
});
