<?php

declare(strict_types=1);

use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Operations\Actions\Rollover\ArchiveLeaversStep;
use App\Modules\Operations\Actions\Rollover\PromoteStudentsStep;
use App\Modules\Operations\Actions\Rollover\ReassignTeachersStep;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Students\Actions\RecordPromotionDecision;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\Student;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/P7F3RolloverTestHelpers.php';

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Students\Actions\RecordPromotionDecision - the step-6 decision door
// ---------------------------------------------------------------------------

it('records one decision per enrollment and updates it on re-record', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);
    $group = p7f3Group($years['from'], $level, 'Form 1 A');

    $enrollment = p7f3Enroll(Student::factory()->create(), $years['from'], $group, '2030-09-05');

    $first = app(RecordPromotionDecision::class)->handle(
        $enrollment->id, PromotionDecision::DECISION_PROMOTED, 'group:Form 2 A', $operator->toAuditActor(),
    );

    // The operator changes their mind: same row, new decision - unique by
    // constraint, revisable until step 6 consumes it.
    $second = app(RecordPromotionDecision::class)->handle(
        $enrollment->id, PromotionDecision::DECISION_REPEAT, 'group:Form 1 A', $operator->toAuditActor(),
    );

    expect($second->id)->toBe($first->id)
        ->and(PromotionDecision::query()->count())->toBe(1)
        ->and($second->decision)->toBe(PromotionDecision::DECISION_REPEAT)
        ->and($second->target_class_group_key)->toBe('group:Form 1 A');
});

it('validates the decision shape: targets for movers, none for leavers, graduation only from an exit class', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $section = SchoolSection::factory()->create();
    $junior = p7f3Level($section);
    $exit = p7f3Level($section, examClass: true);

    $juniorEnrollment = p7f3Enroll(Student::factory()->create(), $years['from'], p7f3Group($years['from'], $junior, 'Form 1 A'), '2030-09-05');
    $exitEnrollment = p7f3Enroll(Student::factory()->create(), $years['from'], p7f3Group($years['from'], $exit, 'Form 5 A'), '2030-09-05');

    $actor = $operator->toAuditActor();
    $record = app(RecordPromotionDecision::class);

    // A promoted student must be told where they go.
    expect(fn () => $record->handle($juniorEnrollment->id, PromotionDecision::DECISION_PROMOTED, null, $actor))
        ->toThrow(ValidationException::class)
        // A graduate goes nowhere - a target is a contradiction.
        ->and(fn () => $record->handle($exitEnrollment->id, PromotionDecision::DECISION_GRADUATED, 'group:Form 6 A', $actor))
        ->toThrow(ValidationException::class)
        // Graduation from a non-exam class is not a thing (07-students 3.2).
        ->and(fn () => $record->handle($juniorEnrollment->id, PromotionDecision::DECISION_GRADUATED, null, $actor))
        ->toThrow(ValidationException::class)
        // Not a decision at all.
        ->and(fn () => $record->handle($juniorEnrollment->id, 'expelled', null, $actor))
        ->toThrow(ValidationException::class);

    // The legitimate graduate records cleanly.
    $decision = $record->handle($exitEnrollment->id, PromotionDecision::DECISION_GRADUATED, null, $actor);
    expect($decision->decision)->toBe(PromotionDecision::DECISION_GRADUATED);
});

// ---------------------------------------------------------------------------
// PromoteStudentsStep - §6.2 step 6
// ---------------------------------------------------------------------------

it('refuses to promote while any class group has undecided students, and names the group', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);
    $group = p7f3Group($years['from'], $level, 'Form 1 A');
    p7f3Group($years['to'], $level, 'Form 2 A');

    $decided = p7f3Enroll(Student::factory()->create(), $years['from'], $group, '2030-09-05');
    p7f3Enroll(Student::factory()->create(), $years['from'], $group, '2030-09-05'); // undecided

    app(RecordPromotionDecision::class)->handle(
        $decided->id, PromotionDecision::DECISION_PROMOTED, 'group:Form 2 A', $operator->toAuditActor(),
    );

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::PromoteStudents->value, $operator);

    expect(fn () => app(PromoteStudentsStep::class)->handle($run->id, $operator->toAuditActor()))
        ->toThrow(DomainException::class, 'Form 1 A (1 undecided)');

    // The refusal wrote NOTHING: no new-year enrollment, step unmoved.
    expect(Enrollment::query()->where('academic_year_id', $years['to'])->count())->toBe(0)
        ->and($run->fresh()?->current_step)->toBe(RolloverStep::PromoteStudents->value);
});

it('promotes and repeats per decision through the Students door, records artifacts and advances the run', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $section = SchoolSection::factory()->create();
    $junior = p7f3Level($section);
    $middle = p7f3Level($section);
    $exit = p7f3Level($section, examClass: true);

    $fromJunior = p7f3Group($years['from'], $junior, 'Form 1 A');
    $fromExit = p7f3Group($years['from'], $exit, 'Form 5 A');
    $toMiddle = p7f3Group($years['to'], $middle, 'Form 2 A');
    $toJunior = p7f3Group($years['to'], $junior, 'Form 1 A');

    $promoted = Student::factory()->create();
    $repeater = Student::factory()->create();
    $graduate = Student::factory()->create();

    $promotedEnrollment = p7f3Enroll($promoted, $years['from'], $fromJunior, '2030-09-05');
    $repeaterEnrollment = p7f3Enroll($repeater, $years['from'], $fromJunior, '2030-09-05');
    $graduateEnrollment = p7f3Enroll($graduate, $years['from'], $fromExit, '2030-09-05');

    $actor = $operator->toAuditActor();
    $record = app(RecordPromotionDecision::class);
    $record->handle($promotedEnrollment->id, PromotionDecision::DECISION_PROMOTED, 'group:Form 2 A', $actor);
    // The repeat decision resolves by LEVEL - the level has exactly one
    // new-year group, so 'level:<id>' is unambiguous.
    $record->handle($repeaterEnrollment->id, PromotionDecision::DECISION_REPEAT, 'level:'.$junior->id, $actor);
    $record->handle($graduateEnrollment->id, PromotionDecision::DECISION_GRADUATED, null, $actor);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::PromoteStudents->value, $operator);

    $summary = app(PromoteStudentsStep::class)->handle($run->id, $actor);

    expect($summary)->toBe(['promoted' => 1, 'repeated' => 1, 'skipped' => 0, 'leavers' => 1]);

    /** @var Enrollment $promotedNew */
    $promotedNew = Enrollment::query()
        ->where('student_id', $promoted->id)->where('academic_year_id', $years['to'])->firstOrFail();
    /** @var Enrollment $repeaterNew */
    $repeaterNew = Enrollment::query()
        ->where('student_id', $repeater->id)->where('academic_year_id', $years['to'])->firstOrFail();

    // §6.2: is_repeat comes from the DECISION, not a person flag.
    expect($promotedNew->is_repeat)->toBeFalse()
        ->and($repeaterNew->is_repeat)->toBeTrue()
        ->and($promotedNew->enrolled_on->toDateString())->toBe('2031-09-01')
        // The segment lands in the resolved destination group.
        ->and($promotedNew->openSegment()->first()?->class_group_id)->toBe($toMiddle->id)
        ->and($repeaterNew->openSegment()->first()?->class_group_id)->toBe($toJunior->id)
        // The graduate gets NO new enrollment - step 8 owns their exit.
        ->and(Enrollment::query()->where('student_id', $graduate->id)->where('academic_year_id', $years['to'])->exists())->toBeFalse();

    // Undo ledger: both created enrollments and their initial segments are on
    // record for this run and step.
    $artifacts = RolloverArtifact::query()->where('rollover_run_id', $run->id)->get();
    expect($artifacts->where('entity_type', 'enrollments')->pluck('entity_id')->sort()->values()->all())
        ->toBe([$promotedNew->id, $repeaterNew->id])
        ->and($artifacts->where('entity_type', 'enrollment_segments')->count())->toBe(2)
        ->and($artifacts->every(fn (RolloverArtifact $a): bool => $a->step === RolloverStep::PromoteStudents->value))->toBeTrue();

    expect($run->fresh()?->current_step)->toBe(RolloverStep::CarryBalances->value);
});

it('skips a student already enrolled in the new year instead of double-enrolling (§6.3 resume idempotency)', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);
    $fromGroup = p7f3Group($years['from'], $level, 'Form 1 A');
    $toGroup = p7f3Group($years['to'], $level, 'Form 1 B');

    $student = Student::factory()->create();
    $enrollment = p7f3Enroll($student, $years['from'], $fromGroup, '2030-09-05');

    app(RecordPromotionDecision::class)->handle(
        $enrollment->id, PromotionDecision::DECISION_PROMOTED, 'group:Form 1 B', $operator->toAuditActor(),
    );

    // The registrar (or a killed first execution) already enrolled them.
    p7f3Enroll($student, $years['to'], $toGroup, '2031-09-01');

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::PromoteStudents->value, $operator);
    $summary = app(PromoteStudentsStep::class)->handle($run->id, $operator->toAuditActor());

    expect($summary['skipped'])->toBe(1)
        ->and($summary['promoted'])->toBe(0)
        ->and(Enrollment::query()->where('student_id', $student->id)->where('academic_year_id', $years['to'])->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// ArchiveLeaversStep - §6.2 step 8
// ---------------------------------------------------------------------------

it('archives graduates and leavers through the Students doors, never deleting, and lists unsettled balances', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $calendar = p7f3Calendar();
    $section = SchoolSection::factory()->create();
    $exit = p7f3Level($section, examClass: true);
    $fromExit = p7f3Group($years['from'], $exit, 'Form 5 A');

    $graduate = Student::factory()->create();
    $leaver = Student::factory()->create();

    $graduateEnrollment = p7f3Enroll($graduate, $years['from'], $fromExit, '2030-09-05');
    $leaverEnrollment = p7f3Enroll($leaver, $years['from'], $fromExit, '2030-09-05');

    $actor = $operator->toAuditActor();
    app(RecordPromotionDecision::class)->handle($graduateEnrollment->id, PromotionDecision::DECISION_GRADUATED, null, $actor);
    app(RecordPromotionDecision::class)->handle($leaverEnrollment->id, PromotionDecision::DECISION_WITHDRAWN, null, $actor);

    // The graduate leaves owing 50 000 on an outgoing-year invoice.
    p7f3IssueInvoice($graduate, $graduateEnrollment, $years['from'], $calendar['fiscal_year_id'], 50_000, $operator);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::ArchiveLeavers->value, $operator);
    $summary = app(ArchiveLeaversStep::class)->handle($run->id, $actor);

    expect($summary['graduated'])->toBe(1)
        ->and($summary['withdrawn'])->toBe(1)
        // "A graduate with an unsettled balance is listed, not silently archived."
        ->and($summary['unsettled'])->toHaveCount(1)
        ->and($summary['unsettled'][0]['student_id'])->toBe($graduate->id)
        ->and($summary['unsettled'][0]['outstanding'])->toBe(50_000);

    /** @var Enrollment $graduated */
    $graduated = $graduateEnrollment->fresh();
    /** @var Enrollment $withdrawn */
    $withdrawn = $leaverEnrollment->fresh();

    expect($graduated->status)->toBe(EnrollmentStatus::Completed)
        ->and($graduated->left_on?->toDateString())->toBe('2031-08-31')
        ->and($withdrawn->status)->toBe(EnrollmentStatus::Withdrawn)
        // Archived, never deleted - and the roll segment is closed (5.2).
        ->and(Enrollment::query()->whereKey($graduateEnrollment->id)->exists())->toBeTrue()
        ->and(DB::table('enrollment_segments')->where('enrollment_id', $graduateEnrollment->id)->whereNull('ends_on')->exists())->toBeFalse()
        // 07-students 3.2 rule 4: completed on an exit level derives `graduated`.
        ->and((string) DB::table('students')->where('id', $graduate->id)->value('status'))->toBe('graduated');

    expect($run->fresh()?->current_step)->toBe(RolloverStep::ReassignTeachers->value);
});

// ---------------------------------------------------------------------------
// ReassignTeachersStep - §6.2 step 9
// ---------------------------------------------------------------------------

it('carries still-active teachers onto the new year allocations and records the artifacts', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);

    $subjectId = p7f3Subject('MATH-P7F3');
    $fromAllocation = p7f3Allocation($years['from'], $level->id, $subjectId);
    $toAllocation = p7f3Allocation($years['to'], $level->id, $subjectId);

    $teacher = User::factory()->create();
    p7f3AssignTeacher($fromAllocation, $teacher);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::ReassignTeachers->value, $operator);
    $summary = app(ReassignTeachersStep::class)->handle($run->id, [], $operator->toAuditActor());

    expect($summary['assigned'])->toBe(1)
        ->and($summary['inherited'])->toBe(1)
        ->and($summary['departed_flagged'])->toBe([])
        ->and(DB::table('subject_allocation_teachers')
            ->where('subject_allocation_id', $toAllocation)
            ->where('user_id', $teacher->id)
            ->exists())->toBeTrue()
        ->and(RolloverArtifact::query()
            ->where('rollover_run_id', $run->id)
            ->where('entity_type', 'subject_allocation_teachers')
            ->count())->toBe(1);

    expect($run->fresh()?->current_step)->toBe(RolloverStep::FlipActiveYear->value);
});

it('flags departed staff, refuses to leave a required allocation unassigned, then accepts an explicit override', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();
    $section = SchoolSection::factory()->create();
    $level = p7f3Level($section);

    $subjectId = p7f3Subject('PHYS-P7F3');
    $fromAllocation = p7f3Allocation($years['from'], $level->id, $subjectId);
    $toAllocation = p7f3Allocation($years['to'], $level->id, $subjectId);

    // The only teacher of the subject has left the school.
    $departed = User::factory()->create();
    DB::table('users')->where('id', $departed->id)->update(['status' => 'suspended']);
    p7f3AssignTeacher($fromAllocation, $departed);

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::ReassignTeachers->value, $operator);
    $step = app(ReassignTeachersStep::class);

    // Refusal names the allocation - and nothing was written.
    expect(fn () => $step->handle($run->id, [], $operator->toAuditActor()))
        ->toThrow(DomainException::class, 'PHYS-P7F3');

    expect(DB::table('subject_allocation_teachers')->where('subject_allocation_id', $toAllocation)->exists())->toBeFalse()
        ->and($run->fresh()?->current_step)->toBe(RolloverStep::ReassignTeachers->value);

    // The reassignment grid names a replacement; the run proceeds.
    $replacement = User::factory()->create();
    $summary = $step->handle($run->id, [$toAllocation => [$replacement->id]], $operator->toAuditActor());

    expect($summary['assigned'])->toBe(1)
        ->and($summary['overridden'])->toBe(1)
        ->and(DB::table('subject_allocation_teachers')
            ->where('subject_allocation_id', $toAllocation)
            ->where('user_id', $replacement->id)
            ->exists())->toBeTrue()
        ->and($run->fresh()?->current_step)->toBe(RolloverStep::FlipActiveYear->value);
});

it('refuses a step out of order - the wizard executes strictly in sequence', function () {
    $operator = p7f3Operator();
    $years = p7f3Years();

    $run = p7f3RunAt($years['from'], $years['to'], RolloverStep::CarryBalances->value, $operator);

    expect(fn () => app(PromoteStudentsStep::class)->handle($run->id, $operator->toAuditActor()))
        ->toThrow(DomainException::class, 'strictly in order');
});
