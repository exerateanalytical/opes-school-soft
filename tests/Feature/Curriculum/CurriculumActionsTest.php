<?php

declare(strict_types=1);

use App\Modules\Curriculum\Actions\AddCompetency;
use App\Modules\Curriculum\Actions\AddTopic;
use App\Modules\Curriculum\Actions\AddUnit;
use App\Modules\Curriculum\Actions\CreateCurriculum;
use App\Modules\Curriculum\Actions\LinkTopicCompetency;
use App\Modules\Curriculum\Actions\PublishCurriculum;
use App\Modules\Curriculum\Actions\ReviseCurriculum;
use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Domain\CurriculumStatus;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/CurriculumTestHelpers.php';

uses(RefreshDatabase::class);

// ── CreateCurriculum ────────────────────────────────────────────────────

it('creates a version-1 draft through the gate', function () {
    $user = currManager();

    $curriculum = currCurriculum($user, ['title' => 'Form 1 Mathematics Programme']);

    expect($curriculum->exists)->toBeTrue()
        ->and($curriculum->title)->toBe('Form 1 Mathematics Programme')
        ->and($curriculum->version)->toBe(1)
        ->and($curriculum->status)->toBe(CurriculumStatus::Draft)
        ->and($curriculum->published_at)->toBeNull()
        ->and($curriculum->published_by)->toBeNull();
});

it('refuses creation without curriculum.manage', function () {
    currUser(CurriculumPermission::VIEW); // signed in, view only

    app(CreateCurriculum::class)->handle([
        ...currIdentity(),
        'sub_system' => 'anglophone',
        'title' => 'Refused Programme',
    ], Actor::system());
})->throws(AuthorizationException::class);

it('rejects an unknown sub-system', function () {
    $user = currManager();

    expect(fn () => currCurriculum($user, ['sub_system' => 'bilingual']))
        ->toThrow(ValidationException::class);
});

it('rejects a missing referenced subject', function () {
    $user = currManager();

    expect(fn () => currCurriculum($user, ['subject_id' => 999_999]))
        ->toThrow(ValidationException::class);
});

it('refuses a second root curriculum for the same identity', function () {
    $user = currManager();
    $first = currCurriculum($user);

    expect(fn () => currCurriculum($user, [
        'subject_id' => $first->subject_id,
        'class_level_id' => $first->class_level_id,
        'academic_year_id' => $first->academic_year_id,
        'sub_system' => $first->sub_system->value,
    ]))->toThrow(DomainException::class, 'revise it');
});

it('allows the same subject and level to differ by sub-system', function () {
    $user = currManager();
    $first = currCurriculum($user, ['sub_system' => 'anglophone']);

    $second = currCurriculum($user, [
        'subject_id' => $first->subject_id,
        'class_level_id' => $first->class_level_id,
        'academic_year_id' => $first->academic_year_id,
        'sub_system' => 'francophone',
    ]);

    expect($second->exists)->toBeTrue()
        ->and($second->version)->toBe(1);
});

// ── AddUnit / AddTopic ──────────────────────────────────────────────────

it('appends units in sequence order', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);

    $one = currUnit($user, $curriculum, ['title' => 'Numbers']);
    $two = currUnit($user, $curriculum, ['title' => 'Algebra']);

    expect($one->sequence)->toBe(1)
        ->and($two->sequence)->toBe(2)
        ->and($curriculum->units()->count())->toBe(2);
});

it('appends topics in sequence order within their unit', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    $unit = currUnit($user, $curriculum);
    $other = currUnit($user, $curriculum);

    $one = currTopic($user, $unit, ['title' => 'Place value']);
    $two = currTopic($user, $unit, ['title' => 'Rounding']);
    $elsewhere = currTopic($user, $other);

    expect($one->sequence)->toBe(1)
        ->and($two->sequence)->toBe(2)
        // Sequences are per unit, not per curriculum.
        ->and($elsewhere->sequence)->toBe(1)
        ->and($one->learning_outcome)->not->toBeNull();
});

it('refuses AddUnit without curriculum.manage', function () {
    $manager = currManager();
    $curriculum = currCurriculum($manager);

    currUser(CurriculumPermission::VIEW);

    app(AddUnit::class)->handle((int) $curriculum->getKey(), ['title' => 'Refused'], Actor::system());
})->throws(AuthorizationException::class);

it('refuses adding a unit to a published curriculum', function () {
    $user = currManager();
    $curriculum = currPublishable($user);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect(fn () => currUnit($user, $curriculum))
        ->toThrow(DomainException::class, 'published and locked');
});

it('refuses adding a topic to a published curriculum', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    $unit = currUnit($user, $curriculum);
    currTopic($user, $unit);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect(fn () => currTopic($user, $unit))
        ->toThrow(DomainException::class, 'published and locked');
});

// ── AddCompetency ───────────────────────────────────────────────────────

it('adds a competency and rejects a duplicate code on the same version', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);

    $competency = currCompetency($user, $curriculum, ['code' => 'COMP-A1']);

    expect($competency->code)->toBe('COMP-A1');

    expect(fn () => currCompetency($user, $curriculum, ['code' => 'COMP-A1']))
        ->toThrow(ValidationException::class);
});

it('refuses adding a competency to a published curriculum', function () {
    $user = currManager();
    $curriculum = currPublishable($user);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect(fn () => currCompetency($user, $curriculum))
        ->toThrow(DomainException::class, 'published and locked');
});

// ── LinkTopicCompetency ─────────────────────────────────────────────────

it('links a topic to a competency of its own curriculum', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    $unit = currUnit($user, $curriculum);
    $topic = currTopic($user, $unit);
    $competency = currCompetency($user, $curriculum);

    app(LinkTopicCompetency::class)->handle((int) $topic->getKey(), (int) $competency->getKey(), currActor($user));

    expect($topic->competencies()->count())->toBe(1)
        ->and($competency->topics()->count())->toBe(1);
});

it('refuses a cross-curriculum link', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    $unit = currUnit($user, $curriculum);
    $topic = currTopic($user, $unit);

    $foreign = currCurriculum($user);
    $foreignCompetency = currCompetency($user, $foreign);

    expect(fn () => app(LinkTopicCompetency::class)->handle(
        (int) $topic->getKey(),
        (int) $foreignCompetency->getKey(),
        currActor($user),
    ))->toThrow(DomainException::class, 'own curriculum');
});

it('refuses a duplicate link', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    $topic = currTopic($user, currUnit($user, $curriculum));
    $competency = currCompetency($user, $curriculum);

    app(LinkTopicCompetency::class)->handle((int) $topic->getKey(), (int) $competency->getKey(), currActor($user));

    expect(fn () => app(LinkTopicCompetency::class)->handle(
        (int) $topic->getKey(),
        (int) $competency->getKey(),
        currActor($user),
    ))->toThrow(DomainException::class, 'already linked');
});

it('refuses linking on a published curriculum', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    $unit = currUnit($user, $curriculum);
    $topic = currTopic($user, $unit);
    $competency = currCompetency($user, $curriculum);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect(fn () => app(LinkTopicCompetency::class)->handle(
        (int) $topic->getKey(),
        (int) $competency->getKey(),
        currActor($user),
    ))->toThrow(DomainException::class, 'published and locked');
});

// ── PublishCurriculum ───────────────────────────────────────────────────

it('publishes a draft, stamping published_at and published_by', function () {
    $user = currManager();
    $curriculum = currPublishable($user);

    $published = app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect($published->status)->toBe(CurriculumStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->published_by)->toBe((int) $user->getKey());
});

it('refuses publishing twice', function () {
    $user = currManager();
    $curriculum = currPublishable($user);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect(fn () => app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user)))
        ->toThrow(DomainException::class, 'already published');
});

it('refuses publishing an empty curriculum', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);

    expect(fn () => app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user)))
        ->toThrow(DomainException::class, 'at least one unit');
});

it('refuses publishing a curriculum whose units hold no topic', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    currUnit($user, $curriculum);

    expect(fn () => app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user)))
        ->toThrow(DomainException::class, 'without any topic');
});

it('refuses publish without curriculum.manage', function () {
    $manager = currManager();
    $curriculum = currPublishable($manager);

    currUser(CurriculumPermission::VIEW);

    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), Actor::system());
})->throws(AuthorizationException::class);

// ── ReviseCurriculum ────────────────────────────────────────────────────

it('clones a published version as a version+1 draft with the full tree', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);
    $unitA = currUnit($user, $curriculum, ['title' => 'Unit A']);
    $unitB = currUnit($user, $curriculum, ['title' => 'Unit B']);
    $topicA1 = currTopic($user, $unitA, ['title' => 'Topic A1', 'learning_outcome' => 'Outcome A1']);
    currTopic($user, $unitA, ['title' => 'Topic A2']);
    currTopic($user, $unitB, ['title' => 'Topic B1']);
    $competency = currCompetency($user, $curriculum, ['code' => 'COMP-R1', 'descriptor' => 'Reasoning']);
    app(LinkTopicCompetency::class)->handle((int) $topicA1->getKey(), (int) $competency->getKey(), currActor($user));
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    $draft = app(ReviseCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect($draft->version)->toBe(2)
        ->and($draft->status)->toBe(CurriculumStatus::Draft)
        ->and($draft->published_at)->toBeNull()
        ->and($draft->subject_id)->toBe($curriculum->subject_id)
        ->and($draft->class_level_id)->toBe($curriculum->class_level_id)
        ->and($draft->academic_year_id)->toBe($curriculum->academic_year_id)
        ->and($draft->sub_system)->toBe($curriculum->sub_system);

    // The tree is CLONED: new rows, same content, same order.
    $units = $draft->units()->get()->all();
    expect($units)->toHaveCount(2)
        ->and($units[0]->title)->toBe('Unit A')
        ->and($units[0]->sequence)->toBe(1)
        ->and($units[1]->title)->toBe('Unit B')
        ->and((int) $units[0]->getKey())->not->toBe((int) $unitA->getKey());

    $clonedTopics = $units[0]->topics()->get()->all();
    expect($clonedTopics)->toHaveCount(2)
        ->and($clonedTopics[0]->title)->toBe('Topic A1')
        ->and($clonedTopics[0]->learning_outcome)->toBe('Outcome A1');

    // Competencies cloned and the topic link remapped onto the clones.
    $clonedCompetencies = $draft->competencies()->get()->all();
    expect($clonedCompetencies)->toHaveCount(1)
        ->and($clonedCompetencies[0]->code)->toBe('COMP-R1')
        ->and((int) $clonedCompetencies[0]->getKey())->not->toBe((int) $competency->getKey());

    expect($clonedTopics[0]->competencies()->pluck('competencies.id')->all())
        ->toBe([(int) $clonedCompetencies[0]->getKey()]);

    // The SOURCE version is untouched: still published, still linked to its
    // own competency rows.
    $curriculum->refresh();
    expect($curriculum->status)->toBe(CurriculumStatus::Published)
        ->and($curriculum->units()->count())->toBe(2)
        ->and((int) DB::table('competency_curriculum_topic')->count())->toBe(2);
});

it('refuses revising a draft', function () {
    $user = currManager();
    $curriculum = currCurriculum($user);

    expect(fn () => app(ReviseCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user)))
        ->toThrow(DomainException::class, 'Only a published curriculum');
});

it('refuses opening a second draft for the same identity', function () {
    $user = currManager();
    $curriculum = currPublishable($user);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    app(ReviseCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    expect(fn () => app(ReviseCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user)))
        ->toThrow(DomainException::class, 'draft revision of this curriculum already exists');
});

it('numbers a third version after the second is published', function () {
    $user = currManager();
    $curriculum = currPublishable($user);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    $second = app(ReviseCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));
    app(PublishCurriculum::class)->handle((int) $second->getKey(), currActor($user));

    $third = app(ReviseCurriculum::class)->handle((int) $second->getKey(), currActor($user));

    expect($second->version)->toBe(2)
        ->and($third->version)->toBe(3);
});

// ── Audit ───────────────────────────────────────────────────────────────

it('writes an audit entry for every curriculum write', function () {
    $user = currManager();
    $curriculum = currPublishable($user);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($user));

    // create + unit + topic + publish = 4 Curriculum-module entries.
    expect((int) DB::table('audit_logs')->where('module', 'Curriculum')->count())->toBe(4);
});
