<?php

declare(strict_types=1);

use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Livewire\Marks\Entry;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Factories\MarkFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function marksUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

/**
 * A grid opened on a real scenario, as an actor who may enter its marks.
 *
 * The Exams Officer is used for the "may enter" side because 7.5 scopes a
 * plain Teacher to the allocations they are ASSIGNED, and no assignment table
 * exists yet - Mark::allocationIdsEnterableBy() is schema-guarded and returns
 * an empty set until it lands, which denies. That deny-by-default is the
 * subject of its own test below rather than something to work around here.
 *
 * @param  array<string, int|list<int>>  $scenario
 * @return \Livewire\Features\SupportTesting\Testable<Entry>
 */
function marksGrid(array $scenario): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(Entry::class, [])
        ->set('classGroup', $scenario['class_group'])
        ->set('allocation', $scenario['allocation'])
        ->set('period', $scenario['period'])
        ->set('component', $scenario['component']);
}

// ---- The screen itself ---------------------------------------------------

it('renders through the real route inside the shell', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    get('/marks')->assertOk()
        ->assertSee('OPES')
        ->assertSee(__('opes.assessment_screen.title'));
});

it('403s on the route for a role without marks.enter', function () {
    actingAs(marksUserAs(Role::Librarian));

    get('/marks')->assertForbidden();
});

it('forbids reaching the component directly without marks.enter', function () {
    actingAs(marksUserAs(Role::Librarian));

    Livewire::test(Entry::class)->assertForbidden();
});

it('asks for a scope rather than rendering an empty grid', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    Livewire::test(Entry::class)
        ->assertSee(__('opes.assessment_screen.choose_scope'));
});

it('lists every student of the class group with the mark state, not only a number', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 3);

    $grid = marksGrid($scenario);

    // 6.4 made visible: the four enterable states are controls on the screen,
    // so an absent child and a zero can be told apart at entry time.
    $grid->assertSee(__('opes.assessment_screen.col_state'))
        ->assertSee(__('opes.assessment_screen.state_absent_unjustified'))
        ->assertSee(__('opes.assessment_screen.state_absent_justified'))
        ->assertSee(__('opes.assessment_screen.state_exempt'))
        ->assertSee(__('opes.assessment_screen.state_pending'));
});

it('drives the live footer and KPI strip from the grid, with no round trip', function () {
    // 17's live footer: entered / pending counts, the class mean so far, and
    // out-of-range warnings. All four are Alpine expressions over the local
    // buffer, so they update as the teacher types rather than on a request.
    actingAs(marksUserAs(Role::ExamsOfficer));

    $grid = marksGrid(MarkFactory::scenario(students: 3));

    $grid->assertSee(__('opes.assessment_screen.kpi_entered'))
        ->assertSee(__('opes.assessment_screen.kpi_pending'))
        ->assertSee(__('opes.assessment_screen.kpi_class_mean'))
        ->assertSee(__('opes.assessment_screen.kpi_out_of_range'))
        ->assertSeeHtml('x-text="enteredCount"')
        ->assertSeeHtml('x-text="pendingCount"')
        ->assertSeeHtml('x-text="rangeCount"')
        // The mean renders an em dash while nothing is entered - never 0.
        // "No mark yet" and "the class averaged zero" are different facts.
        ->assertSeeHtml("x-text=\"classMean ?? '—'\"");
});

// ---- T21: one request for the whole grid ---------------------------------

it('T21: a batch save of 62 changed rows issues exactly one request', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 62);

    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];
    expect($markIds)->toHaveCount(62);

    $rows = [];
    foreach ($markIds as $i => $markId) {
        $rows[] = [
            'mark_id' => $markId,
            'version' => 1,
            'state' => MarkState::Scored->value,
            'score' => (string) (5 + ($i % 15)),
            'comment' => null,
        ];
    }

    $auditsBefore = DB::table('audit_logs')->count();

    // ONE Livewire call carrying all 62 rows. The grid's cells hold no
    // `wire:` binding at all (asserted below), so this is the ONLY request the
    // browser makes for the whole afternoon's typing.
    marksGrid($scenario)
        ->call('saveBatch', $rows)
        ->assertSet('savedCount', 62)
        ->assertSet('conflicts', []);

    // All 62 landed...
    expect(Mark::query()->whereIn('id', $markIds)->where('state', MarkState::Scored->value)->count())
        ->toBe(62)
        // ...in ONE transaction, evidenced by ONE batch audit entry.
        // A per-cell save would have written 62.
        ->and(DB::table('audit_logs')->count() - $auditsBefore)->toBe(1);
});

it('T21: no cell carries a wire: binding, so there is no round trip per keystroke', function () {
    // The other half of T21. 62 students x 2 components at one request per
    // cell is 124 requests; the ABSENCE of a per-cell binding is what makes
    // the single batch above the only path, so it is asserted, not assumed.
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 3);

    $html = marksGrid($scenario)->html();

    $gridStart = strpos($html, '<tbody');
    $gridEnd = strpos($html, '</tbody>');

    expect($gridStart)->not->toBeFalse()->and($gridEnd)->not->toBeFalse();

    $body = substr($html, (int) $gridStart, (int) $gridEnd - (int) $gridStart);

    expect($body)->not->toContain('wire:model')
        ->and($body)->not->toContain('wire:click')
        ->and($body)->not->toContain('wire:change')
        // ...and it IS wired to Alpine, so the cells are not simply inert.
        ->and($body)->toContain('x-model="row.score"');
});

it('sends nothing and says so when the grid has not changed', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 3);

    marksGrid($scenario)
        ->call('saveBatch', [])
        ->assertSet('savedCount', 0)
        ->assertSee(__('opes.assessment_screen.nothing_changed'));
});

// ---- T16: the optimistic-lock conflict, made legible ----------------------

it('T16: surfaces a concurrent change with the other party value and name', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 2);
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];
    $markId = $markIds[0];

    // Somebody else got there first, so the row is now at version 2.
    $other = marksUserAs(Role::ClassMaster);
    $other->forceFill(['name' => 'Mme Ngo Bertille'])->save();

    DB::table('marks')->where('id', $markId)->update([
        'score' => '14.000',
        'state' => MarkState::Scored->value,
        'entered_by' => $other->id,
        'entered_at' => now(),
        'version' => 2,
    ]);

    $grid = marksGrid($scenario)->call('saveBatch', [[
        'mark_id' => $markId,
        'version' => 1, // what this teacher loaded
        'state' => MarkState::Scored->value,
        'score' => '9.000',
        'comment' => null,
    ]]);

    $grid->assertSet('savedCount', 0)
        // Whose value it is, and what it is - not a raw exception and not a
        // silent overwrite.
        ->assertSee('Mme Ngo Bertille')
        ->assertSee('14')
        ->assertSee(__('opes.assessment_screen.conflict_set_to'))
        ->assertSee(__('opes.assessment_screen.conflict_explainer'));

    // Nothing of theirs was overwritten either.
    expect(DB::table('marks')->where('id', $markId)->value('score'))->toBe('14.000');
});

// ---- 6.4: the state is enterable, and an absence is not a zero -----------

it('records an unexcused absence as a state, with no score', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 1);
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];

    marksGrid($scenario)->call('saveBatch', [[
        'mark_id' => $markIds[0],
        'version' => 1,
        'state' => MarkState::AbsentUnjustified->value,
        'score' => null,
        'comment' => null,
    ]])->assertSet('savedCount', 1);

    $mark = Mark::query()->findOrFail($markIds[0]);

    expect($mark->state)->toBe(MarkState::AbsentUnjustified)
        // Invariant 4. An unexcused absence contributes a ratio of zero at
        // composition; it is NOT stored as a score of zero, because a zero is
        // a mark somebody earned.
        ->and($mark->score)->toBeNull();
});

it('refuses a certified absence with no reason, and takes it with one', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 1);
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];

    // 6.4: the justification flag is worth 8.40 points out of 20 in one
    // subject, so it is a controlled field and the screen surfaces the refusal
    // rather than swallowing it.
    marksGrid($scenario)->call('saveBatch', [[
        'mark_id' => $markIds[0],
        'version' => 1,
        'state' => MarkState::AbsentJustified->value,
        'score' => null,
        'comment' => null,
    ]])->assertSet('savedCount', 0)->assertSet('problem', fn (string $p): bool => $p !== '');

    marksGrid($scenario)->call('saveBatch', [[
        'mark_id' => $markIds[0],
        'version' => 1,
        'state' => MarkState::AbsentJustified->value,
        'score' => null,
        'comment' => 'Medical certificate on file, 12/01.',
    ]])->assertSet('savedCount', 1);

    expect(Mark::query()->findOrFail($markIds[0])->state)->toBe(MarkState::AbsentJustified);
});

it('surfaces an out-of-range mark as a sentence naming the maximum', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 1, maxScore: '20.000');
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];

    $grid = marksGrid($scenario)->call('saveBatch', [[
        'mark_id' => $markIds[0],
        'version' => 1,
        'state' => MarkState::Scored->value,
        'score' => '24.000',
        'comment' => null,
    ]]);

    $grid->assertSet('savedCount', 0)->assertSee('20');

    expect(Mark::query()->findOrFail($markIds[0])->state)->toBe(MarkState::Pending);
});

it('refuses a payload whose state is not a mark state', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 1);
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];

    marksGrid($scenario)->call('saveBatch', [[
        'mark_id' => $markIds[0],
        'version' => 1,
        'state' => 'excellent',
        'score' => '18.000',
        'comment' => null,
    ]])->assertSet('savedCount', 0)
        ->assertSee(__('opes.assessment_screen.errors.bad_state', ['state' => 'excellent']));
});

// ---- T22: deny by default, at the SCREEN, not only in the Action ---------

it('T22: a teacher assigned to nothing sees no allocation and no grid', function () {
    // 7.5's predicate is "assigned teacher OR active delegation". With neither
    // record present the answer is DENY - never "allow because we cannot tell
    // yet" - and the screen must land on that answer too, because a teacher
    // READING another class's marks is a privacy breach, not merely an
    // authorisation miss.
    actingAs(marksUserAs(Role::Teacher));

    $scenario = MarkFactory::scenario(students: 3);

    Livewire::test(Entry::class)
        ->assertSee(__('opes.assessment_screen.choose_scope'))
        // The allocation the scenario built is not even OFFERED: the selector
        // is derived from the marks this actor may enter, so it can never
        // present a scope the grid would then refuse.
        ->assertDontSeeHtml('value="'.$scenario['allocation'].'"')
        ->assertDontSeeHtml('value="'.$scenario['class_group'].'"');
});

it('T22: a crafted query string naming another allocation is refused at the route', function () {
    // The scope arrives as URL state (17's selector is #[Url]), so this is the
    // shape the attack actually takes: a teacher who holds `marks.enter`
    // pastes a colleague's URL. mount() re-checks 7.5's predicate and refuses,
    // rather than rendering an empty grid - which would read as "this class
    // has no marks", a different and wrong statement.
    actingAs(marksUserAs(Role::Teacher));

    $scenario = MarkFactory::scenario(students: 3);

    get('/marks?classGroup='.$scenario['class_group']
        .'&allocation='.$scenario['allocation']
        .'&period='.$scenario['period']
        .'&component='.$scenario['component'])
        ->assertForbidden();
});

it('T22: choosing another allocation from the selector is refused at the screen', function () {
    actingAs(marksUserAs(Role::Teacher));

    $scenario = MarkFactory::scenario(students: 1);
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];

    // The refusal is at the SCREEN, not only inside the Action: the component
    // re-checks the moment the scope changes, so the grid is never populated
    // for an allocation this teacher is not entitled to read.
    Livewire::test(Entry::class)
        ->set('allocation', $scenario['allocation'])
        ->assertForbidden();

    expect(Mark::query()->findOrFail($markIds[0])->state)->toBe(MarkState::Pending);
});

// ---- 17: submission is separate from saving ------------------------------

it('does not submit as a side effect of saving', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 2);
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];

    $rows = [];
    foreach ($markIds as $markId) {
        $rows[] = [
            'mark_id' => $markId,
            'version' => 1,
            'state' => MarkState::Scored->value,
            'score' => '12.000',
            'comment' => null,
        ];
    }

    marksGrid($scenario)->call('saveBatch', $rows)->assertSet('savedCount', 2);

    // A teacher saves a half-finished grid all afternoon without declaring it
    // finished (17).
    expect(Mark::query()->whereIn('id', $markIds)
        ->where('workflow_state', WorkflowState::Draft->value)->count())->toBe(2);
});

it('submits the grid for validation as its own explicit action', function () {
    actingAs(marksUserAs(Role::ExamsOfficer));

    $scenario = MarkFactory::scenario(students: 2);
    /** @var list<int> $markIds */
    $markIds = $scenario['marks'];

    $rows = [];
    foreach ($markIds as $markId) {
        $rows[] = [
            'mark_id' => $markId,
            'version' => 1,
            'state' => MarkState::Scored->value,
            'score' => '12.000',
            'comment' => null,
        ];
    }

    $grid = marksGrid($scenario);
    $grid->call('saveBatch', $rows)->call('submitForValidation');

    expect(Mark::query()->whereIn('id', $markIds)
        ->where('workflow_state', WorkflowState::Submitted->value)->count())->toBe(2);
});
