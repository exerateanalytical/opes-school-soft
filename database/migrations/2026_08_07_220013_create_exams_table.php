<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `Exam` — docs/specs/01-assessment.md 16.1.
 *
 * ── Why this table exists at all ──────────────────────────────────────────
 *
 * An `AssessmentPeriod` is a WINDOW OF THE CALENDAR: "Séquence 3 runs from
 * 12 January to 6 February". An `Exam` is a SITTING: "Form 1A sits Maths on
 * 3 February at 08:00 for 120 minutes in Hall B, invigilated by Mme Ngo and
 * M. Tabi, seated 1..42, marked out of 20". v1 had only the first, and so had
 * nowhere to record a date, a room, an invigilator or a seat — which is why
 * four tabs of the Examinations mockup had zero backing data.
 *
 * One period therefore contains MANY exams: one per (subject allocation ×
 * class group × exam type). That product is exactly the UNIQUE key below.
 *
 * ── Two columns deliberately carry no foreign key ─────────────────────────
 *
 * `exam_type_id` and `mark_scheme_id` reference tables from 16.1 that are not
 * part of this migration's ownership. The established precedent in this
 * codebase for a forward reference is a plain unsigned BIGINT with the
 * constraint wired by the owning migration later (see
 * subject_allocations.subject_group_id and assessment_periods.framework_id,
 * both of which say so in the same words). `exam_type_id` stays NOT NULL
 * because 16.1 makes it part of the UNIQUE key and a nullable member of a
 * MySQL UNIQUE index defeats the constraint outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Forward reference — see the class header. NOT NULL because it is
            // part of the UNIQUE key.
            $table->unsignedBigInteger('exam_type_id');

            // 6.1's rule 3: no Mark may exist for a non-leaf period, and an
            // exam produces marks — so the leaf-ness of this period is checked
            // in ScheduleExam, which is the only place that can see the tree.
            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            $table->foreignId('subject_allocation_id')
                ->constrained('subject_allocations')
                ->restrictOnDelete();

            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            $table->date('scheduled_on');

            // TIME, not DATETIME: the sitting's clock time. The date lives in
            // its own column so "every exam on 3 February" — the invigilator
            // overlap query and the day's timetable — is one indexed scan.
            $table->time('starts_at');

            $table->unsignedSmallInteger('duration_minutes');

            // Nullable: a paper can be scheduled before the room allocation is
            // settled. Seating cannot be generated until it is set, which
            // GenerateSeating refuses to do rather than guessing a room.
            $table->foreignId('room_id')
                ->nullable()
                ->constrained('rooms')
                ->restrictOnDelete();

            // Forward reference — see the class header.
            $table->unsignedBigInteger('mark_scheme_id')->nullable();

            // The paper's own maximum. NOT the framework maximum: 6.3's
            // precedence chain re-scales at pipeline stage 4, so a paper
            // marked out of 100 stays out of 100 here and is normalised once,
            // in one place.
            $table->decimal('max_score', 6, 3);

            $table->enum('status', [
                'planned', 'scheduled', 'in_progress', 'marked', 'cancelled',
            ])->default('planned');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Optimistic lock, 00-core 10.6.
            $table->integer('version')->default(1);

            $table->timestamps();

            $table->unique(
                ['assessment_period_id', 'subject_allocation_id', 'class_group_id', 'exam_type_id'],
                'uq_exams_period_allocation_group_type',
            );

            // The invigilator-overlap probe (16.1's INVARIANT, T24) and the
            // day timetable both open with "which exams sit on this date".
            $table->index(['scheduled_on', 'starts_at'], 'idx_exams_day');

            // "Which exams are booked into this room" — the seat-capacity
            // probe of GenerateSeating.
            $table->index(['room_id', 'scheduled_on'], 'idx_exams_room_day');
        });

        // Blueprint has no check() helper. A zero-length sitting would make
        // the half-open interval [starts_at, starts_at + duration) empty, and
        // an empty interval overlaps nothing — the invigilator invariant would
        // silently stop being enforceable. So the floor lives in the database.
        DB::statement(
            'ALTER TABLE exams ADD CONSTRAINT chk_exams_duration CHECK (duration_minutes > 0)'
        );

        DB::statement(
            'ALTER TABLE exams ADD CONSTRAINT chk_exams_max_score CHECK (max_score > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
