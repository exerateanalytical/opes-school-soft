<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ExamInvigilator` — docs/specs/01-assessment.md 16.1, invariant 17.
 *
 * ── The invariant this table cannot express in SQL ────────────────────────
 *
 *   "a staff member cannot invigilate two exams whose
 *    [starts_at, starts_at + duration) intervals overlap on the same date"
 *
 * MySQL has no exclusion constraint and no range type, so this is enforced in
 * AssignInvigilators under `SELECT ... FOR UPDATE` on the staff rows. The
 * UNIQUE below is the part that CAN be declarative — it stops the same person
 * being listed twice on one paper — and the temporal part is a locked read
 * plus an INSERT inside one transaction.
 *
 * The interval is HALF-OPEN on purpose. Two papers that merely touch
 * (08:00–10:00 and 10:00–12:00) are not a conflict: the invigilator walks out
 * of one hall and into the next, which is how a Cameroonian exam day is
 * actually timetabled. A closed interval would reject the entire normal
 * morning schedule.
 *
 * ── Cascade, uniquely in this module ──────────────────────────────────────
 *
 * 00-core 10.5 forbids cascading into `Mark`, and this codebase is otherwise
 * RESTRICT throughout. An invigilator row has no meaning without its exam —
 * it is not a record OF anything, it is a line ON the exam — so it cascades.
 * An exam that has happened is `cancelled`, never deleted, so in practice the
 * cascade fires only when a mistakenly-created draft exam is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_invigilators', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('exam_id')
                ->constrained('exams')
                ->cascadeOnDelete();

            $table->foreignId('staff_id')
                ->constrained('staff_members')
                ->restrictOnDelete();

            // 16.1. `chief` is the person who signs the attendance sheet and
            // the incident report; `assistant` walks the room.
            $table->enum('role', ['chief', 'assistant'])->default('assistant');

            $table->foreignId('assigned_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['exam_id', 'staff_id'], 'uq_exam_invigilators_exam_staff');

            // The overlap probe reads "every exam this person already
            // invigilates", so staff_id leads the index.
            $table->index('staff_id', 'idx_exam_invigilators_staff');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_invigilators');
    }
};
