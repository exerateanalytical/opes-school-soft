<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `DisciplineCase` — docs/specs/07-students.md §3.4 C3: keyed on BOTH
 * `student_id NOT NULL` and `enrollment_id NULL`.
 *
 * The double key is the whole point. The sanction ladder needs cross-year
 * student history ("third offence, ever"), which only `student_id` can
 * answer; the promotion criterion and the report-card conduct block need a
 * year filter ("cases this year"), which only `enrollment_id` can answer.
 * `enrollment_id` is nullable solely for cases raised outside an enrolled
 * period (holiday incident on school premises); it is filled whenever a live
 * enrollment exists at `occurred_on`.
 *
 * `is_positive` carries 10-documents' DISC-ACTION checkbox: positive
 * behaviour entries are first-class rows in the same log, excluded from the
 * promotion counts by the read door, never a separate afterthought table.
 *
 * `visibility` implements 07-students §8 row 20: `internal` cases (e.g.
 * involving another named minor) are invisible to every guardian; only
 * `guardian` cases surface narrative detail on the portal.
 *
 * All FKs RESTRICT: deleting a student, category or user with casework
 * attached would amputate the ladder history the next sanction relies on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discipline_cases', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('student_id')
                ->constrained('students')
                ->restrictOnDelete();

            $table->foreignId('enrollment_id')
                ->nullable()
                ->constrained('enrollments')
                ->restrictOnDelete();

            $table->foreignId('discipline_category_id')
                ->constrained('discipline_categories')
                ->restrictOnDelete();

            $table->date('occurred_on');

            $table->foreignId('reported_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('description');

            $table->enum('status', [
                'open', 'under_investigation', 'resolved', 'dismissed',
            ])->default('open');

            $table->enum('visibility', ['internal', 'guardian'])
                ->default('internal');

            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('resolution_note', 500)->nullable();

            $table->boolean('is_positive')->default(false);

            $table->timestamps();

            // The ladder's probe: this student's history, newest first.
            $table->index(['student_id', 'occurred_on'], 'idx_discipline_cases_student_date');

            // The promotion criterion / conduct block probe: this year's
            // open-or-resolved cases per enrollment.
            $table->index(['enrollment_id', 'status'], 'idx_discipline_cases_enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discipline_cases');
    }
};
