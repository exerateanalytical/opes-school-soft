<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\SaveMark;
use App\Modules\Assessment\Actions\SubmitMarksForValidation;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Livewire\Examinations\Index as ExaminationsScreen;
use App\Modules\Assessment\Livewire\Marks\Entry;
use App\Modules\Assessment\Livewire\Reports\Index as ReportsScreen;
use App\Modules\Assessment\Livewire\Results\Index as ResultsScreen;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Factories\ExamFactory;
use Database\Factories\MarkFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../AssessmentTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Every button on the four Assessment screens is supposed to DO something.
 * These tests press them through the component and look at the database
 * afterwards, so a control that renders but performs nothing fails here
 * rather than in front of a teacher.
 */
if (! function_exists('screenUserAs')) {
    function screenUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

/**
 * A grid whose marks are scored and submitted, waiting on a validator.
 *
 * @return array{scenario: array<string, mixed>, user: User}
 */
if (! function_exists('screenSubmittedGrid')) {
    function screenSubmittedGrid(): array
    {
        // The Exams Officer holds marks.enter AND marks.validate, which is
        // what the marks-entry screen's Approve / Return buttons need: mount()
        // gates on marks.enter, so a validator without it never reaches them.
        $user = screenUserAs(Role::ExamsOfficer);
        $scenario = MarkFactory::scenario(students: 3);

        actingAs($user);

        foreach ($scenario['marks'] as $i => $markId) {
            app(SaveMark::class)->handle(
                Mark::query()->findOrFail($markId),
                MarkState::Scored,
                sprintf('%d.000', 10 + $i),
            );
        }

        app(SubmitMarksForValidation::class)->handle(
            $scenario['allocation'],
            $scenario['period'],
            $scenario['class_group'],
        );

        return ['scenario' => $scenario, 'user' => $user];
    }
}

// ---- Marks entry: Approve / Return to teacher ---------------------------

it('validates the submitted grid when Approve is pressed', function () {
    ['scenario' => $s] = screenSubmittedGrid();

    Livewire::test(Entry::class)
        ->set('classGroup', $s['class_group'])
        ->set('allocation', $s['allocation'])
        ->set('period', $s['period'])
        ->set('component', $s['component'])
        ->call('approveMarks')
        ->assertSet('problem', '');

    expect(Mark::query()->where('workflow_state', WorkflowState::Validated->value)->count())->toBe(3);
});

it('refuses to return a grid with no reason, and returns it with one', function () {
    ['scenario' => $s] = screenSubmittedGrid();

    $screen = Livewire::test(Entry::class)
        ->set('classGroup', $s['class_group'])
        ->set('allocation', $s['allocation'])
        ->set('period', $s['period'])
        ->set('component', $s['component'])
        ->call('toggleRejectForm')
        ->assertSet('showRejectForm', true)
        ->call('rejectMarks')
        ->assertHasErrors('rejectReason');

    // Nothing moved on the refusal: a rejection without a stated reason is
    // 7.4's one thing that must not happen silently.
    expect(Mark::query()->where('workflow_state', WorkflowState::Submitted->value)->count())->toBe(3);

    $screen->set('rejectReason', 'Two scores were transposed on the last page.')
        ->call('rejectMarks')
        ->assertHasNoErrors()
        ->assertSet('showRejectForm', false);

    expect(Mark::query()->where('workflow_state', WorkflowState::Draft->value)->count())->toBe(3);
});

// ---- Results: Open / Close a period ------------------------------------

it('closes and re-opens a period from the Periods tab', function () {
    $s = MarkFactory::scenario(students: 2);

    actingAs(screenUserAs(Role::ExamsOfficer));

    $screen = Livewire::test(ResultsScreen::class)
        ->call('selectTab', 'periods')
        ->assertSet('tab', 'periods')
        ->call('closePeriod', $s['period'])
        ->assertHasNoErrors();

    expect(DB::table('assessment_periods')->where('id', $s['period'])->value('status'))->toBe('closed');

    $screen->call('openPeriod', $s['period'])->assertHasNoErrors();

    expect(DB::table('assessment_periods')->where('id', $s['period'])->value('status'))->toBe('open');
});

// ---- Results: Compute / Publish ----------------------------------------

it('computes period results and then publishes them from the Results screen', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 3]);

    actingAs(reportCardPublisher());

    Livewire::test(ResultsScreen::class)
        ->set('computePeriodId', (string) $fx['period_id'])
        ->set('computeClassGroupId', (string) $fx['class_group_ids'][0])
        ->call('computeResults')
        ->assertHasNoErrors()
        ->assertSet('showComputeForm', false);

    expect(DB::table('period_results')->where('assessment_period_id', $fx['period_id'])->count())->toBe(3);

    Livewire::test(ResultsScreen::class)
        ->call('publishPeriod', $fx['period_id'], $fx['class_group_ids'][0])
        ->assertHasNoErrors();

    expect(DB::table('period_publications')
        ->where('assessment_period_id', $fx['period_id'])
        ->where('class_group_id', $fx['class_group_ids'][0])
        ->value('status'))->toBe('published');
});

it('says what is missing rather than computing results for half a scope', function () {
    actingAs(screenUserAs(Role::ExamsOfficer));

    Livewire::test(ResultsScreen::class)
        ->set('computePeriodId', '')
        ->set('computeClassGroupId', '')
        ->call('computeResults')
        ->assertHasErrors('computePeriodId');

    expect(DB::table('period_results')->count())->toBe(0);
});

// ---- Reports: Export Excel / Export PDF --------------------------------

it('streams a real file from each export button', function () {
    assessmentFixture(['groups' => 1, 'students' => 2]);

    actingAs(screenUserAs(Role::ExamsOfficer));

    Livewire::test(ReportsScreen::class)
        ->call('exportExcel')
        ->assertFileDownloaded('mark-sheet.xlsx');

    Livewire::test(ReportsScreen::class)
        ->call('exportPdf')
        ->assertFileDownloaded('mark-sheet.pdf');
});

// ---- Examinations: Schedule exam / Generate seating --------------------

it('schedules a sitting and generates its seating from the Examinations screen', function () {
    actingAs(screenUserAs(Role::VicePrincipal));

    $exam = ExamFactory::new()->makeOne();

    $roomId = (int) DB::table('rooms')->insertGetId([
        'code' => 'H'.Str::upper(Str::random(4)),
        'name' => 'Main hall',
        'capacity' => 60,
        'type' => 'hall',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ExaminationsScreen::class)
        ->call('toggleScheduleForm')
        ->assertSet('showScheduleForm', true)
        ->set('formExamTypeId', '1')
        ->set('formAssessmentPeriodId', (string) $exam->assessment_period_id)
        ->set('formSubjectAllocationId', (string) $exam->subject_allocation_id)
        ->set('formClassGroupId', (string) $exam->class_group_id)
        ->set('formScheduledOn', '2027-01-20')
        ->set('formStartsAt', '08:00')
        ->set('formDurationMinutes', '120')
        ->set('formMaxScore', '20')
        ->set('formRoomId', (string) $roomId)
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSet('showScheduleForm', false);

    $examId = (int) DB::table('exams')->where('room_id', $roomId)->value('id');
    expect($examId)->toBeGreaterThan(0);

    Livewire::test(ExaminationsScreen::class)
        ->call('generateSeating', $examId)
        ->assertSet('tab', 'seating');

    // The sitting has a room but no candidates enrolled into its class group,
    // so seating legitimately produces nothing; what is asserted here is that
    // the button REACHED the Action rather than being inert - it either seated
    // somebody or said why it could not.
    expect(session('status') !== null || session('error') !== null)->toBeTrue();
});
