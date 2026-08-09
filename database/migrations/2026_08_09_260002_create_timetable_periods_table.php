<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `TimetablePeriod` — docs/plans/phase-08.md F1; docs/specs/09-ui.md §8.6.
 *
 * The named rows of the timetable grid: "Period 1" 07:30–08:20, "BREAK"
 * 09:10–09:30, "LUNCH BREAK" 11:10–12:00 (the mockup's exact shape). Periods
 * are PER SECTION because primary and secondary run different bell schedules
 * (09-ui open question 3 — durations are school-entered, never seeded).
 *
 * `duration_minutes` is stored, not derived on read: it is the source of
 * *heures d'absence* for per-lesson attendance (07-students §9.7), and a
 * later edit to the bell schedule must not silently rewrite the hours already
 * frozen onto submitted registers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_periods', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('school_section_id')
                ->constrained('school_sections')
                ->restrictOnDelete();

            $table->string('name', 60);
            $table->string('name_fr', 60)->nullable();

            // Grid order. UNIQUE per section so "Period 3" cannot appear twice
            // in one section's grid.
            $table->unsignedSmallInteger('sequence');

            $table->time('starts_at');
            $table->time('ends_at');

            // BREAK / LUNCH rows render full-width and take no slots.
            $table->boolean('is_break')->default(false);

            $table->unsignedSmallInteger('duration_minutes');

            $table->timestamps();

            $table->unique(['school_section_id', 'sequence'], 'uq_periods_section_sequence');
            $table->index(['school_section_id', 'starts_at'], 'idx_periods_section_start');
        });

        // A zero-length period would make every per-lesson absence worth zero
        // heures d'absence; the floor lives in the database.
        DB::statement(
            'ALTER TABLE timetable_periods '
            .'ADD CONSTRAINT chk_timetable_periods_range CHECK (ends_at > starts_at)'
        );

        DB::statement(
            'ALTER TABLE timetable_periods '
            .'ADD CONSTRAINT chk_timetable_periods_duration CHECK (duration_minutes > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_periods');
    }
};
