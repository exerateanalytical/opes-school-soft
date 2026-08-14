<?php

declare(strict_types=1);

use App\Modules\Curriculum\Actions\AddCompetency;
use App\Modules\Curriculum\Actions\AddTopic;
use App\Modules\Curriculum\Actions\AddUnit;
use App\Modules\Curriculum\Actions\CreateCurriculum;
use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Models\Competency;
use App\Modules\Curriculum\Models\Curriculum;
use App\Modules\Curriculum\Models\CurriculumTopic;
use App\Modules\Curriculum\Models\CurriculumUnit;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Database\Factories\AcademicYearFactory;
use Database\Factories\ClassLevelFactory;
use Database\Factories\SubjectFactory;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Curriculum suites. Prefix `curr`, every helper
 * function_exists-guarded (00-core test discipline; names must never
 * collide with another agent's).
 */
if (! function_exists('currUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function currUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('currManager')) {
    /** The usual operator: holds both curriculum abilities. */
    function currManager(): User
    {
        return currUser(CurriculumPermission::VIEW, CurriculumPermission::MANAGE);
    }
}

if (! function_exists('currActor')) {
    function currActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('currIdentity')) {
    /**
     * A fresh (subject, class level, academic year) identity for a
     * curriculum, as ids.
     *
     * @return array{subject_id: int, class_level_id: int, academic_year_id: int}
     */
    function currIdentity(): array
    {
        return [
            'subject_id' => (int) SubjectFactory::new()->createOne()->getKey(),
            'class_level_id' => (int) ClassLevelFactory::new()->createOne()->getKey(),
            'academic_year_id' => (int) AcademicYearFactory::new()->createOne()->getKey(),
        ];
    }
}

if (! function_exists('currCurriculum')) {
    /**
     * A version-1 draft created through the REAL gate.
     *
     * @param  array<string, mixed>  $overrides
     */
    function currCurriculum(User $user, array $overrides = []): Curriculum
    {
        /** @var array{subject_id: int, class_level_id: int, academic_year_id: int, sub_system: string, title: string} $data */
        $data = [
            ...currIdentity(),
            'sub_system' => 'anglophone',
            'title' => 'Programme '.fake()->unique()->numberBetween(1, 999_999),
            ...$overrides,
        ];

        return app(CreateCurriculum::class)->handle($data, currActor($user));
    }
}

if (! function_exists('currUnit')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function currUnit(User $user, Curriculum $curriculum, array $overrides = []): CurriculumUnit
    {
        /** @var array{title: string} $data */
        $data = [
            'title' => 'Unit '.fake()->unique()->numberBetween(1, 999_999),
            ...$overrides,
        ];

        return app(AddUnit::class)->handle((int) $curriculum->getKey(), $data, currActor($user));
    }
}

if (! function_exists('currTopic')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function currTopic(User $user, CurriculumUnit $unit, array $overrides = []): CurriculumTopic
    {
        /** @var array{title: string} $data */
        $data = [
            'title' => 'Topic '.fake()->unique()->numberBetween(1, 999_999),
            'learning_outcome' => 'Learners can '.fake()->words(3, true).'.',
            ...$overrides,
        ];

        return app(AddTopic::class)->handle((int) $unit->getKey(), $data, currActor($user));
    }
}

if (! function_exists('currCompetency')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function currCompetency(User $user, Curriculum $curriculum, array $overrides = []): Competency
    {
        /** @var array{code: string, descriptor: string} $data */
        $data = [
            'code' => 'COMP-'.fake()->unique()->numberBetween(1, 999_999),
            'descriptor' => 'Masters '.fake()->words(3, true).'.',
            ...$overrides,
        ];

        return app(AddCompetency::class)->handle((int) $curriculum->getKey(), $data, currActor($user));
    }
}

if (! function_exists('currPublishable')) {
    /** A draft with one unit and one topic - the minimum PublishCurriculum accepts. */
    function currPublishable(User $user): Curriculum
    {
        $curriculum = currCurriculum($user);
        $unit = currUnit($user, $curriculum);
        currTopic($user, $unit);

        return $curriculum->refresh();
    }
}
