<?php

declare(strict_types=1);

require_once __DIR__.'/PromotionTestHelpers.php';

// docs/specs/07-students.md §10.4 — the promotion rulebook: creation,
// validation, the advisory-by-default fee_clearance rule, and the
// permission gate.

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\CreateCriteriaSet;
use App\Modules\Students\Domain\CriterionType;
use App\Modules\Students\Models\PromotionCriteriaSet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('creates a versioned criteria set with ordered criteria', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: null,
        name: 'MINESEC default',
        criteria: [
            ['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => 10],
            ['type' => 'attendance_rate', 'comparator' => 'gte', 'threshold' => 80],
            ['type' => 'fee_clearance', 'comparator' => 'lte', 'threshold' => 0],
        ],
    );

    expect($set->version)->toBe(1)
        ->and($set->is_active)->toBeTrue();

    $criteria = $set->criteria()->get()->all();

    expect($criteria)->toHaveCount(3)
        ->and($criteria[0]->type)->toBe(CriterionType::AnnualAverage)
        ->and($criteria[0]->threshold)->toBe('10.000')
        ->and($criteria[0]->is_blocking)->toBeTrue()
        ->and($criteria[0]->sequence)->toBe(0)
        // §10.4: fee_clearance defaults ADVISORY even though every other
        // criterion defaults blocking.
        ->and($criteria[2]->type)->toBe(CriterionType::FeeClearance)
        ->and($criteria[2]->is_blocking)->toBeFalse();
});

it('refuses an empty criteria list', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: null,
        name: 'Empty',
        criteria: [],
    );
})->throws(ValidationException::class);

it('refuses a blocking fee_clearance criterion without the written-warning acknowledgement', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $refused = false;

    try {
        app(CreateCriteriaSet::class)->handle(
            academicYearId: (int) $fixture['year']->getKey(),
            schoolSectionId: (int) $fixture['section']->getKey(),
            classLevelId: null,
            name: 'Fee gate',
            criteria: [
                ['type' => 'fee_clearance', 'comparator' => 'lte', 'threshold' => 0, 'is_blocking' => true],
            ],
        );
    } catch (ValidationException $exception) {
        $refused = true;
        expect(json_encode($exception->errors()))->toContain('written-warning');
    }

    // A blocking fee_clearance criterion must require the acknowledgement.
    expect($refused)->toBeTrue();

    // With the acknowledgement it is accepted — the policy decision is the
    // school's to take, on the record.
    $set = app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: null,
        name: 'Fee gate acknowledged',
        criteria: [
            ['type' => 'fee_clearance', 'comparator' => 'lte', 'threshold' => 0, 'is_blocking' => true],
        ],
        acceptFeeClearanceBlockWarning: true,
    );

    expect($set->criteria()->first()?->is_blocking)->toBeTrue();
});

it('requires subject_minimum to name its subject and forbids subjects elsewhere', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    expect(fn () => app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: null,
        name: 'No subject',
        criteria: [['type' => 'subject_minimum', 'comparator' => 'gte', 'threshold' => 8]],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: null,
        name: 'Subject on average',
        criteria: [['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => 10, 'subject_id' => 1]],
    ))->toThrow(ValidationException::class);
});

it('refuses duplicate criteria of the same type and target', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: null,
        name: 'Duplicated',
        criteria: [
            ['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => 10],
            ['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => 12],
        ],
    );
})->throws(ValidationException::class);

it('rejects a class level from another section', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $foreignSection = \App\Modules\Academics\Models\SchoolSection::factory()->create();
    $foreignLevel = \App\Modules\Academics\Models\ClassLevel::factory()->create([
        'school_section_id' => $foreignSection->getKey(),
    ]);

    app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: (int) $foreignLevel->getKey(),
        name: 'Wrong section',
        criteria: [['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => 10]],
    );
})->throws(ValidationException::class);

it('is immutable once referenced by a run', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Principal));

    $set = phase8F4CriteriaSet($fixture);

    expect($set->isReferenced())->toBeFalse();

    \App\Modules\Students\Models\PromotionRun::factory()->create([
        'academic_year_id' => $fixture['year']->getKey(),
        'class_group_id' => $fixture['group']->getKey(),
        'target_academic_year_id' => $fixture['target_year']->getKey(),
        'criteria_set_id' => $set->getKey(),
    ]);

    expect($set->isReferenced())->toBeTrue();
});

it('forbids a teacher from creating criteria sets', function () {
    $fixture = phase8F4Fixture();
    actingAs(phase8F4UserAs(Role::Teacher));

    app(CreateCriteriaSet::class)->handle(
        academicYearId: (int) $fixture['year']->getKey(),
        schoolSectionId: (int) $fixture['section']->getKey(),
        classLevelId: null,
        name: 'Forbidden',
        criteria: [['type' => 'annual_average', 'comparator' => 'gte', 'threshold' => 10]],
    );
})->throws(AuthorizationException::class);

it('persists sets that survive a fresh model round-trip', function () {
    $fixture = phase8F4Fixture();

    $set = phase8F4CriteriaSet($fixture);

    $reloaded = PromotionCriteriaSet::query()->findOrFail((int) $set->getKey());

    expect($reloaded->criteria()->count())->toBe(1)
        ->and($reloaded->academic_year_id)->toBe((int) $fixture['year']->getKey());
});
