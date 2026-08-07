<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 07-students 3.4: every FK on the enrollment graph is RESTRICT.
            // Students and enrollments are never deleted (00-core 10.5);
            // pseudonymisation is the erasure path.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            // 07-students 4.1: the LEVEL is a property of the year and lives
            // here; the class GROUP lives on EnrollmentSegment, because a
            // mid-year transfer must not create a second Enrollment (C2).
            $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();
            $table->foreignId('stream_id')->nullable()->constrained('streams')->restrictOnDelete();

            // Denormalised from the class level and frozen at enrollment: a
            // later re-parenting of the level must not silently rewrite
            // history for cohorts already enrolled under it.
            $table->foreignId('school_section_id')->constrained('school_sections')->restrictOnDelete();

            $table->enum('status', [
                'pending',
                'active',
                'suspended',
                'withdrawn',
                'transferred_out',
                'completed',
                'cancelled',
            ])->default('pending');

            // C4 (07-students 4.1): repeating is a property of the YEAR, not of
            // the person. `Student.is_repeater` deliberately does not exist.
            $table->boolean('is_repeat')->default(false);

            // FeeItem.applies_to new|returning reads THIS, never a person-level
            // flag (04-fees).
            $table->enum('enrollment_type', ['new', 'returning', 'transfer_in', 're_admission'])
                ->default('new');

            $table->date('enrolled_on');
            $table->date('left_on')->nullable();

            // 01-assessment: a January joiner is not ranked on Term 1.
            $table->unsignedBigInteger('assessable_from_period_id')->nullable();
            $table->unsignedSmallInteger('min_periods_assessed')->nullable();

            $table->enum('boarding_status', ['day', 'boarder'])->default('day');
            $table->string('previous_school_name', 160)->nullable();

            // Set by WithdrawalSettlement (04-fees); the Transfer Certificate
            // gates on it (10-documents).
            $table->boolean('financial_clearance')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // ---------------------------------------------------------------
            // C1 - THE uniqueness constraint (07-students 4.3), verbatim.
            //
            // MySQL 8 has no partial indexes, so the 00-core 10.1 generated
            // column pattern stands in for one: `active_year_key` carries the
            // academic year while the enrollment is LIVE (pending, active or
            // suspended) and NULL once it reaches a terminal status. MySQL
            // permits unlimited duplicate NULLs in a UNIQUE index, so any
            // number of terminal enrollments coexist while a second LIVE one
            // for the same (student, year) violates uq_enrollment_active_year.
            //
            // The spec then adds a plain composite and is explicit about which
            // of the two is load-bearing:
            //
            //   "uq_enrollment_student_year is the stronger of the two and is
            //    the one that matters: one Enrollment per student per year,
            //    full stop, including terminal ones. The generated column is
            //    retained because it is the pattern 00-core mandates and
            //    because it survives any future decision to permit a
            //    cancelled-then-recreated row (which would drop the second
            //    index and rely on the first)."
            //
            // So: withdrawing does NOT free the year for a second row today.
            // Both indexes exist precisely so that decision can be reversed by
            // dropping one index rather than by rewriting the shape.
            //
            // The brief's UNIQUE(student_id, academic_year_id, class_group_id)
            // is deliberately NOT implemented: class_group_id moved to
            // EnrollmentSegment, so including it here would reintroduce the
            // multi-row-per-year shape the constraint exists to prevent. The
            // equivalent guarantee is uq_segment_open in 5.2.
            // ---------------------------------------------------------------
            $table->unsignedBigInteger('active_year_key')
                ->nullable()
                ->storedAs(
                    "CASE WHEN `status` IN ('pending','active','suspended') "
                    .'THEN `academic_year_id` END'
                );

            $table->unique(['student_id', 'active_year_key'], 'uq_enrollment_active_year');
            $table->unique(['student_id', 'academic_year_id'], 'uq_enrollment_student_year');

            // 07-students 13.
            $table->index(
                ['academic_year_id', 'class_level_id', 'status'],
                'idx_enrollments_year_level_status',
            );
            $table->index(['class_level_id', 'stream_id'], 'idx_enrollments_level_stream');
        });

        // 07-students 4.2 invariant 2. Invariant 1 (enrolled_on inside the
        // academic year) is cross-table and not expressible as a MySQL 8 CHECK
        // without a trigger, so it is enforced in EnrollStudent and by the
        // nightly integrity job, exactly as the spec directs.
        DB::statement(
            'ALTER TABLE enrollments ADD CONSTRAINT chk_enrollments_left_on '
            .'CHECK (left_on IS NULL OR left_on >= enrolled_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
