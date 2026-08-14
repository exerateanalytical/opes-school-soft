<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap #3 of docs/specs/2026-08-12-module-gap-analysis.md - the alumni
 * relationship AFTER graduation. `PromotionOutcome` already reaches
 * `graduated` (students module); this table is what exists on the other
 * side of that door.
 *
 * `student_id` is a plain FK read via DB::table - the Alumni module never
 * imports a Students model (00-core 6.2, ModuleBoundaryTest).
 *
 * `final_class_group_name` is denormalised AT CONVERSION, the same
 * label-at-time discipline the reporting module applies to bulletins: a
 * class group renamed in 2032 must not silently rewrite what the 2028
 * cohort's diploma class was called.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alumnus_records', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // One alumnus per student, for all time - ConvertGraduateToAlumnus
            // refuses a double conversion, and this UNIQUE is the structural
            // backstop. RESTRICT: students are never deleted (00-core 10.5).
            $table->foreignId('student_id')
                ->constrained('students')->restrictOnDelete();
            $table->unique('student_id', 'uq_alumnus_records_student');

            // The calendar year the completing enrollment closed in
            // (enrollments.left_on). "This year's cohort" and the year filter
            // both key on it.
            $table->unsignedSmallInteger('graduation_year');

            // Label-at-time (see class docblock). Frozen copies, never joined
            // back to the live rows.
            $table->string('final_class_group_name', 100);
            $table->string('academic_year_name', 60);

            // Where life took them - nullable because the school rarely knows
            // on conversion day; UpdateAlumnusContact fills these in later.
            $table->string('current_occupation', 120)->nullable();
            $table->string('current_organisation', 160)->nullable();
            $table->string('contact_email', 160)->nullable();
            $table->string('contact_phone', 24)->nullable();

            // One-way (MarkDeceased): an alumni office must never "undo" a
            // death because a row was filtered wrong.
            $table->boolean('is_deceased')->default(false);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The list screen's default axes: year filter and cohort KPI.
            $table->index('graduation_year', 'ix_alumnus_records_year');
            $table->index('current_occupation', 'ix_alumnus_records_occupation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alumnus_records');
    }
};
