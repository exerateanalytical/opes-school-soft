<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Student;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\SanctionType;
use App\Modules\Welfare\Livewire\Discipline\CaseShow;
use App\Modules\Welfare\Livewire\Discipline\Index as DisciplineIndex;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineCategory;
use App\Modules\Welfare\Models\DisciplineSanction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/DisciplineTestHelpers.php';

uses(RefreshDatabase::class);

// ── Index ──────────────────────────────────────────────────────────────────

it('renders the case list with the student name, category and status pill', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $student = Student::query()->findOrFail($enrollment->student_id);
    $category = DisciplineCategory::factory()->create(['name' => 'Offence Truancy']);

    DisciplineCase::factory()->create([
        'student_id' => $student->getKey(),
        'enrollment_id' => $enrollment->getKey(),
        'discipline_category_id' => $category->getKey(),
    ]);

    Livewire::test(DisciplineIndex::class)
        ->assertSee(__('discipline.title'))
        ->assertSee(__('discipline.kpi_open'))
        ->assertSee($student->first_name)
        ->assertSee('Offence Truancy')
        ->assertSee(__('discipline.status.open'));
});

it('filters by the status tabs', function () {
    $fixture = phase8F3Fixture();
    $openEnrollment = phase8F3Enroll($fixture);
    $resolvedEnrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    Student::query()->whereKey($openEnrollment->student_id)
        ->update(['first_name' => 'Openonly', 'last_name' => 'Rowmarker']);
    Student::query()->whereKey($resolvedEnrollment->student_id)
        ->update(['first_name' => 'Resolvedonly', 'last_name' => 'Rowmarker']);

    DisciplineCase::factory()->create([
        'student_id' => $openEnrollment->student_id,
        'enrollment_id' => $openEnrollment->getKey(),
    ]);
    DisciplineCase::factory()->resolved()->create([
        'student_id' => $resolvedEnrollment->student_id,
        'enrollment_id' => $resolvedEnrollment->getKey(),
    ]);

    Livewire::test(DisciplineIndex::class)
        ->assertSee('Openonly')
        ->assertSee('Resolvedonly')
        ->call('selectStatus', 'resolved')
        ->assertSet('status', 'resolved')
        ->assertSee('Resolvedonly')
        ->assertDontSee('Openonly');
});

it('refuses the screen without discipline.view', function () {
    phase8F3Fixture();
    // Teacher holds attendance permissions but not discipline.view (plan §1).
    actingAs(phase8F3UserAs(Role::Teacher));

    Livewire::test(DisciplineIndex::class)->assertForbidden();
});

it('opens a case from the form and redirects to its file', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $category = DisciplineCategory::factory()->create();

    Livewire::test(DisciplineIndex::class)
        ->call('toggleOpenForm')
        ->set('studentId', (string) $enrollment->student_id)
        ->set('formCategoryId', (string) $category->getKey())
        ->set('occurredOn', '2026-03-10')
        ->set('description', 'Skipped the flag-raising ceremony.')
        ->call('openCase')
        ->assertHasNoErrors();

    $case = DisciplineCase::query()->firstOrFail();

    expect($case->student_id)->toBe($enrollment->student_id)
        ->and($case->enrollment_id)->toBe((int) $enrollment->getKey());
});

it('hides the Open Case action from a view-only role', function () {
    phase8F3Fixture();
    actingAs(phase8F3UserAs(Role::Principal));

    Livewire::test(DisciplineIndex::class)
        ->assertSee(__('discipline.title'))
        ->call('toggleOpenForm')
        ->assertForbidden();
});

// ── CaseShow ───────────────────────────────────────────────────────────────

it('renders one case file with incident, sanctions and lifecycle', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $student = Student::query()->findOrFail($enrollment->student_id);

    $case = DisciplineCase::factory()->create([
        'student_id' => $student->getKey(),
        'enrollment_id' => $enrollment->getKey(),
        'description' => 'Broke a laboratory beaker on purpose.',
    ]);
    DisciplineSanction::factory()->create([
        'discipline_case_id' => $case->getKey(),
        'type' => SanctionType::Detention,
    ]);

    Livewire::test(CaseShow::class, ['case' => (int) $case->getKey()])
        ->assertSee($student->first_name)
        ->assertSee('Broke a laboratory beaker on purpose.')
        ->assertSee(__('discipline.sanction.detention'))
        ->assertSee(__('discipline.status.open'));
});

it('applies a sanction from the case file, pre-filled with the ADVISORY ladder suggestion', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    Livewire::test(CaseShow::class, ['case' => (int) $case->getKey()])
        ->call('toggleSanctionForm')
        // First offence, warning-default category: the ladder pre-selects a
        // warning — and it is only a DEFAULT the human can change.
        ->assertSet('sanctionType', 'warning')
        ->set('sanctionType', 'detention')
        ->set('startsOn', '2026-03-12')
        ->set('endsOn', '2026-03-12')
        ->call('applySanction')
        ->assertHasNoErrors();

    expect(DisciplineSanction::query()->where('discipline_case_id', $case->getKey())->count())->toBe(1)
        ->and(DisciplineSanction::query()->firstOrFail()->type)->toBe(SanctionType::Detention);
});

it('acknowledges a sanction from the case file and rejects a foreign sanction id', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);
    $sanction = DisciplineSanction::factory()->create([
        'discipline_case_id' => $case->getKey(),
    ]);

    $otherCase = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);
    $foreign = DisciplineSanction::factory()->create([
        'discipline_case_id' => $otherCase->getKey(),
    ]);

    Livewire::test(CaseShow::class, ['case' => (int) $case->getKey()])
        ->call('acknowledgeSanction', (int) $foreign->getKey())
        ->assertHasErrors('sanction')
        ->call('acknowledgeSanction', (int) $sanction->getKey())
        ->assertHasNoErrors('sanction');

    expect($sanction->fresh()?->acknowledged_at)->not->toBeNull()
        ->and($foreign->fresh()?->acknowledged_at)->toBeNull();
});

it('closes a case from the file with an outcome and a note', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    Livewire::test(CaseShow::class, ['case' => (int) $case->getKey()])
        ->set('resolveOutcome', 'dismissed')
        ->set('resolutionNote', 'Witnesses cleared the student.')
        ->call('resolveCase')
        ->assertHasNoErrors();

    expect($case->fresh()?->status)->toBe(DisciplineCaseStatus::Dismissed);
});

it('refuses a case that does not exist rather than rendering an empty file', function () {
    phase8F3Fixture();
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    expect(fn () => Livewire::test(CaseShow::class, ['case' => 999999]))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
