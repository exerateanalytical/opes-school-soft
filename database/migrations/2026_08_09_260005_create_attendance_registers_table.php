<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `AttendanceRegister` — docs/specs/07-students.md §9.3, the header row that
 * IS the C5 fix: the attendance denominator is the count of registers
 * actually taken, never a global school-days constant. No register, no
 * denominator, no attendance percentage.
 *
 * `timetable_slot_id` is NOT NULL DEFAULT 0 (sentinel) inside the UNIQUE key
 * on purpose: MySQL treats every NULL in a UNIQUE index as distinct, so a
 * nullable slot column would let the same daily register (class, date,
 * session) be opened twice. The sentinel makes daily (slot 0) and per-lesson
 * (real slot id) share one index with real collision semantics — the same
 * trap subject_allocations.stream_id and school_calendar_days.
 * school_section_id already solve. Because 0 is not a timetable_slots row,
 * the FK is enforced in OpenAttendanceRegister, not the schema.
 *
 * `taken_by` references `users`, not `staff_members`: the authenticated
 * principal in this codebase is a User (mark entry's
 * subject_allocation_teachers.user_id sets the precedent), and staff_members
 * carries no NOT NULL user linkage to pivot through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_registers', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RESTRICT: deleting a class group that has taken registers would
            // destroy the denominator history §9 exists to protect.
            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            // Denormalised from the class group for partitioning and the
            // year-scoped rate/coverage queries (§9.8 index note).
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            // business-date at creation (§9.3).
            $table->date('date');

            $table->enum('session', ['morning', 'afternoon', 'full_day'])
                ->default('full_day');

            // Sentinel 0 = "no slot / daily mode" — see class header.
            $table->unsignedBigInteger('timetable_slot_id')->default(0);

            // Per-lesson mode; denormalised from the slot so the register
            // survives a later timetable edit.
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subjects')
                ->restrictOnDelete();

            $table->enum('mode', ['daily', 'per_lesson']);

            // Roster size resolved at open (§9.5), FROZEN on the header —
            // a student enrolled three days later does not retroactively
            // change last Tuesday's denominator.
            $table->unsignedSmallInteger('expected_count');

            // Maintained in the same transaction as the exception rows.
            // present_count = expected_count − Σ(exception rows), so it
            // counts the silent (unmarked) students; `late` students appear
            // in late_count and are re-added as present by the §9.6 formula.
            $table->unsignedSmallInteger('present_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('excused_count')->default(0);

            $table->enum('status', ['open', 'submitted', 'amended'])
                ->default('open');

            $table->foreignId('taken_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('taken_at');
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('amended_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('amended_at')->nullable();
            $table->string('amendment_reason', 255)->nullable();

            // Per-lesson only; the source of heures d'absence (§9.7).
            $table->unsignedSmallInteger('lesson_duration_minutes')->nullable();

            $table->timestamps();

            $table->unique(
                ['class_group_id', 'date', 'session', 'timetable_slot_id'],
                'uq_register_daily',
            );

            // §9.8's three indexes, verbatim.
            $table->index(['class_group_id', 'date'], 'idx_registers_group_date');
            $table->index(['academic_year_id', 'date'], 'idx_registers_year_date');
            $table->index(['taken_by', 'date'], 'idx_registers_taker_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_registers');
    }
};
