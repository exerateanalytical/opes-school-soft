<?php

declare(strict_types=1);

use App\Modules\Curriculum\Actions\PublishCurriculum;
use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Domain\CurriculumStatus;
use App\Modules\Curriculum\Livewire\Index as CurriculumIndex;
use App\Modules\Curriculum\Livewire\Show as CurriculumShow;
use App\Modules\Curriculum\Models\Curriculum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\get;

require_once __DIR__.'/CurriculumTestHelpers.php';

uses(RefreshDatabase::class);

// ── /curriculum (list) ──────────────────────────────────────────────────

it('renders the curriculum list for a holder of curriculum.view', function () {
    $manager = currManager();
    currCurriculum($manager, ['title' => 'Form 1 Mathematics Programme']);

    currUser(CurriculumPermission::VIEW);

    get('/curriculum')
        ->assertOk()
        ->assertSee('Curriculum Framework')
        ->assertSee('Form 1 Mathematics Programme');
});

it('answers 403 on the list without curriculum.view', function () {
    currUser(); // signed in, no abilities

    get('/curriculum')->assertForbidden();
});

it('filters the list by status', function () {
    $manager = currManager();
    $published = currPublishable($manager);
    app(PublishCurriculum::class)->handle((int) $published->getKey(), currActor($manager));
    currCurriculum($manager, ['title' => 'Still A Draft']);

    Livewire::test(CurriculumIndex::class)
        ->set('status', CurriculumStatus::Published->value)
        ->assertSee($published->title)
        ->assertDontSee('Still A Draft');
});

it('creates a curriculum from the list screen form', function () {
    $manager = currManager();
    $identity = currIdentity();

    Livewire::test(CurriculumIndex::class)
        ->set('createFormSubjectId', (string) $identity['subject_id'])
        ->set('createFormClassLevelId', (string) $identity['class_level_id'])
        ->set('createFormAcademicYearId', (string) $identity['academic_year_id'])
        ->set('createFormSubSystem', 'francophone')
        ->set('createFormTitle', 'Programme de 6e - Mathematiques')
        ->call('saveCurriculum')
        ->assertHasNoErrors();

    expect(Curriculum::query()->where('title', 'Programme de 6e - Mathematiques')->exists())->toBeTrue();
});

it('refuses the create form to a view-only holder', function () {
    currUser(CurriculumPermission::VIEW);

    Livewire::test(CurriculumIndex::class)
        ->call('toggleCreateForm')
        ->assertForbidden();
});

// ── /curriculum/{id} (detail) ───────────────────────────────────────────

it('renders the detail tree with units, topics and the draft banner', function () {
    $manager = currManager();
    $curriculum = currCurriculum($manager, ['title' => 'Form 2 Physics Programme']);
    $unit = currUnit($manager, $curriculum, ['title' => 'Electricity']);
    currTopic($manager, $unit, ['title' => 'Ohm\'s law', 'learning_outcome' => 'Solves simple circuits']);

    get('/curriculum/'.$curriculum->getKey())
        ->assertOk()
        ->assertSee('Form 2 Physics Programme')
        ->assertSee('Electricity')
        ->assertSee('Ohm&#039;s law', false)
        ->assertSee('Solves simple circuits')
        ->assertSee('Draft');
});

it('answers 403 on the detail without curriculum.view', function () {
    $manager = currManager();
    $curriculum = currCurriculum($manager);

    currUser();

    get('/curriculum/'.$curriculum->getKey())->assertForbidden();
});

it('answers 404 for a missing curriculum', function () {
    currUser(CurriculumPermission::VIEW);

    get('/curriculum/999999')->assertNotFound();
});

it('publishes from the banner and shows the published state', function () {
    $manager = currManager();
    $curriculum = currPublishable($manager);

    Livewire::test(CurriculumShow::class, ['curriculum' => (int) $curriculum->getKey()])
        ->call('publish');

    $curriculum->refresh();
    expect($curriculum->status)->toBe(CurriculumStatus::Published);

    get('/curriculum/'.$curriculum->getKey())
        ->assertOk()
        ->assertSee('Published')
        ->assertSee('Revise');
});

it('revises from the banner and redirects to the new draft', function () {
    $manager = currManager();
    $curriculum = currPublishable($manager);
    app(PublishCurriculum::class)->handle((int) $curriculum->getKey(), currActor($manager));

    Livewire::test(CurriculumShow::class, ['curriculum' => (int) $curriculum->getKey()])
        ->call('revise')
        ->assertRedirect();

    $draft = Curriculum::query()
        ->where('subject_id', $curriculum->subject_id)
        ->where('status', CurriculumStatus::Draft->value)
        ->firstOrFail();

    expect($draft->version)->toBe(2);
});

it('adds a unit, topic, competency and link through the detail forms', function () {
    $manager = currManager();
    $curriculum = currCurriculum($manager);

    $component = Livewire::test(CurriculumShow::class, ['curriculum' => (int) $curriculum->getKey()])
        ->call('toggleUnitForm')
        ->set('unitFormTitle', 'Numbers')
        ->call('saveUnit')
        ->assertHasNoErrors();

    $unit = $curriculum->units()->firstOrFail();

    $component
        ->call('openTopicForm', (int) $unit->getKey())
        ->set('topicFormTitle', 'Place value')
        ->set('topicFormOutcome', 'Reads and writes large numbers')
        ->call('saveTopic')
        ->assertHasNoErrors();

    $component
        ->call('selectTab', 'competencies')
        ->call('toggleCompetencyForm')
        ->set('competencyFormCode', 'COMP-N1')
        ->set('competencyFormDescriptor', 'Number sense')
        ->call('saveCompetency')
        ->assertHasNoErrors();

    $topic = $unit->topics()->firstOrFail();
    $competency = $curriculum->competencies()->firstOrFail();

    $component
        ->call('openLinkForm', (int) $topic->getKey())
        ->set('linkFormCompetencyId', (string) $competency->getKey())
        ->call('saveLink')
        ->assertHasNoErrors();

    expect($topic->competencies()->count())->toBe(1)
        ->and($topic->refresh()->learning_outcome)->toBe('Reads and writes large numbers');
});

it('refuses the write controls to a view-only holder on the detail page', function () {
    $manager = currManager();
    $curriculum = currCurriculum($manager);

    currUser(CurriculumPermission::VIEW);

    Livewire::test(CurriculumShow::class, ['curriculum' => (int) $curriculum->getKey()])
        ->call('toggleUnitForm')
        ->assertForbidden();
});
