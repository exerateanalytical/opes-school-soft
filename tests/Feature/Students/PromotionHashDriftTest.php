<?php

declare(strict_types=1);

require_once __DIR__.'/PromotionTestHelpers.php';

// docs/specs/07-students.md §10.3 — the inputs hash. Evaluated at T0,
// re-validated at apply: on drift the Action REFUSES, names the enrollments
// whose inputs changed, and offers re-evaluation. It never silently
// re-evaluates — the principal signed off on a LIST, and applying a
// different list is the defect.

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\ApplyPromotionRun;
use App\Modules\Students\Actions\EvaluatePromotionRun;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\PromotionRun;
use App\Modules\Students\Support\PromotionInputsHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('refuses to apply when a mark amendment drifts an enrollment input, naming the student', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $student = phase8F4Student($fixture, ['12.000', '13.000']);
    $bystander = phase8F4Student($fixture, ['11.000', '11.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    // Between review and apply, the student's period result is amended —
    // the exact race §10.1 describes.
    DB::table('period_results')
        ->where('enrollment_id', $student->getKey())
        ->where('assessment_period_id', $fixture['s1']->getKey())
        ->update(['general_average_rounded' => '9.000', 'general_average' => '9.000']);

    $refused = false;

    try {
        app(ApplyPromotionRun::class)->handle((int) $run->getKey());
    } catch (ValidationException $exception) {
        $refused = true;
        $message = json_encode($exception->errors());

        // The refusal NAMES the drifted enrollment and only it.
        expect($message)->toContain((string) $student->getKey())
            ->and($message)->not->toContain('enrollment(s) '.$bystander->getKey())
            ->and($message)->toContain('re-evaluate');
    }

    // Apply must refuse on inputs drift.
    expect($refused)->toBeTrue();

    // Nothing was applied: the run still awaits, every enrollment untouched.
    expect(PromotionRun::query()->findOrFail((int) $run->getKey())->status)
        ->toBe(PromotionRunStatus::Evaluated);
    expect(DB::table('enrollments')->where('id', $student->getKey())->value('status'))
        ->toBe('active');
});

it('applies cleanly after the offered re-evaluation', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    DB::table('period_results')
        ->where('enrollment_id', $student->getKey())
        ->update(['general_average_rounded' => '9.000', 'general_average' => '9.000']);

    expect(fn () => app(ApplyPromotionRun::class)->handle((int) $run->getKey()))
        ->toThrow(ValidationException::class);

    // The corrective path §10.3 offers: re-evaluate (the principal reviews
    // the NEW list), then apply.
    $run = app(EvaluatePromotionRun::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        criteriaSetId: (int) $set->getKey(),
        targetAcademicYearId: (int) $fixture['target_year']->getKey(),
    );

    $applied = app(ApplyPromotionRun::class)->handle((int) $run->getKey());

    expect($applied->status)->toBe(PromotionRunStatus::Applied);
});

it('drifts when a discipline case is opened and when an attendance summary is rebuilt', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    $hasher = app(PromotionInputsHasher::class);

    $arguments = [
        (int) $fixture['year']->getKey(),
        (int) $fixture['group']->getKey(),
        (int) $set->getKey(),
        $set->version,
        [(int) $student->getKey()],
        [(int) $student->getKey() => '12.500'],
    ];

    $baseline = $hasher->handle(...$arguments);

    // Stable: same inputs, same bytes.
    expect($hasher->handle(...$arguments)['hash'])->toBe($baseline['hash']);

    // §10.3 bullet 5: a discipline case in the year drifts the hash.
    \App\Modules\Welfare\Models\DisciplineCase::factory()->create([
        'student_id' => $student->student_id,
        'enrollment_id' => $student->getKey(),
    ]);

    $withCase = $hasher->handle(...$arguments);

    expect($withCase['hash'])->not->toBe($baseline['hash'])
        ->and($withCase['fingerprints'][(int) $student->getKey()])
        ->not->toBe($baseline['fingerprints'][(int) $student->getKey()]);

    // §10.3 bullet 4: a rebuilt attendance summary drifts it again.
    \App\Modules\Attendance\Models\AttendanceSummary::factory()->create([
        'enrollment_id' => $student->getKey(),
        'assessment_period_id' => $fixture['s1']->getKey(),
        'sessions_expected' => 40,
        'sessions_present' => 38,
    ]);

    $withSummary = $hasher->handle(...$arguments);

    expect($withSummary['hash'])->not->toBe($withCase['hash']);
});

it('drifts on publication changes without touching any enrollment fingerprint', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);
    $student = phase8F4Student($fixture, ['12.000', '13.000']);

    $hasher = app(PromotionInputsHasher::class);

    $arguments = [
        (int) $fixture['year']->getKey(),
        (int) $fixture['group']->getKey(),
        (int) $set->getKey(),
        $set->version,
        [(int) $student->getKey()],
        [(int) $student->getKey() => '12.500'],
    ];

    $baseline = $hasher->handle(...$arguments);

    \App\Modules\Assessment\Models\PeriodPublication::factory()->create([
        'assessment_period_id' => $fixture['s1']->getKey(),
        'class_group_id' => $fixture['group']->getKey(),
    ]);

    $published = $hasher->handle(...$arguments);

    // Run-level drift: the hash moves, the per-enrollment fingerprint does
    // not — which is how the refusal message knows to blame the publications
    // rather than a student.
    expect($published['hash'])->not->toBe($baseline['hash'])
        ->and($published['fingerprints'][(int) $student->getKey()])
        ->toBe($baseline['fingerprints'][(int) $student->getKey()]);
});
