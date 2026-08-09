<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/plans/phase-10.md §3 row 8 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). A sick-bay visit. Keyed on student_id
        // (like student_medical_records: the clinical history belongs to the
        // CHILD and persists across years) with the enrollment recorded when
        // known, so "visits this year" reports stay answerable after rollover.
        Schema::create('medical_consultations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            // Nullable: a consultation can be recorded for a child between
            // enrollments (holiday programme, admission medical).
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();

            $table->timestamp('visited_at');

            // 00-core 9.5: health data about a minor - clinical narrative is
            // encrypted at the model ('encrypted' cast, the exact
            // StudentMedicalRecord.detail pattern). TEXT because the
            // ciphertext envelope is far wider than the plaintext.
            $table->text('presenting_complaint');
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();

            // Same scale as Students' MedicalSeverity so triage language is
            // consistent school-wide.
            $table->enum('severity', ['low', 'moderate', 'high'])->default('low');

            $table->enum('outcome', [
                'returned_to_class', 'sent_home', 'referred', 'admitted',
            ])->default('returned_to_class');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The dashboard's "Today's Visits" and the per-child history read.
            $table->index('visited_at', 'idx_medical_consultations_visited_at');
            $table->index(['student_id', 'visited_at'], 'idx_medical_consultations_student');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_consultations');
    }
};
