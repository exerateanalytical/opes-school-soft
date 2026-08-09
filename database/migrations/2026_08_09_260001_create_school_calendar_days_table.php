<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `SchoolCalendarDay` — docs/specs/07-students.md §9.2.
 *
 * Owned by Academics; attendance is its only consumer. Every date inside
 * `[year.starts_on, year.ends_on]` must resolve to exactly one row per
 * section (section-specific rows win over all-sections rows), and a MISSING
 * calendar blocks register creation with a clear message rather than
 * defaulting to "teaching".
 *
 * `school_section_id` is a plain column with a 0 sentinel for all-sections
 * rows, NOT a nullable FK: MySQL treats every NULL in a UNIQUE index as
 * distinct, so a nullable column would let the same (year, date) be entered
 * twice for "all sections" — the exact NULL-in-UNIQUE trap 04-fees calls out
 * and subject_allocations.stream_id already solves the same way. The FK is
 * therefore enforced in the Action (SetCalendarDayType), not the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_calendar_days', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RESTRICT: deleting a year that still has a calendar would strand
            // the registers that were gated on it.
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->date('date');

            $table->enum('day_type', [
                'teaching', 'weekend', 'public_holiday', 'school_holiday',
                'exam', 'staff_day', 'closure',
            ]);

            // 0 = all sections (sentinel, see class header).
            $table->unsignedBigInteger('school_section_id')->default(0);

            // "Youth Day", "Fête de la Jeunesse" — why the day is what it is.
            $table->string('label', 120)->nullable();
            $table->string('label_fr', 120)->nullable();

            $table->timestamps();

            $table->unique(
                ['academic_year_id', 'date', 'school_section_id'],
                'uq_calendar_year_date_section',
            );

            // ResolveCalendarDay's probe: one date, both the section row and
            // the 0 row, cheapest as a covering scan on (date, section).
            $table->index(['date', 'school_section_id'], 'idx_calendar_date_section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_calendar_days');
    }
};
