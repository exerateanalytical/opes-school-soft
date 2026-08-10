<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\Homework\GradeAssignmentSubmission;
use App\Modules\Assessment\Actions\Homework\SetAssignment;
use App\Modules\Assessment\Actions\Homework\SubmitAssignment;
use App\Modules\Assessment\Models\AssignmentSubmission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Homework/assignments is scoped to the LOGIN user via
 * subject_allocation_teachers, the precedent Attendance's
 * OpenAttendanceRegister already established - not to a staff_members row,
 * which most demo teacher logins in this product do not even have.
 * SuperAdmin bypasses the teaching-assignment check via assessment.configure,
 * the same way OpenAttendanceRegister's leadership bypass works.
 */

function assignmentActor(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return $user;
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function assignmentFixture(): array
{
    $classGroupId = (int) DB::table('class_groups')->value('id');
    $subjectId = (int) DB::table('subjects')->value('id');
    $enrollmentId = (int) DB::table('enrollments')->value('id');

    if ($classGroupId === 0 || $subjectId === 0 || $enrollmentId === 0) {
        test()->markTestSkipped('Needs class_group/subject/enrollment fixtures from another slice.');
    }

    return [$classGroupId, $subjectId, $enrollmentId];
}

it('sets an assignment with a due date on or after the assigned date', function (): void {
    $actor = assignmentActor();
    [$classGroupId, $subjectId] = assignmentFixture();

    $assignment = app(SetAssignment::class)->handle(
        $classGroupId, $subjectId, (int) $actor->getKey(), 'Chapter 3 exercises', '2026-08-10', '2026-08-17', null, 20.0,
    );

    expect($assignment->title)->toBe('Chapter 3 exercises')
        ->and((float) $assignment->max_score)->toBe(20.0);
});

it('refuses a due date before the assigned date', function (): void {
    $actor = assignmentActor();
    [$classGroupId, $subjectId] = assignmentFixture();

    expect(fn () => app(SetAssignment::class)->handle(
        $classGroupId, $subjectId, (int) $actor->getKey(), 'Bad dates', '2026-08-17', '2026-08-10',
    ))->toThrow(DomainException::class);
});

it('refuses a teacher who is not allocated to teach the subject', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $teacher = User::factory()->create();
    $teacher->assignRole(Role::Teacher->value);
    Auth::setUser($teacher);

    [$classGroupId, $subjectId] = assignmentFixture();

    // A plain teacher role holds marks.enter but has no
    // subject_allocation_teachers row, so the teaching-assignment check
    // must refuse rather than let RBAC alone decide.
    expect(fn () => app(SetAssignment::class)->handle(
        $classGroupId, $subjectId, (int) $teacher->getKey(), 'Unauthorised', '2026-08-10', '2026-08-17',
    ))->toThrow(DomainException::class);
});

it('marks a submission late when submitted after the due date', function (): void {
    $actor = assignmentActor();
    [$classGroupId, $subjectId, $enrollmentId] = assignmentFixture();

    $assignment = app(SetAssignment::class)->handle(
        $classGroupId, $subjectId, (int) $actor->getKey(), 'Past-due homework', '2020-01-01', '2020-01-02',
    );

    $submission = app(SubmitAssignment::class)->handle((int) $assignment->getKey(), $enrollmentId, 'Done.');

    expect($submission->is_late)->toBeTrue();
});

it('upserts a resubmission rather than creating a second row', function (): void {
    $actor = assignmentActor();
    [$classGroupId, $subjectId, $enrollmentId] = assignmentFixture();

    $assignment = app(SetAssignment::class)->handle(
        $classGroupId, $subjectId, (int) $actor->getKey(), 'Essay', now()->toDateString(), now()->addWeek()->toDateString(),
    );

    app(SubmitAssignment::class)->handle((int) $assignment->getKey(), $enrollmentId, 'First draft.');
    app(SubmitAssignment::class)->handle((int) $assignment->getKey(), $enrollmentId, 'Revised draft.');

    expect(AssignmentSubmission::query()->count())->toBe(1)
        ->and(AssignmentSubmission::query()->value('submission_note'))->toBe('Revised draft.');
});

it('refuses a resubmission once the submission has been graded', function (): void {
    $actor = assignmentActor();
    [$classGroupId, $subjectId, $enrollmentId] = assignmentFixture();

    $assignment = app(SetAssignment::class)->handle(
        $classGroupId, $subjectId, (int) $actor->getKey(), 'Essay', now()->toDateString(), now()->addWeek()->toDateString(), null, 20.0,
    );

    $submission = app(SubmitAssignment::class)->handle((int) $assignment->getKey(), $enrollmentId, 'Draft.');
    app(GradeAssignmentSubmission::class)->handle((int) $submission->getKey(), 18.0, (int) $actor->getKey());

    expect(fn () => app(SubmitAssignment::class)->handle((int) $assignment->getKey(), $enrollmentId, 'Too late.'))
        ->toThrow(DomainException::class);
});

it('refuses a score above the assignment\'s max_score', function (): void {
    $actor = assignmentActor();
    [$classGroupId, $subjectId, $enrollmentId] = assignmentFixture();

    $assignment = app(SetAssignment::class)->handle(
        $classGroupId, $subjectId, (int) $actor->getKey(), 'Quiz', now()->toDateString(), now()->addWeek()->toDateString(), null, 10.0,
    );

    $submission = app(SubmitAssignment::class)->handle((int) $assignment->getKey(), $enrollmentId, 'Done.');

    expect(fn () => app(GradeAssignmentSubmission::class)->handle((int) $submission->getKey(), 15.0, (int) $actor->getKey()))
        ->toThrow(DomainException::class);
});
