<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\CreateFramework;
use App\Modules\Assessment\Actions\DefineComponents;
use App\Modules\Assessment\Domain\ComponentKind;
use App\Modules\Assessment\Domain\FrameworkFamily;
use App\Modules\Assessment\Domain\MissingComponentPolicy;
use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Database\Factories\AssessmentFrameworkFactory;
use Database\Factories\SubjectAllocationFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// Distinct names per Pest file: file-level functions share one global
// namespace and a duplicate is a fatal redeclaration.
function frameworkUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

/**
 * @return array{section: int, year: int}
 */
function frameworkPrerequisites(): array
{
    return [
        'section' => AssessmentFrameworkFactory::schoolSectionId(),
        'year' => SubjectAllocationFactory::academicYearId(),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function familyAPayload(array $overrides = []): array
{
    ['section' => $section, 'year' => $year] = frameworkPrerequisites();

    return array_merge([
        'school_section_id' => $section,
        'academic_year_id' => $year,
        'code' => 'MINESEC_FR_SEC1',
        'name' => 'MINESEC Francophone Secondary',
        'name_fr' => 'Secondaire francophone MINESEC',
        'family' => 'A',
        'assessment_mode' => 'numeric',
        'max_score' => '20.000',
        'pass_score' => '10.000',
        'requires_per_lesson_attendance' => true,
    ], $overrides);
}

it('creates a Family A framework and audits it', function () {
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = app(CreateFramework::class)->handle(familyAPayload(), $user->toAuditActor());

    expect($framework->family)->toBe(FrameworkFamily::A);
    expect($framework->max_score)->toBe('20.000');
    expect($framework->pass_score)->toBe('10.000');
    // The spec default, not something the caller had to remember to pass.
    expect($framework->missing_component_policy)->toBe(MissingComponentPolicy::Redistribute);

    $entry = AuditLog::query()->where('module', 'Assessment')->latest('id')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->auditable_type)->toBe(AssessmentFramework::class);
});

it('refuses to create a framework without assessment.configure', function () {
    // A Teacher may enter marks and may not shape the rules those marks are
    // judged by. Deny-by-default, 00-core 9.2.
    $user = frameworkUserAs(Role::Teacher);
    actingAs($user);

    expect($user->can(Permission::MarksEnter->value))->toBeTrue();
    expect($user->can(Permission::AssessmentConfigure->value))->toBeFalse();

    app(CreateFramework::class)->handle(familyAPayload(), $user->toAuditActor());
})->throws(AuthorizationException::class);

it('refuses a Family F framework that is not competency-only', function () {
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    app(CreateFramework::class)->handle(
        familyAPayload(['family' => 'F', 'assessment_mode' => 'numeric', 'requires_per_lesson_attendance' => false]),
        $user->toAuditActor(),
    );
})->throws(DomainException::class, 'Family F is competency-only');

it('refuses a Family F framework that carries a rank', function () {
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    app(CreateFramework::class)->handle(
        familyAPayload([
            'family' => 'F',
            'assessment_mode' => 'competency',
            'uses_rank' => true,
            'requires_per_lesson_attendance' => false,
        ]),
        $user->toAuditActor(),
    );
})->throws(DomainException::class, 'no coefficients and no rank');

it('refuses a MINESEC framework without per-lesson attendance', function () {
    // 01-assessment 14: the MINESEC bulletin's attendance block can only be
    // filled from per-lesson registers. Discovering that in June is too late.
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    app(CreateFramework::class)->handle(
        familyAPayload(['requires_per_lesson_attendance' => false]),
        $user->toAuditActor(),
    );
})->throws(DomainException::class, 'requires per-lesson attendance');

it('refuses a pass score above the maximum', function () {
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    app(CreateFramework::class)->handle(
        familyAPayload(['pass_score' => '21.000']),
        $user->toAuditActor(),
    );
})->throws(DomainException::class, '0 < pass_score <= max_score');

it('refuses a score precision finer than Score can represent', function () {
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    app(CreateFramework::class)->handle(
        familyAPayload(['score_precision' => 4]),
        $user->toAuditActor(),
    );
})->throws(DomainException::class, 'score_precision must be 0..3');

it('allows only one default framework per section per year', function () {
    ['section' => $section, 'year' => $year] = frameworkPrerequisites();

    AssessmentFramework::factory()->create([
        'school_section_id' => $section,
        'academic_year_id' => $year,
        'is_default' => true,
    ]);

    // 00-core 10.1's generated-column pattern: MySQL 8 has no partial index,
    // so the second default collides on uq_assessment_frameworks_default.
    expect(fn () => AssessmentFramework::factory()->create([
        'school_section_id' => $section,
        'academic_year_id' => $year,
        'is_default' => true,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('lets a non-default framework sit beside the default one', function () {
    ['section' => $section, 'year' => $year] = frameworkPrerequisites();

    AssessmentFramework::factory()->create([
        'school_section_id' => $section,
        'academic_year_id' => $year,
        'is_default' => true,
    ]);

    AssessmentFramework::factory()->create([
        'school_section_id' => $section,
        'academic_year_id' => $year,
        'is_default' => false,
    ]);

    expect(AssessmentFramework::query()->count())->toBe(2);
});

it('defines components carrying their own maxima', function () {
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    $components = app(DefineComponents::class)->handle((int) $framework->getKey(), [
        ['code' => 'CA', 'name' => 'Continuous Assessment', 'name_fr' => 'Contrôle continu', 'max_score' => '30.000'],
        ['code' => 'EXAM', 'name' => 'Examination', 'name_fr' => 'Composition', 'max_score' => '100.000'],
    ], $user->toAuditActor());

    expect($components)->toHaveCount(2);

    // The 2.1 counterexample's inputs: 24/30 and 60/100. Neither is measured
    // against the framework's 20, which is the whole correction.
    expect($components[0]->max_score)->toBe('30.000');
    expect($components[1]->max_score)->toBe('100.000');
    expect($framework->max_score)->toBe('20.000');
});

it('upserts components by code rather than replacing them', function () {
    // A Mark row points at a component id, so delete-and-recreate would orphan
    // every mark ever entered against it (00-core 10.5).
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();
    $payload = [['code' => 'CA', 'name' => 'CA', 'name_fr' => 'CC', 'max_score' => '30.000']];

    $first = app(DefineComponents::class)->handle((int) $framework->getKey(), $payload, $user->toAuditActor());

    $payload[0]['max_score'] = '20.000';
    $second = app(DefineComponents::class)->handle((int) $framework->getKey(), $payload, $user->toAuditActor());

    expect($second[0]->getKey())->toBe($first[0]->getKey());
    expect(AssessmentComponent::query()->count())->toBe(1);
    expect($second[0]->max_score)->toBe('20.000');
});

it('refuses a duplicate component code and a zero maximum', function () {
    $user = frameworkUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();
    $id = (int) $framework->getKey();
    $actor = $user->toAuditActor();

    expect(fn () => app(DefineComponents::class)->handle($id, [
        ['code' => 'CA', 'name' => 'A', 'name_fr' => 'A', 'max_score' => '30.000'],
        ['code' => 'CA', 'name' => 'B', 'name_fr' => 'B', 'max_score' => '20.000'],
    ], $actor))->toThrow(DomainException::class, 'declared twice');

    // A zero maximum is what stage 2 would divide by.
    expect(fn () => app(DefineComponents::class)->handle($id, [
        ['code' => 'TP', 'name' => 'TP', 'name_fr' => 'TP', 'max_score' => '0.000'],
    ], $actor))->toThrow(DomainException::class, 'maximum of zero');

    expect(fn () => app(DefineComponents::class)->handle($id, [], $actor))
        ->toThrow(DomainException::class, 'at least one component');
});

it('classifies a component from its code and answers Other for anything else', function () {
    $ca = AssessmentComponent::factory()->create(['code' => 'CA']);
    $exam = AssessmentComponent::factory()->create(['code' => 'EXAM', 'framework_id' => $ca->framework_id]);
    $house = AssessmentComponent::factory()->create(['code' => 'DEVOIR_MAISON', 'framework_id' => $ca->framework_id]);

    expect($ca->kind())->toBe(ComponentKind::ContinuousAssessment);
    expect($exam->kind())->toBe(ComponentKind::Examination);
    expect($exam->kind()->isExamination())->toBeTrue();
    // A school naming its own column is not an error and gets no behaviour.
    expect($house->kind())->toBe(ComponentKind::Other);
});

it('gives every role the marks permissions 00-core 9.1 assigns it', function () {
    $matrix = [
        [Role::Teacher, [Permission::MarksEnter], [Permission::MarksValidate, Permission::AssessmentConfigure, Permission::ReportsPublish]],
        [Role::ClassMaster, [Permission::MarksEnter, Permission::MarksValidate], [Permission::AssessmentConfigure, Permission::ReportsPublish]],
        [Role::ExamsOfficer, [Permission::MarksEnter, Permission::MarksValidate, Permission::AssessmentConfigure, Permission::ReportsPublish], []],
        [Role::VicePrincipal, [Permission::MarksValidate, Permission::AssessmentConfigure, Permission::ReportsPublish], [Permission::MarksEnter]],
        [Role::Principal, [Permission::ReportsPublish], [Permission::MarksEnter, Permission::MarksValidate, Permission::AssessmentConfigure]],
    ];

    foreach ($matrix as [$role, $granted, $withheld]) {
        $held = $role->defaultPermissions();

        foreach ($granted as $permission) {
            expect(in_array($permission, $held, true))
                ->toBeTrue("{$role->value} lacks {$permission->value}");
        }

        foreach ($withheld as $permission) {
            expect(in_array($permission, $held, true))
                ->toBeFalse("{$role->value} wrongly holds {$permission->value}");
        }
    }
});

it('keeps the actor on the audit entry', function () {
    $user = frameworkUserAs(Role::ExamsOfficer);
    actingAs($user);

    app(CreateFramework::class)->handle(familyAPayload(), $user->toAuditActor());

    $entry = AuditLog::query()->where('module', 'Assessment')->latest('id')->first();
    expect($entry?->actor_id)->toBe($user->id);
    expect(Actor::system()->id)->toBeNull();
});
