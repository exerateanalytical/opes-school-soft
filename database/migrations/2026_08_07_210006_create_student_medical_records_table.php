<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/07-students.md 8.2, keyed on student_id per 3.4: a
        // chronic condition persists across years.
        Schema::create('student_medical_records', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            $table->enum('condition_type', [
                'allergy', 'chronic_condition', 'medication',
                'disability', 'immunisation', 'incident',
            ]);

            // The only field a class teacher may see, and only where
            // is_emergency_relevant = 1. v1 surfaced the whole record to every
            // class teacher, which put twelve staff members in front of every
            // child's full medical picture.
            $table->string('summary', 200);

            // 00-core 9.5 encrypted. TEXT because the ciphertext envelope is
            // far wider than the plaintext; never readable by a class teacher.
            $table->text('detail')->nullable();

            $table->boolean('is_emergency_relevant')->default(false);
            $table->enum('severity', ['low', 'moderate', 'high'])->default('low');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // Drives the "emergency-relevant only" read the class-teacher view
            // performs, so it does not filter in PHP over the full set.
            $table->index(
                ['student_id', 'is_emergency_relevant'],
                'student_medical_records_student_emergency_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_medical_records');
    }
};
