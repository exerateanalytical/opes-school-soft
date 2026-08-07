<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\CreateClassLevel;
use App\Modules\Academics\Actions\CreateSchoolSection;
use App\Modules\Academics\Actions\CreateStream;
use App\Modules\Academics\Actions\DeactivateStream;
use App\Modules\Academics\Actions\UpdateClassLevel;
use App\Modules\Academics\Domain\EducationLevel;
use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Academics\Domain\Track;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Stream;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// Local helper - deliberately NOT named userAs(): Pest file-level functions
// share one global namespace, and UserManagementTest already declares userAs().
function structureUserAs(bool $canManageAcademics = true): User
{
    Permission::findOrCreate('academics.manage', 'web');
    $user = User::factory()->create();

    if ($canManageAcademics) {
        $user->givePermissionTo('academics.manage');
    }

    return $user->fresh() ?? $user;
}

it('creates a school section and audits it', function () {
    actingAs(structureUserAs());

    $section = app(CreateSchoolSection::class)->handle(
        educationLevel: EducationLevel::SecondaryFirstCycle,
        track: Track::General,
        subSystem: SubSystem::Anglophone,
        name: 'Secondary General (Anglophone)',
        nameFr: 'Secondaire general (anglophone)',
        matriculeFormat: 'OPS-{YY}-{SEQ:5}',
    );

    expect($section->education_level)->toBe(EducationLevel::SecondaryFirstCycle)
        ->and($section->is_active)->toBeTrue();

    expect(
        AuditLog::query()->where('module', 'Academics')->where('action', 'created')->count()
    )->toBe(1);
});

it('enforces the (education_level, track, sub_system) uniqueness triple', function () {
    actingAs(structureUserAs());
    $action = app(CreateSchoolSection::class);

    $action->handle(
        educationLevel: EducationLevel::SecondaryFirstCycle,
        track: Track::General,
        subSystem: SubSystem::Anglophone,
        name: 'First', nameFr: 'Premiere', matriculeFormat: 'X-{SEQ}',
    );

    // Same triple again must be refused by uq_section_level_track_system.
    expect(fn () => $action->handle(
        educationLevel: EducationLevel::SecondaryFirstCycle,
        track: Track::General,
        subSystem: SubSystem::Anglophone,
        name: 'Duplicate', nameFr: 'Doublon', matriculeFormat: 'X-{SEQ}',
    ))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);

    // A triple differing in ONE dimension is a different section and is fine.
    $action->handle(
        educationLevel: EducationLevel::SecondaryFirstCycle,
        track: Track::General,
        subSystem: SubSystem::Francophone,
        name: 'Franco', nameFr: 'Franco', matriculeFormat: 'X-{SEQ}',
    );

    expect(SchoolSection::query()->count())->toBe(2);
});

it('refuses a duplicate class level code within a section with a domain exception', function () {
    actingAs(structureUserAs());
    $section = SchoolSection::factory()->create();
    $action = app(CreateClassLevel::class);

    $action->handle($section, 'F1', 'Form 1', '6e', 1);

    expect(fn () => $action->handle($section, 'F1', 'Form One Again', '6e bis', 2))
        ->toThrow(DomainException::class, "already exists in section");
});

it('allows the same class level code in two different sections', function () {
    actingAs(structureUserAs());
    $anglo = SchoolSection::factory()->create();
    $franco = SchoolSection::factory()->create();
    $action = app(CreateClassLevel::class);

    $action->handle($anglo, 'F1', 'Form 1', 'Form 1', 1);
    $action->handle($franco, 'F1', '6e', '6e', 1);

    expect(ClassLevel::query()->where('code', 'F1')->count())->toBe(2);
});

it('updates a class level and audits before and after', function () {
    actingAs(structureUserAs());
    $level = ClassLevel::factory()->create(['code' => 'F5', 'is_exam_class' => false]);

    app(UpdateClassLevel::class)->handle(
        level: $level,
        code: 'F5',
        name: 'Form 5',
        nameFr: '3e',
        orderIndex: 5,
        isExamClass: true,
    );

    expect($level->refresh()->is_exam_class)->toBeTrue();

    $entry = AuditLog::query()
        ->where('module', 'Academics')->where('action', 'updated')->firstOrFail();

    expect($entry->before)->toMatchArray(['is_exam_class' => false])
        ->and($entry->after)->toMatchArray(['is_exam_class' => true]);
});

it('round-trips the stream subject basket through the JSON column', function () {
    actingAs(structureUserAs());
    $section = SchoolSection::factory()->create();
    $basket = ['MATH', 'PHYS', 'CHEM', 'F-MATH'];

    $stream = app(CreateStream::class)->handle(
        section: $section,
        code: 'S1',
        name: 'Science 1',
        nameFr: 'Serie C',
        subjectBasket: $basket,
    );

    // Fresh query, not the in-memory model: the point is that the DATABASE
    // holds the basket that drives the ranking cohort.
    $reloaded = Stream::query()->findOrFail((int) $stream->getKey());

    expect($reloaded->subject_basket)->toBe($basket);
});

it('deactivates a stream and audits the flip', function () {
    actingAs(structureUserAs());
    $stream = Stream::factory()->create();

    app(DeactivateStream::class)->handle($stream);

    expect($stream->refresh()->is_active)->toBeFalse();
    expect(
        AuditLog::query()->where('module', 'Academics')->where('action', 'updated')->count()
    )->toBe(1);
});

it('rejects an actor without academics.manage', function () {
    actingAs(structureUserAs(canManageAcademics: false));

    app(CreateSchoolSection::class)->handle(
        educationLevel: EducationLevel::Primary,
        track: Track::General,
        subSystem: SubSystem::Anglophone,
        name: 'Primary', nameFr: 'Primaire', matriculeFormat: 'X-{SEQ}',
    );
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

it('rejects an unauthenticated caller', function () {
    app(DeactivateStream::class)->handle(Stream::factory()->create());
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);
