<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Homework/assignments: a teacher sets one for a class group and subject,
 * students submit, the teacher grades. Not in docs/specs - the spec set is
 * compliance-first (SYSCOHADA, DGI, MINESEC) and silent on this; the mockup
 * catalogue (10-documents §20) lists "Homework Log" and "Class Test" as
 * MOCKUP-ONLY documents, with no schema behind them anywhere.
 *
 * `assignments` scopes to `class_group_id` + `subject_id` rather than to a
 * `timetable_slot_id`: a teacher setting homework does so for "9A Maths",
 * not for "period 3 on Tuesday", and tying it to a slot would break the
 * moment the timetable is revised mid-term.
 *
 * `set_by_user_id` references `users`, NOT `staff_members` - following the
 * precedent Attendance\Actions\OpenAttendanceRegister already established
 * (its own docblock names it explicitly): teaching assignment lives in
 * `subject_allocation_teachers`, which is keyed on the LOGIN user, not on a
 * staff HR record. Most demo teacher logins in this product are plain
 * `users` rows carrying the `teacher` role with no `staff_members` row at
 * all, so scoping through staff_members would silently exclude them.
 *
 * `assignment_submissions` is per (assignment, student). `submitted_at`
 * NULL means not yet submitted; grading fields are separate from submission
 * fields so "submitted, ungraded" and "not submitted" stay distinguishable
 * at a glance rather than both reading as a blank score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('class_group_id')->constrained('class_groups')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('set_by_user_id')->constrained('users')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('instructions')->nullable();

            $table->date('assigned_on');
            $table->date('due_on');

            // NULL = ungraded work, e.g. a reading log. A max_score present
            // is what makes AssignmentSubmission.score meaningful rather
            // than an arbitrary number with no ceiling.
            $table->decimal('max_score', 6, 2)->nullable();

            $table->boolean('is_published')->default(true);

            $table->timestamps();

            $table->index(['class_group_id', 'subject_id', 'due_on'], 'ix_assignments_class_subject_due');
        });

        DB::statement(
            'ALTER TABLE assignments ADD CONSTRAINT ck_assignments_due_after_assigned '
            .'CHECK (due_on >= assigned_on)'
        );

        Schema::create('assignment_submissions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            $table->text('submission_note')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);

            $table->decimal('score', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by_user_id')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('graded_at')->nullable();

            $table->timestamps();

            // One submission row per student per assignment - a resubmission
            // updates this row rather than creating a second one, so a
            // teacher grading always sees the CURRENT attempt.
            $table->unique(['assignment_id', 'enrollment_id'], 'uq_submissions_assignment_enrollment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
    }
};
