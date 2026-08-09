<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `AttendanceRecord` — docs/specs/07-students.md §9.4, the EXCEPTION rows.
 *
 * Storage rule: `present` rows are never written. The header's counts are
 * the authority (`present_count = expected_count − Σ(non-present)`), which
 * keeps the table at ~5–8% of the naive full-matrix size while remaining
 * sound, because the denominator now comes from the header rather than from
 * row absence. That is the entire C5 fix.
 *
 * The register FK is the ONLY cascade in the students spec, and only because
 * a record is meaningless without its header and both are written in one
 * transaction. Registers themselves are never deleted once submitted — the
 * AttendanceRegister model observer enforces it.
 *
 * Keys on `enrollment_id`, never `student_id` (C3): the roster on any date
 * resolves through enrollment_segments, and a mid-year transfer must keep
 * every historical record attached to the one surviving enrollment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('attendance_register_id')
                ->constrained('attendance_registers')
                ->cascadeOnDelete();

            $table->foreignId('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();

            $table->enum('status', [
                'present', 'absent', 'late', 'excused', 'sick', 'suspended',
            ]);

            // §9.7 (C6): three orthogonal concepts. `excused` in `status` is
            // an absence the school authorised IN ADVANCE; `is_justified` is
            // a note/certificate accepted AFTER the fact. The MINESEC
            // justified/unjustified hours split reads `is_justified` across
            // both `absent` and `excused` — never collapse them.
            $table->boolean('is_justified')->default(false);

            $table->enum('justification_type', [
                'medical', 'family', 'administrative', 'transport', 'other',
            ])->nullable();

            $table->foreignId('justification_document_id')
                ->nullable()
                ->constrained('student_documents')
                ->restrictOnDelete();

            $table->foreignId('justified_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('justified_at')->nullable();

            // Retards.
            $table->unsignedSmallInteger('minutes_late')->nullable();

            $table->string('remark', 160)->nullable();

            $table->foreignId('recorded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['attendance_register_id', 'enrollment_id'],
                'uq_record',
            );

            // §9.8's three indexes, verbatim.
            $table->index(
                ['enrollment_id', 'attendance_register_id'],
                'idx_records_enrollment_register',
            );
            $table->index(
                ['attendance_register_id', 'status'],
                'idx_records_register_status',
            );
            // The justified/unjustified rollup.
            $table->index(['enrollment_id', 'status'], 'idx_records_enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
