<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ExamSeat` — docs/specs/01-assessment.md 16.1. The table is named
 * `exam_seatings` (and the model `ExamSeating`) because that is the name the
 * migration was assigned; it holds exactly the columns 16.1 specifies.
 *
 * ── The invariant this table cannot express in SQL ────────────────────────
 *
 *   "seats assigned per room <= Room.capacity, enforced under FOR UPDATE on
 *    the room row"
 *
 * A per-room COUNT is not a column, so this is a locked read plus an INSERT
 * in GenerateSeating. What IS declarative here is the pair of UNIQUE keys —
 * a student sits once per paper, and a seat holds one student — and those two
 * are the constraints that a concurrent generation would otherwise violate
 * silently.
 *
 * Capacity is counted across every exam that shares the room and OVERLAPS in
 * time, not merely across this one exam: two class groups sitting different
 * papers in the same hall at the same hour is normal practice, and it is the
 * combined body count that has to fit in the chairs. GenerateSeating says so
 * again at the point where it counts.
 *
 * `room_id` is stored on the seat rather than read from the exam because a
 * large cohort spills across halls: 96 candidates in 2 rooms of 60 is one
 * exam and two rooms, and the seat is the only row that knows which.
 *
 * Cascades on the exam for the reason given in the invigilators migration;
 * RESTRICTs on enrollment and room, because those are records of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_seatings', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('exam_id')
                ->constrained('exams')
                ->cascadeOnDelete();

            // The enrollment, not the student: 12.6 makes one Enrollment own
            // the student's whole year, so a candidate who transfers class in
            // January still resolves to one row here.
            $table->foreignId('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();

            $table->foreignId('room_id')
                ->constrained('rooms')
                ->restrictOnDelete();

            // Printed on the desk card and on the attendance sheet: "B-014",
            // "R3-C7". Free text because seat naming is a school convention,
            // not something the product should impose.
            $table->string('seat_label', 20);

            $table->timestamps();

            $table->unique(['exam_id', 'enrollment_id'], 'uq_exam_seatings_exam_enrollment');
            $table->unique(['exam_id', 'room_id', 'seat_label'], 'uq_exam_seatings_exam_room_label');

            // "How many chairs are already spoken for in this room" — the
            // capacity probe.
            $table->index(['room_id'], 'idx_exam_seatings_room');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_seatings');
    }
};
