<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `AttendanceSummary` — docs/specs/07-students.md §9.8. Per-period rollups
 * are PERSISTED, not recomputed: the report-card snapshot freezes these
 * numbers at publication and a published card must reproduce byte-identically
 * — recomputing from live registers cannot guarantee that.
 *
 * Rebuilt by RebuildAttendanceSummary (queued on register submit/amend and
 * on justification changes). `sessions_expected = 0` means the rate is NULL
 * — rendered "—", never 0% (C5) — so the columns store the counts, and the
 * rate is derived by the §9.6 formula at read time, one formula, one place.
 *
 * Summaries are never archived (§9.8) even when their records eventually
 * move to attendance_records_archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_summaries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();

            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('sessions_expected')->default(0);
            $table->unsignedSmallInteger('sessions_present')->default(0);
            $table->unsignedSmallInteger('sessions_absent')->default(0);
            $table->unsignedSmallInteger('sessions_excused')->default(0);
            $table->unsignedSmallInteger('sessions_late')->default(0);
            $table->unsignedSmallInteger('sessions_suspended')->default(0);

            // §9.7: Σ lesson_duration_minutes / 60 over per-lesson records
            // with status ∈ (absent, sick, excused), split by is_justified.
            // Daily attendance cannot yield hours; these stay 0 for it.
            $table->decimal('hours_absent_justified', 6, 2)->default(0);
            $table->decimal('hours_absent_unjustified', 6, 2)->default(0);

            // Count of status='late' records (retards on the MINESEC card).
            $table->unsignedSmallInteger('retards')->default(0);

            $table->timestamp('computed_at');

            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'assessment_period_id'],
                'uq_summary_enrollment_period',
            );

            // The report card and promotion run read a whole period at once.
            $table->index('assessment_period_id', 'idx_summaries_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_summaries');
    }
};
