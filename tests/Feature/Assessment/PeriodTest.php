<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\CloseAssessmentPeriod;
use App\Modules\Assessment\Actions\OpenAssessmentPeriod;
use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Database\Factories\AssessmentFrameworkFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function periodUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

/**
 * Column-filtered against the live schema, so a nullable column added or
 * removed by another module's author never breaks this fixture.
 *
 * @param  array<string, mixed>  $values
 */
function periodInsert(string $table, array $values): int
{
    $columns = array_flip(Schema::getColumnListing($table));

    return (int) DB::table($table)->insertGetId(array_intersect_key($values + [
        'created_at' => now(),
        'updated_at' => now(),
    ], $columns));
}

/**
 * A whole year in miniature: one framework with two components, a
 * year -> term -> two-sequence tree, one allocation requiring both components,
 * and three active enrolments plus one withdrawn.
 *
 * Prerequisite rows for other modules are raw, schema-independent DB writes -
 * the same discipline the Phase 2 factories use, so this suite does not depend
 * on code being written concurrently by other authors.
 *
 * @return array{framework: AssessmentFramework, year: int, level: int, sequence1: int, sequence2: int, term: int, root: int, allocation: int, enrollments: list<int>, components: list<int>}
 */
function periodFixture(): array
{
    $section = AssessmentFrameworkFactory::schoolSectionId();

    $yearId = periodInsert('academic_years', [
        'code' => 'AY-'.uniqid(),
        'name' => '2026/2027',
        'name_fr' => '2026/2027',
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-07-31',
        'is_current' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $levelId = periodInsert('class_levels', [
        'school_section_id' => $section,
        'code' => strtoupper(uniqid('CL')),
        'name' => 'Form 1',
        'name_fr' => '6e',
        'order_index' => 1,
        'is_exam_class' => 0,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $framework = AssessmentFramework::factory()->create([
        'school_section_id' => $section,
        'academic_year_id' => $yearId,
    ]);

    $ca = AssessmentComponent::factory()->create(['framework_id' => $framework->id]);
    $exam = AssessmentComponent::factory()->exam()->create(['framework_id' => $framework->id]);

    $period = static function (array $attributes) use ($yearId, $framework): int {
        return periodInsert('assessment_periods', array_merge([
            'academic_year_id' => $yearId,
            'framework_id' => $framework->id,
            'parent_id' => null,
            'weight' => '1.0000',
            'counts_toward_parent' => 1,
            'is_reporting_period' => 0,
            'status' => 'planned',
            'order_index' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    };

    $root = $period([
        'type' => 'year', 'code' => 'YEAR', 'name' => 'Year', 'name_fr' => 'Année',
        'starts_on' => '2026-09-01', 'ends_on' => '2027-07-31',
    ]);

    $term = $period([
        'type' => 'trimestre', 'code' => 'T1', 'name' => 'Term 1', 'name_fr' => 'Trimestre 1',
        'parent_id' => $root, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-15',
        'is_reporting_period' => 1,
    ]);

    $sequence1 = $period([
        'type' => 'sequence', 'code' => 'S1', 'name' => 'Sequence 1', 'name_fr' => 'Séquence 1',
        'parent_id' => $term, 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-31',
    ]);

    $sequence2 = $period([
        'type' => 'sequence', 'code' => 'S2', 'name' => 'Sequence 2', 'name_fr' => 'Séquence 2',
        'parent_id' => $term, 'starts_on' => '2026-11-01', 'ends_on' => '2026-12-15',
        'order_index' => 2,
    ]);

    $subjectId = periodInsert('subjects', [
        'code' => strtoupper(uniqid('SUB')),
        'name' => 'Mathematics',
        'name_fr' => 'Mathématiques',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $allocation = periodInsert('subject_allocations', [
        'academic_year_id' => $yearId,
        'class_level_id' => $levelId,
        'stream_id' => 0,
        'subject_id' => $subjectId,
        'coefficient' => '4.00',
        'required_components' => json_encode([$ca->id, $exam->id]),
        'is_optional' => 0,
        'counts_toward_average' => 1,
        'is_active' => 1,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $enrollments = [];

    foreach (['active', 'active', 'active', 'withdrawn'] as $i => $status) {
        $studentId = periodInsert('students', [
            'matricule' => 'OS-26-'.strtoupper(uniqid()),
            'matricule_is_official' => 1,
            'admission_no' => 'ADM/'.strtoupper(uniqid()),
            'first_name' => 'Pupil',
            'last_name' => 'Number '.$i,
            'date_of_birth' => '2012-04-11',
            'place_of_birth' => 'Bamenda',
            'gender' => 'male',
            'nationality' => 'CM',
            'status' => 'active',
            'is_archived' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = periodInsert('enrollments', [
            'student_id' => $studentId,
            'academic_year_id' => $yearId,
            'class_level_id' => $levelId,
            'stream_id' => null,
            'school_section_id' => $section,
            'status' => $status,
            'is_repeat' => 0,
            'enrollment_type' => 'new',
            'enrolled_on' => '2026-09-05',
            'left_on' => $status === 'withdrawn' ? '2026-10-01' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($status === 'active') {
            $enrollments[] = $id;
        }
    }

    return [
        'framework' => $framework,
        'year' => $yearId,
        'level' => $levelId,
        'root' => $root,
        'term' => $term,
        'sequence1' => $sequence1,
        'sequence2' => $sequence2,
        'allocation' => $allocation,
        'enrollments' => $enrollments,
        'components' => [(int) $ca->id, (int) $exam->id],
    ];
}

// --- T2 ---------------------------------------------------------------------

it('T2 materialises one pending mark per enrolment, allocation and required component', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    $created = app(OpenAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());

    // 3 active enrolments x 1 allocation x 2 required components. The
    // withdrawn student is absent: they are not on the roll for this period.
    expect($created)->toBe(6);
    expect(DB::table('marks')->count())->toBe(6);

    $rows = DB::table('marks')->where('assessment_period_id', $f['sequence1'])->get();

    foreach ($rows as $row) {
        // "No row" is not a state; `pending` is (01-assessment 6.2).
        expect($row->state)->toBe('pending');
        expect($row->workflow_state)->toBe('draft');
        expect($row->score)->toBeNull();
        expect((int) $row->subject_allocation_id)->toBe($f['allocation']);
        expect($f['components'])->toContain((int) $row->component_id);
    }

    expect(DB::table('assessment_periods')->where('id', $f['sequence1'])->value('status'))->toBe('open');
});

it('T2 re-running creates no duplicates', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();
    $action = app(OpenAssessmentPeriod::class);

    expect($action->handle($f['sequence1'], $user->toAuditActor()))->toBe(6);

    // Re-running is NORMAL, not exceptional: a late enrolment, a transfer in,
    // a newly effective allocation or a component added to
    // required_components all call it again.
    expect($action->handle($f['sequence1'], $user->toAuditActor()))->toBe(0);
    expect(DB::table('marks')->count())->toBe(6);
});

it('T2 re-running picks up a late enrolment without disturbing entered marks', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();
    $action = app(OpenAssessmentPeriod::class);
    $action->handle($f['sequence1'], $user->toAuditActor());

    // A teacher has already entered a mark.
    $markId = (int) DB::table('marks')->value('id');
    DB::table('marks')->where('id', $markId)->update(['state' => 'scored', 'score' => '14.500']);

    $studentId = periodInsert('students', [
        'matricule' => 'OS-26-'.strtoupper(uniqid()),
        'matricule_is_official' => 1,
        'admission_no' => 'ADM/'.strtoupper(uniqid()),
        'first_name' => 'Late', 'last_name' => 'Arrival',
        'date_of_birth' => '2012-04-11', 'place_of_birth' => 'Buea',
        'gender' => 'female', 'nationality' => 'CM', 'status' => 'active',
        'is_archived' => 0,
    ]);

    periodInsert('enrollments', [
        'student_id' => $studentId,
        'academic_year_id' => $f['year'],
        'class_level_id' => $f['level'],
        'stream_id' => null,
        'school_section_id' => AssessmentFrameworkFactory::schoolSectionId(),
        'status' => 'active',
        'is_repeat' => 0,
        'enrollment_type' => 'transfer_in',
        'enrolled_on' => '2026-10-02',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($action->handle($f['sequence1'], $user->toAuditActor()))->toBe(2);
    expect(DB::table('marks')->count())->toBe(8);

    // INSERT IGNORE, not upsert: the entered score is untouched.
    $mark = DB::table('marks')->where('id', $markId)->first();
    expect($mark?->state)->toBe('scored');
    expect($mark?->score)->toBe('14.500');
});

it('materialises only the named enrolments when given a batch', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    $created = app(OpenAssessmentPeriod::class)->handle(
        $f['sequence1'],
        $user->toAuditActor(),
        [$f['enrollments'][0]],
    );

    expect($created)->toBe(2);
});

it('refuses to materialise against a period that has children', function () {
    // Marks attach only to leaf periods (01-assessment 4.1); a mark on the
    // term would be double-counted by the composition that reads its children.
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    app(OpenAssessmentPeriod::class)->handle($f['term'], $user->toAuditActor());
})->throws(DomainException::class, 'has child periods');

it('refuses to materialise a period with no framework', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();
    DB::table('assessment_periods')->where('id', $f['sequence1'])->update(['framework_id' => null]);

    app(OpenAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());
})->throws(DomainException::class, 'no assessment framework');

it('skips an allocation that is not yet in effect for the period', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    // Effective only from the second sequence.
    DB::table('subject_allocations')->where('id', $f['allocation'])
        ->update(['effective_from_period_id' => $f['sequence2']]);

    expect(app(OpenAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor()))->toBe(0);
    expect(app(OpenAssessmentPeriod::class)->handle($f['sequence2'], $user->toAuditActor()))->toBe(6);
});

it('ignores a required component id that belongs to no active component', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    // Nothing in the database stops required_components naming a component
    // from another framework, so it is filtered rather than trusted.
    DB::table('subject_allocations')->where('id', $f['allocation'])
        ->update(['required_components' => json_encode([$f['components'][0], 999999])]);

    expect(app(OpenAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor()))->toBe(3);
});

it('refuses to open a period without assessment.configure', function () {
    $user = periodUserAs(Role::Teacher);
    actingAs($user);

    $f = periodFixture();

    app(OpenAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());
})->throws(AuthorizationException::class);

// --- T18 --------------------------------------------------------------------

it('T18 accepts a 00:30 local save on the closing date', function () {
    // Cameroon is UTC+1 with no DST, so at 00:30 local the UTC clock still
    // reads the previous day. Evaluating the window in UTC would reject a
    // teacher finishing a class set on closing night (01-assessment 7.6).
    $utcInstant = Carbon::parse('2026-12-04 23:30:00', 'UTC');

    expect($utcInstant->copy()->setTimezone(BusinessDate::TIMEZONE)->format('Y-m-d H:i'))
        ->toBe('2026-12-05 00:30');

    $opens = '2026-12-05 00:00:00';
    $closes = '2026-12-05 23:59:59';

    expect(OpenAssessmentPeriod::entryWindowIsOpen($opens, $closes, $utcInstant))->toBeTrue();

    // The naive comparison this exists to prevent: read as UTC wall-clock,
    // the same instant is 23:30 on the 4th and falls before the window opens.
    expect($utcInstant->lessThan(Carbon::parse($opens, 'UTC')))->toBeTrue();
});

it('T18 refuses a save before the window opens and after it closes', function () {
    $opens = '2026-12-01 08:00:00';
    $closes = '2026-12-05 18:00:00';

    $early = Carbon::parse('2026-12-01 07:59:59', BusinessDate::TIMEZONE);
    $late = Carbon::parse('2026-12-05 18:00:01', BusinessDate::TIMEZONE);
    $inside = Carbon::parse('2026-12-03 12:00:00', BusinessDate::TIMEZONE);

    expect(OpenAssessmentPeriod::entryWindowIsOpen($opens, $closes, $early))->toBeFalse();
    expect(OpenAssessmentPeriod::entryWindowIsOpen($opens, $closes, $late))->toBeFalse();
    expect(OpenAssessmentPeriod::entryWindowIsOpen($opens, $closes, $inside))->toBeTrue();

    // A NULL bound is unbounded on that side.
    expect(OpenAssessmentPeriod::entryWindowIsOpen(null, null, $late))->toBeTrue();

    // 7.6: the refusal prints the window, so an operator can tell whether
    // they are early, late, or looking at the wrong period.
    expect(fn () => OpenAssessmentPeriod::assertEntryWindowOpen($opens, $closes, $late))
        ->toThrow(DomainException::class, '2026-12-05 18:00:00');
});

it('stores the entry window as local wall clock when opening', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    app(OpenAssessmentPeriod::class)->handle(
        $f['sequence1'],
        $user->toAuditActor(),
        null,
        '2026-09-01 08:00:00',
        '2026-10-31 23:59:59',
    );

    $period = DB::table('assessment_periods')->where('id', $f['sequence1'])->first();

    expect((string) $period?->marks_entry_opens_at)->toContain('2026-09-01 08:00:00');
    expect(OpenAssessmentPeriod::entryWindowIsOpen(
        (string) $period?->marks_entry_opens_at,
        (string) $period?->marks_entry_closes_at,
        Carbon::parse('2026-10-31 23:30:00', BusinessDate::TIMEZONE),
    ))->toBeTrue();
});

it('refuses a window that closes before it opens', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    app(OpenAssessmentPeriod::class)->handle(
        $f['sequence1'],
        $user->toAuditActor(),
        null,
        '2026-10-31 08:00:00',
        '2026-09-01 08:00:00',
    );
})->throws(DomainException::class, 'closes');

// --- closing ----------------------------------------------------------------

it('closes a period, reporting the pending marks rather than resolving them', function () {
    // 6.4's missing_component_policy decides what happens to a pending mark,
    // and that decision belongs to the framework - not to whoever pressed
    // "close". Zeroing them here would turn a data-entry gap into a child's
    // zero with nothing in the audit trail saying who chose it.
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();
    app(OpenAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());

    DB::table('marks')->limit(2)->update(['state' => 'scored', 'score' => '12.000']);

    $pending = app(CloseAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());

    expect($pending)->toBe(4);
    expect(DB::table('marks')->where('state', 'pending')->count())->toBe(4);
    expect(DB::table('assessment_periods')->where('id', $f['sequence1'])->value('status'))->toBe('closed');
});

it('refuses to close a period twice', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();
    app(OpenAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());
    app(CloseAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());
    app(CloseAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());
})->throws(DomainException::class, 'already closed');

it('pulls the entry window shut when the period closes', function () {
    $user = periodUserAs(Role::ExamsOfficer);
    actingAs($user);

    $f = periodFixture();

    app(OpenAssessmentPeriod::class)->handle(
        $f['sequence1'],
        $user->toAuditActor(),
        null,
        '2026-09-01 08:00:00',
        '2099-12-31 23:59:59',
    );

    app(CloseAssessmentPeriod::class)->handle($f['sequence1'], $user->toAuditActor());

    $period = DB::table('assessment_periods')->where('id', $f['sequence1'])->first();

    // Otherwise an entry screen that checks only the window keeps writing to a
    // closed period.
    expect(OpenAssessmentPeriod::entryWindowIsOpen(
        (string) $period?->marks_entry_opens_at,
        (string) $period?->marks_entry_closes_at,
        Carbon::now(BusinessDate::TIMEZONE)->addMinute(),
    ))->toBeFalse();
});
