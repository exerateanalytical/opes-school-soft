<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `TimetableSlot` — docs/specs/09-ui.md §8.6.
 *
 * One cell of the weekly grid: class group × day × period → subject, teacher,
 * room. "Conflict detection is an invariant, not a warning" — the three
 * conflict rules are UNIQUE constraints, NOT application checks, so two
 * concurrent inserts cannot both win no matter how they interleave:
 *
 *   1. slot_taken         UNIQUE(class_group_id, day, period, year)
 *   2. teacher_busy       UNIQUE(staff_member_id, day, period, year)
 *   3. room_double_booked UNIQUE(room_id, day, period, year)
 *
 * `room_id` stays NULLABLE inside its UNIQUE key on purpose: MySQL admits
 * unlimited NULL duplicates, which is exactly right here — any number of
 * room-less slots may coexist; only a CLAIMED room can be double-booked.
 *
 * `day_of_week` is ISO: 1 = Monday … 6 = Saturday (the six-day week is the
 * standard grid; whether Saturday teaching is universal is 09-ui open
 * question 2, so the CHECK admits it and the calendar decides per school).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            // Denormalised from the class group so the three conflict keys can
            // be year-scoped without a join.
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->unsignedTinyInteger('day_of_week');

            $table->foreignId('timetable_period_id')
                ->constrained('timetable_periods')
                ->restrictOnDelete();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete();

            $table->foreignId('staff_member_id')
                ->constrained('staff_members')
                ->restrictOnDelete();

            $table->foreignId('room_id')
                ->nullable()
                ->constrained('rooms')
                ->restrictOnDelete();

            // The week navigator's effective range (09-ui §8.6). v1 keeps one
            // live grid per year: replacing a cell is remove + assign, so the
            // UNIQUE keys never have to disambiguate overlapping ranges.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['class_group_id', 'day_of_week', 'timetable_period_id', 'academic_year_id'],
                'uq_slot_class',
            );

            $table->unique(
                ['staff_member_id', 'day_of_week', 'timetable_period_id', 'academic_year_id'],
                'uq_slot_staff',
            );

            $table->unique(
                ['room_id', 'day_of_week', 'timetable_period_id', 'academic_year_id'],
                'uq_slot_room',
            );

            // The Teacher and Room tabs open with "everything this teacher /
            // room has this year".
            $table->index(['staff_member_id', 'academic_year_id'], 'idx_slots_staff_year');
            $table->index(['room_id', 'academic_year_id'], 'idx_slots_room_year');
        });

        DB::statement(
            'ALTER TABLE timetable_slots '
            .'ADD CONSTRAINT chk_timetable_slots_day CHECK (day_of_week BETWEEN 1 AND 6)'
        );

        DB::statement(
            'ALTER TABLE timetable_slots '
            .'ADD CONSTRAINT chk_timetable_slots_effective '
            .'CHECK (effective_to IS NULL OR effective_to >= effective_from)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};
